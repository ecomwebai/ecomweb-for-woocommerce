<?php
/**
 * Confirm screen and copy-start handler tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Admin_Page;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Selection;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Confirm_Screen
 */
class Test_Confirm_Screen extends WP_UnitTestCase {

	const PATH = '/stores/s1/integrations/woocommerce/preflight';

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Page under test.
	 *
	 * @var Admin_Page
	 */
	private $page;

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Set up: signed-in administrator with a connection.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 3600,
				'email'              => 'merchant@example.com',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => Auth::normalise_home( home_url() ),
			)
		);
		$this->fake = Fake_Api::start();
		$this->fake->on(
			'POST',
			'/stores/s1/integrations/woocommerce/connections',
			200,
			array(
				'connection_id' => 'c1',
				'images_mode'   => 'upload',
				'store'         => array(
					'id'       => 's1',
					'ref'      => 's1',
					'name'     => 'Shop One',
					'currency' => 'VND',
				),
			)
		);
		Plugin::instance()->connection()->register( 's1' );
		$this->page = new Admin_Page( Plugin::instance()->auth(), Plugin::instance()->connection() );
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['view'] );
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Creates products.
	 *
	 * @param int $count How many.
	 * @return int[]
	 */
	private function products( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Tee ' . $i );
			$product->set_regular_price( '10' );
			$ids[] = $product->save();
		}
		return $ids;
	}

	/**
	 * Queues a preflight answer.
	 *
	 * @param array $states ID => state.
	 * @param array $extra  Overrides of plan, storage, currency, upgrade_url.
	 */
	private function answer( array $states, array $extra = array() ): void {
		$products = array();
		foreach ( $states as $id => $state ) {
			$products[] = array(
				'wc_product_id'      => $id,
				'state'              => $state,
				'ecomweb_product_id' => 'new' === $state ? null : 'E' . $id,
			);
		}
		$this->fake->on(
			'POST',
			self::PATH,
			200,
			array_merge(
				array(
					'products'    => $products,
					'plan'        => array(
						'limit'     => -1,
						'current'   => 0,
						'new_count' => count( $states ),
						'fits'      => count( $states ),
					),
					'storage'     => null,
					'currency'    => array(
						'store'       => 'VND',
						'woocommerce' => 'VND',
						'matches'     => true,
					),
					'upgrade_url' => null,
				),
				$extra
			)
		);
	}

	/**
	 * Renders the confirm screen.
	 *
	 * @return string
	 */
	private function render(): string {
		$_GET['view'] = 'confirm';
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	/**
	 * Prepares a POST.
	 *
	 * @param string $action Action.
	 * @param array  $post   Fields.
	 * @param bool   $nonce  Send a valid nonce.
	 */
	private function request( string $action, array $post = array(), bool $nonce = true ) {
		$_POST = $post;
		if ( $nonce ) {
			$token                = wp_create_nonce( $action );
			$_POST['_wpnonce']    = $token;
			$_REQUEST['_wpnonce'] = $token;
		}
	}

	/**
	 * Runs a handler and returns the redirect.
	 *
	 * @param string $handler Method.
	 * @return string
	 */
	private function redirect_of( string $handler ): string {
		try {
			$this->page->$handler();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'No redirect.' );
	}

	/**
	 * Over the cap: copy the first X and the upgrade link.
	 */
	public function test_over_cap_shows_copy_first_x_and_upgrade_link() {
		$ids = $this->products( 5 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'plan'        => array(
					'limit'     => 20,
					'current'   => 18,
					'new_count' => 5,
					'fits'      => 2,
				),
				'upgrade_url' => 'https://ecomweb.app/store/s1/settings/subscription',
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'Copy the first 2', $html );
		$this->assertStringContainsString( 'href="https://ecomweb.app/store/s1/settings/subscription"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'Your plan allows 20 products and the store has 18, so 2 of these 5 fit.', $html );
		$this->assertStringContainsString( 'Products deleted in ecomweb are left out.', $html );
		$this->assertStringContainsString( 'Upgrade your plan', $html );
	}

	/**
	 * A plan with no room left (a free store at its cap): the cap message and the upgrade link,
	 * and no Start copying button that could only be refused.
	 */
	public function test_no_room_shows_cap_message_and_upgrade_link_without_start_button() {
		$ids = $this->products( 3 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array( $ids[0] => 'new', $ids[1] => 'new', $ids[2] => 'copied' ),
			array(
				'plan'        => array(
					'limit'     => 20,
					'current'   => 20,
					'new_count' => 2,
					'fits'      => 0,
				),
				'upgrade_url' => 'https://ecomweb.app/store/s1/settings/subscription',
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'Your plan allows 20 products and the store has 20, so 0 of these 2 fit.', $html );
		$this->assertStringContainsString( 'Your plan has no room for more products. Upgrade your plan, or delete products in ecomweb, then copy again.', $html );
		$this->assertStringContainsString( 'href="https://ecomweb.app/store/s1/settings/subscription"', $html );
		$this->assertStringContainsString( 'Upgrade your plan', $html );
		$this->assertStringContainsString( 'Cancel', $html );
		$this->assertStringNotContainsString( 'Start copying', $html );
		$this->assertStringNotContainsString( 'Copy the first', $html );
		$this->assertStringNotContainsString( 'value="ecomweb_start_copy"', $html );
		$this->assertStringNotContainsString( 'already in ecomweb.</', $html );
	}

	/**
	 * Starting with no room left is refused with the plan-cap message, not "already in ecomweb".
	 */
	public function test_start_with_no_room_refuses_with_cap_message() {
		$ids = $this->products( 2 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'plan' => array(
					'limit'     => 20,
					'current'   => 20,
					'new_count' => 2,
					'fits'      => 0,
				),
			)
		);
		$this->request( 'ecomweb_start_copy', array( 'limit_to_fits' => '1' ) );
		$this->redirect_of( 'handle_start_copy' );

		$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
		$this->assertSame( array( 'Your plan has no room for more products. Upgrade your plan, or delete products in ecomweb, then copy again.' ), get_transient( 'ecomweb_notice_' . $this->admin )['args'] );
	}

	/**
	 * Only http(s) upgrade links are rendered.
	 *
	 * @dataProvider unsafe_urls
	 * @param string $url URL from the API.
	 */
	public function test_upgrade_link_limited_to_http( string $url ) {
		$ids = $this->products( 3 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'plan'        => array(
					'limit'     => 10,
					'current'   => 9,
					'new_count' => 3,
					'fits'      => 1,
				),
				'upgrade_url' => $url,
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'Copy the first 1', $html );
		$this->assertStringNotContainsString( 'Upgrade your plan', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'mailto:', $html );
	}

	/**
	 * URLs that must not become links.
	 *
	 * @return array
	 */
	public function unsafe_urls(): array {
		return array(
			'javascript' => array( 'javascript:alert(1)' ),
			'mailto'     => array( 'mailto:a@example.com' ),
		);
	}

	/**
	 * A currency mismatch has no start button.
	 */
	public function test_currency_mismatch_has_no_start_button() {
		$ids = $this->products( 1 );
		Selection::save( $this->admin, $ids, true, 2 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'currency' => array(
					'store'       => 'VND',
					'woocommerce' => 'USD',
					'matches'     => false,
				),
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'ecomweb doesn&#039;t convert prices', $html );
		$this->assertStringContainsString( 'Your ecomweb store uses VND and WooCommerce uses USD.', $html );
		$this->assertStringNotContainsString( 'Start copying', $html );
		$this->assertStringContainsString( '2 products can&#039;t be copied (grouped or external).', $html );
		$this->assertStringNotContainsString( 'Products deleted in ecomweb are left out.', $html );
	}

	/**
	 * The storage warning shows the numbers.
	 */
	public function test_storage_warning() {
		$ids = $this->products( 1 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'storage' => array(
					'limit_mb' => 100,
					'used_mb'  => 87,
				),
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'used 87 MB of 100 MB', $html );
		$this->assertStringContainsString( 'Start copying', $html );
		$this->assertStringContainsString( 'Cancel', $html );
	}

	/**
	 * The overwrite checkbox and its confirmation appear when products are already copied.
	 */
	public function test_overwrite_checkboxes_shown_with_copied_products() {
		$ids = $this->products( 2 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array(
				$ids[0] => 'new',
				$ids[1] => 'copied',
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'Overwrite products already copied', $html );
		$this->assertStringContainsString( 'Yes, overwrite 1 products in ecomweb. Their ecomweb status stays as it is.', $html );
	}

	/**
	 * Without a selection the screen says so.
	 */
	public function test_no_selection() {
		$this->assertStringContainsString( 'Choose the products to copy first.', $this->render() );
	}

	/**
	 * Copy all saves a non-explicit selection and opens the confirm screen.
	 */
	public function test_prepare_copy_all_saves_selection() {
		$ids = $this->products( 2 );
		$this->request( 'ecomweb_prepare_copy_all' );
		$location = $this->redirect_of( 'handle_prepare_copy_all' );

		$this->assertStringContainsString( 'view=confirm', $location );
		$selection = Selection::get( $this->admin );
		$this->assertFalse( $selection['explicit'] );
		$this->assertSame( $ids, array_values( array_intersect( $selection['ids'], $ids ) ) );
	}

	/**
	 * Overwrite needs the second checkbox.
	 */
	public function test_start_refuses_overwrite_without_confirmation() {
		$ids = $this->products( 2 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array(
				$ids[0] => 'new',
				$ids[1] => 'copied',
			)
		);
		$this->request(
			'ecomweb_start_copy',
			array(
				'overwrite'         => '1',
				'confirm_overwrite' => '0',
			)
		);
		$location = $this->redirect_of( 'handle_start_copy' );

		$this->assertStringContainsString( 'view=confirm', $location );
		$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
		$this->assertNotNull( Selection::get( $this->admin ) );
		$this->assertSame( 'copy_refused', get_transient( 'ecomweb_notice_' . $this->admin )['key'] );
	}

	/**
	 * Over the cap needs "Copy the first X".
	 */
	public function test_start_refuses_over_cap_without_limit_flag() {
		$ids = $this->products( 3 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'plan' => array(
					'limit'     => 10,
					'current'   => 9,
					'new_count' => 3,
					'fits'      => 1,
				),
			)
		);
		$this->request( 'ecomweb_start_copy' );
		$this->redirect_of( 'handle_start_copy' );

		$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
	}

	/**
	 * A currency mismatch refuses.
	 */
	public function test_start_refuses_currency_mismatch() {
		$ids = $this->products( 1 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer(
			array_fill_keys( $ids, 'new' ),
			array(
				'currency' => array(
					'store'       => 'VND',
					'woocommerce' => 'USD',
					'matches'     => false,
				),
			)
		);
		$this->request( 'ecomweb_start_copy' );
		$this->redirect_of( 'handle_start_copy' );

		$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
	}

	/**
	 * Nothing new: refuses with the message.
	 */
	public function test_start_refuses_empty_queue() {
		$ids = $this->products( 1 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer( array_fill_keys( $ids, 'copied' ) );
		$this->request( 'ecomweb_start_copy' );
		$this->redirect_of( 'handle_start_copy' );

		$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
		$this->assertSame( array( 'Nothing to copy: every selected product is already in ecomweb.' ), get_transient( 'ecomweb_notice_' . $this->admin )['args'] );
	}

	/**
	 * Happy path: starts the copy, clears the selection and goes to the main page.
	 */
	public function test_start_copies_and_clears_selection() {
		$ids = $this->products( 3 );
		Selection::save( $this->admin, $ids, false, 4 );
		$this->answer(
			array(
				$ids[0] => 'new',
				$ids[1] => 'copied',
				$ids[2] => 'deleted_in_ecomweb',
			)
		);
		$this->request( 'ecomweb_start_copy' );
		$location = $this->redirect_of( 'handle_start_copy' );

		$this->assertStringNotContainsString( 'view=confirm', $location );
		$state = get_option( 'ecomweb_copy_state' );
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( array( $ids[0] ), array_map( 'intval', $state['queue'] ) );
		$this->assertFalse( $state['overwrite'] );
		$this->assertNull( Selection::get( $this->admin ) );
	}

	/**
	 * An explicit selection recreates deleted products; overwrite with confirmation adds copied ones.
	 */
	public function test_start_explicit_with_overwrite_confirmed() {
		$ids = $this->products( 3 );
		Selection::save( $this->admin, $ids, true, 0 );
		$this->answer(
			array(
				$ids[0] => 'new',
				$ids[1] => 'copied',
				$ids[2] => 'deleted_in_ecomweb',
			)
		);
		$this->request(
			'ecomweb_start_copy',
			array(
				'overwrite'         => '1',
				'confirm_overwrite' => '1',
			)
		);
		$this->redirect_of( 'handle_start_copy' );

		$state = get_option( 'ecomweb_copy_state' );
		$this->assertSame( $ids, array_map( 'intval', $state['queue'] ) );
		$this->assertTrue( $state['overwrite'] );
	}

	/**
	 * Both actions need the nonce and the capability.
	 */
	public function test_start_requires_nonce_and_capability() {
		$ids = $this->products( 1 );
		Selection::save( $this->admin, $ids, false, 0 );
		$this->answer( array_fill_keys( $ids, 'new' ) );

		foreach ( array(
			'handle_start_copy'       => 'ecomweb_start_copy',
			'handle_prepare_copy_all' => 'ecomweb_prepare_copy_all',
		) as $handler => $action ) { // phpcs:ignore Generic.Arrays.MultipleStatementAlignment
			$this->request( $action, array(), false );
			try {
				$this->page->$handler();
				$this->fail( 'Expected the nonce check to stop the request.' );
			} catch ( WPDieException $e ) {
				$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
			}

			wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
			$this->request( $action );
			try {
				$this->page->$handler();
				$this->fail( 'Expected the capability check to stop the request.' );
			} catch ( WPDieException $e ) {
				$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
			}
			wp_set_current_user( $this->admin );
		}
	}
}
