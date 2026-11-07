<?php
/**
 * Connected page tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Admin_Page;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Copy_State;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Connected_Page
 */
class Test_Connected_Page extends WP_UnitTestCase {

	/**
	 * Page under test.
	 *
	 * @var Admin_Page
	 */
	private $page;

	/**
	 * Set up: signed-in administrator, connected store.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->sign_in( 'm@shop.vn' );
		$this->connect( 'upload', 'https://ecomweb.app/store/s1' );
		$this->page = new Admin_Page( Plugin::instance()->auth(), Plugin::instance()->connection() );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( Copy_State::OPTION );
		parent::tear_down();
	}

	/**
	 * Signs in.
	 *
	 * @param string|null $email Email.
	 */
	private function sign_in( ?string $email ) {
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT-xyz',
				'refresh_token'      => 'RT-xyz',
				'expires_at'         => time() + 3600,
				'email'              => $email,
				'user_id'            => 'u1',
				'api_url'            => Ecomweb\Woo\Config::api_url(),
				'signed_in_home_url' => Auth::normalise_home( home_url() ),
			)
		);
	}

	/**
	 * Saves a connection.
	 *
	 * @param string $mode Images mode.
	 * @param string $url  Dashboard URL.
	 */
	private function connect( string $mode, string $url ) {
		update_option(
			'ecomweb_connection',
			array(
				'connection_id' => 'c1',
				'images_mode'   => $mode,
				'store'         => array(
					'id'            => 's1',
					'ref'           => 's1',
					'name'          => 'Shop One',
					'dashboard_url' => $url,
				),
			),
			false
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	/**
	 * Saves a copy state.
	 *
	 * @param array $over Overrides.
	 */
	private function state( array $over = array() ) {
		Copy_State::put(
			array_merge(
				array(
					'status'      => 'completed',
					'total'       => 45,
					'finished_at' => time(),
					'counts'      => array(
						'created'       => 30,
						'updated'       => 5,
						'skipped'       => 4,
						'failed'        => 3,
						'not_supported' => 3,
					),
					'issues'      => array(),
					'error'       => null,
				),
				$over
			)
		);
	}

	/**
	 * Header.
	 */
	public function test_header_shows_email_and_shared_account_warning() {
		$html = $this->render();
		$this->assertStringContainsString( 'Signed in as m@shop.vn', $html );
		$this->assertStringContainsString( 'Everyone who can manage WooCommerce on this site', $html );
	}

	/**
	 * W33.
	 */
	public function test_null_email_has_no_blank_header() {
		$this->sign_in( null );
		$html = $this->render();
		$this->assertStringContainsString( 'Signed in to ecomweb', $html );
		$this->assertStringNotContainsString( 'Signed in as', $html );
	}

	/**
	 * Store link.
	 */
	public function test_store_link_escaped() {
		$this->connect( 'fetch', 'https://ecomweb.app/s"><script>alert(1)</script>' );
		$html = $this->render();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'Store: ', $html );

		$this->connect( 'fetch', 'javascript:alert(1)' );
		$this->assertStringNotContainsString( 'javascript:', $this->render() );
	}

	/**
	 * Store link is the dashboard URL.
	 */
	public function test_store_link_points_to_dashboard() {
		$this->assertStringContainsString( '<a href="https://ecomweb.app/store/s1" target="_blank" rel="noopener noreferrer">Shop One</a>', $this->render() );
	}

	/**
	 * Images lines and buttons.
	 */
	public function test_images_mode_text_and_buttons() {
		$html = $this->render();
		$this->assertStringContainsString( 'this site uploads them to ecomweb', $html );
		foreach ( array( 'Recheck images', 'Change store', 'Disconnect', 'Copy all products not copied yet' ) as $label ) {
			$this->assertStringContainsString( $label, $html );
		}
		foreach ( array( 'ecomweb_recheck_images', 'ecomweb_change_store', 'ecomweb_disconnect', 'ecomweb_prepare_copy_all' ) as $action ) {
			$this->assertStringContainsString( 'value="' . $action . '"', $html );
		}
		$this->assertStringContainsString( 'data-confirm="Disconnect from ecomweb? Copied products stay in ecomweb."', $html );
		$this->assertStringNotContainsString( 'Overwrite products already copied', $html );

		$this->connect( 'fetch', 'https://ecomweb.app/store/s1' );
		$this->assertStringContainsString( 'Images: ecomweb downloads them from this site.', $this->render() );
	}

	/**
	 * Running.
	 */
	public function test_running_progress_and_disabled_copy_button() {
		$this->state(
			array(
				'status' => 'running',
				'total'  => 45,
				'counts' => array(
					'created' => 12,
					'updated' => 3,
					'skipped' => 3,
					'failed'  => 2,
				),
			)
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Copying 20 of 45', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*value="Copy all products not copied yet"[^>]*disabled/', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*value="Change store"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'data-status="running"', $html );
	}

	/**
	 * Finished.
	 */
	public function test_finished_summary_counts() {
		$this->state();
		$html = $this->render();
		$this->assertStringContainsString( '30 created, 5 updated, 4 skipped (already copied), 3 failed, 3 not supported.', $html );
		$this->assertStringContainsString( 'Last copy finished ', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*value="Copy all products not copied yet"[^>]*disabled/', $html );
	}

	/**
	 * Failed copy shows the translated error.
	 */
	public function test_failed_copy_shows_translated_error() {
		$this->state(
			array(
				'status' => 'failed',
				'error'  => array(
					'code'    => 'JOB_TIMEOUT',
					'message' => 'raw',
				),
			)
		);
		$html = $this->render();
		$this->assertStringContainsString( 'ecomweb took too long to answer.', $html );
		$this->assertStringNotContainsString( '>raw<', $html );
	}

	/**
	 * On-sale list.
	 */
	public function test_on_sale_list_lists_products_with_regular_price() {
		$id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->state(
			array(
				'issues' => array(
					(string) $id => array(
						'name'          => 'Blue shirt',
						'result'        => 'created',
						'url'           => 'https://ecomweb.app/p/1',
						'regular_price' => '25.00',
						'warnings'      => array(
							array(
								'code'    => 'ON_SALE_REGULAR_PRICE_NOT_COPIED',
								'message' => 'x',
							),
							array(
								'code'    => 'ON_SALE_REGULAR_PRICE_NOT_COPIED',
								'message' => 'x',
							),
							array(
								'code'    => 'IMAGE_FAILED',
								'message' => 'x',
							),
						),
						'error'         => null,
					),
				),
			)
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Recreate these sales as promotions in ecomweb', $html );
		$list = substr( $html, strpos( $html, 'ecomweb-on-sale' ) );
		$this->assertStringContainsString( 'On sale in WooCommerce; regular price', $list );
		$this->assertStringContainsString( '25.00', $list );
		$this->assertStringContainsString( 'Blue shirt', $list );
		// Table: the warning shows once, with the other warning and the ecomweb link.
		$table = substr( $html, strpos( $html, 'ecomweb-issues' ), strpos( $html, 'ecomweb-on-sale' ) - strpos( $html, 'ecomweb-issues' ) );
		$this->assertSame( 1, substr_count( $table, 'On sale in WooCommerce' ) );
		$this->assertStringContainsString( 'An image could not be copied.', $table );
		$this->assertStringContainsString( 'href="https://ecomweb.app/p/1"', $table );
		$this->assertStringContainsString( 'post.php?post=' . $id, $table );
	}

	/**
	 * No sale, no list.
	 */
	public function test_no_on_sale_list_without_on_sale_issues() {
		$this->state(
			array(
				'issues' => array(
					'7' => array(
						'name'          => 'X',
						'result'        => 'failed',
						'url'           => null,
						'regular_price' => null,
						'warnings'      => array(),
						'error'         => array(
							'code'    => 'INTERNAL',
							'message' => 'm',
						),
					),
				),
			)
		);
		$html = $this->render();
		$this->assertStringNotContainsString( 'Recreate these sales', $html );
		$this->assertStringContainsString( 'ecomweb couldn&#039;t copy this product.', $html );
	}

	/**
	 * Names are escaped.
	 */
	public function test_issue_names_escaped() {
		$this->state(
			array(
				'issues' => array(
					'7' => array(
						'name'          => '<img src=x onerror=alert(1)>',
						'result'        => 'created',
						'url'           => 'javascript:alert(1)',
						'regular_price' => '1',
						'warnings'      => array(
							array(
								'code'    => 'NEW_CODE',
								'message' => '<b>raw</b>',
							),
						),
						'error'         => null,
					),
				),
			)
		);
		$html = $this->render();
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<b>raw</b>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}
}
