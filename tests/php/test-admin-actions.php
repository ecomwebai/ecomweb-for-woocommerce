<?php
/**
 * Admin-post action and store picker view tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Admin_Page;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Admin_Actions
 */
class Test_Admin_Actions extends WP_UnitTestCase {

	const BASE = '/stores/s1/integrations/woocommerce/connections';

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
	 * Set up: signed-in administrator.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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
		unset( $_POST['store_ref'], $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Prepares a request.
	 *
	 * @param string $action Action.
	 * @param array  $post   Extra POST fields.
	 * @param bool   $nonce  Whether to send a valid nonce.
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
	 * Runs a handler and returns the redirect target.
	 *
	 * @param string $handler Method name.
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
	 * Saves a connection.
	 */
	private function connect() {
		$this->fake->on(
			'POST',
			self::BASE,
			200,
			array(
				'connection_id' => 'c1',
				'images_mode'   => 'upload',
				'store'         => array(
					'id'            => 's1',
					'ref'           => 's1',
					'name'          => 'Shop One',
					'currency'      => 'VND',
					'dashboard_url' => 'https://ecomweb.app/store/s1',
				),
			)
		);
		Plugin::instance()->connection()->register( 's1' );
	}

	/**
	 * The four actions.
	 *
	 * @return array
	 */
	public function actions(): array {
		return array(
			'select'  => array( 'ecomweb_select_store', 'handle_select_store' ),
			'change'  => array( 'ecomweb_change_store', 'handle_change_store' ),
			'disc'    => array( 'ecomweb_disconnect', 'handle_disconnect' ),
			'recheck' => array( 'ecomweb_recheck_images', 'handle_recheck_images' ),
		);
	}

	/**
	 * The handlers are hooked.
	 */
	public function test_actions_are_hooked() {
		$this->page->register();
		$this->assertNotFalse( has_action( 'admin_post_ecomweb_select_store' ) );
		$this->assertNotFalse( has_action( 'admin_post_ecomweb_change_store' ) );
		$this->assertNotFalse( has_action( 'admin_post_ecomweb_disconnect' ) );
		$this->assertNotFalse( has_action( 'admin_post_ecomweb_recheck_images' ) );
	}

	/**
	 * Without a nonce nothing happens.
	 *
	 * @dataProvider actions
	 * @param string $action  Action.
	 * @param string $handler Handler.
	 */
	public function test_each_admin_post_action_requires_nonce( string $action, string $handler ) {
		$this->connect();
		$before = $this->fake->calls();
		$this->request( $action, array( 'store_ref' => 's1' ), false );

		try {
			$this->page->$handler();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->fake->calls() );
			$this->assertNotNull( Plugin::instance()->connection()->current() );
			$this->assertTrue( Plugin::instance()->auth()->is_signed_in() );
		}
	}

	/**
	 * Without the capability nothing happens, even with a valid nonce.
	 *
	 * @dataProvider actions
	 * @param string $action  Action.
	 * @param string $handler Handler.
	 */
	public function test_each_admin_post_action_requires_capability( string $action, string $handler ) {
		$this->connect();
		$before = $this->fake->calls();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->request( $action, array( 'store_ref' => 's1' ) );

		try {
			$this->page->$handler();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->fake->calls() );
			$this->assertNotNull( Plugin::instance()->connection()->current() );
		}
	}

	/**
	 * Selecting a store registers it.
	 */
	public function test_select_store_registers_and_redirects() {
		$this->fake->on(
			'POST',
			self::BASE,
			200,
			array(
				'connection_id' => 'c1',
				'images_mode'   => 'fetch',
				'store'         => array(
					'id'            => 's1',
					'ref'           => 's1',
					'name'          => 'Shop One',
					'currency'      => 'VND',
					'dashboard_url' => 'x',
				),
			)
		);
		$this->request( 'ecomweb_select_store', array( 'store_ref' => ' s1 ' ) );

		$this->assertSame( Admin_Page::url(), $this->redirect_of( 'handle_select_store' ) );
		$this->assertSame( 1, $this->fake->count( self::BASE ) );
		$this->assertSame( 'c1', get_option( 'ecomweb_connection' )['connection_id'] );
	}

	/**
	 * No store chosen: nothing is sent.
	 */
	public function test_select_store_without_choice_sends_nothing() {
		$this->request( 'ecomweb_select_store', array() );

		$this->assertSame( Admin_Page::url( array( 'notice' => 'pick_store' ) ), $this->redirect_of( 'handle_select_store' ) );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * A store the merchant can't use is refused.
	 */
	public function test_select_store_403_shows_no_access() {
		$this->fake->on( 'POST', self::BASE, 403, array( 'message' => 'Forbidden' ) );
		$this->request( 'ecomweb_select_store', array( 'store_ref' => 's1' ) );

		$this->assertSame( Admin_Page::url( array( 'notice' => 'no_access' ) ), $this->redirect_of( 'handle_select_store' ) );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
	}

	/**
	 * An API error travels in the stored notice and shows once.
	 */
	public function test_select_store_api_error_is_shown_once() {
		$this->fake->on( 'POST', self::BASE, 500, array( 'message' => 'Database on fire' ) );
		$this->fake->on( 'GET', '/stores', 200, array() );
		$this->request( 'ecomweb_select_store', array( 'store_ref' => 's1' ) );
		$this->redirect_of( 'handle_select_store' );

		$this->assertStringContainsString( 'Database on fire', $this->render() );
		$this->assertStringNotContainsString( 'Database on fire', $this->render() );
	}

	/**
	 * Change store drops the connection only, and the picker warns.
	 */
	public function test_change_store_forgets_connection_only() {
		$this->connect();
		update_post_meta( self::factory()->post->create(), '_ecomweb_product_id', 'p1' );
		$this->request( 'ecomweb_change_store' );
		$calls = count( $this->fake->calls() );

		$this->assertSame( Admin_Page::url( array( 'notice' => 'changed_store' ) ), $this->redirect_of( 'handle_change_store' ) );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
		$this->assertTrue( Plugin::instance()->auth()->is_signed_in() );
		$this->assertSame( $calls, count( $this->fake->calls() ) );
		$this->assertSame( 0, $this->fake->count( '/auth/logout' ) );

		$this->fake->on(
			'GET',
			'/stores',
			200,
			array(
				array(
					'ref'  => 's1',
					'name' => 'A',
					'role' => 'owner',
				),
			)
		);
		$_GET['notice'] = 'changed_store';
		$html           = $this->render();
		unset( $_GET['notice'] );
		$this->assertStringContainsString( 'Products copied to your previous store will be copied again to the new one.', $html );
	}

	/**
	 * Disconnect signs out, forgets the connection, and keeps the site UUID and product meta.
	 */
	public function test_disconnect_logs_out_and_keeps_meta() {
		$this->connect();
		$uuid    = Plugin::site_uuid();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_ecomweb_product_id', 'p1' );
		$this->fake->on( 'POST', '/auth/logout', 204 );
		$this->request( 'ecomweb_disconnect' );

		$this->assertSame( Admin_Page::url(), $this->redirect_of( 'handle_disconnect' ) );
		$this->assertSame( 1, $this->fake->count( '/auth/logout' ) );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
		$this->assertSame( $uuid, get_option( 'ecomweb_site_uuid' ) );
		$this->assertSame( 'p1', get_post_meta( $post_id, '_ecomweb_product_id', true ) );
	}

	/**
	 * Recheck saves the mode and tells the merchant.
	 */
	public function test_recheck_images_redirects_with_result() {
		$this->connect();
		$this->fake->on( 'POST', self::BASE . '/c1/probe', 200, array( 'images_mode' => 'fetch' ) );
		$this->request( 'ecomweb_recheck_images' );

		$this->assertSame( Admin_Page::url( array( 'notice' => 'images_fetch' ) ), $this->redirect_of( 'handle_recheck_images' ) );
		$this->assertSame( 'fetch', get_option( 'ecomweb_connection' )['images_mode'] );
	}

	/**
	 * Recheck throttled.
	 */
	public function test_recheck_images_throttled_notice() {
		$this->connect();
		$this->fake->on( 'POST', self::BASE . '/c1/probe', 429, array( 'message' => 'x' ) );
		$this->request( 'ecomweb_recheck_images' );

		$this->assertSame( Admin_Page::url( array( 'notice' => 'throttled' ) ), $this->redirect_of( 'handle_recheck_images' ) );
	}

	/**
	 * Staff stores show disabled, with the reason; the others are selectable.
	 */
	public function test_pick_store_view_disables_staff_store() {
		$this->fake->on(
			'GET',
			'/stores',
			200,
			array(
				array(
					'ref'  => 's1',
					'name' => 'A',
					'role' => 'owner',
				),
				array(
					'ref'  => 's3',
					'name' => 'C',
					'role' => 'staff',
				),
			)
		);
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<input type="radio"[^>]*value="s3" disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input type="radio"[^>]*value="s1"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'Only the store owner or an admin can copy products', $html );
		$this->assertStringContainsString( 'Use this store', $html );
		$this->assertStringContainsString( 'Signed in as merchant@example.com', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
		$this->assertStringNotContainsString( 'Products copied to your previous store', $html );
	}

	/**
	 * No stores: a sign-up link and Refresh.
	 */
	public function test_no_store_view_links_to_signup() {
		$this->fake->on( 'GET', '/stores', 200, array() );
		$html = $this->render();

		$this->assertStringContainsString( "You don't have an ecomweb store yet.", str_replace( '&#039;', "'", $html ) );
		$this->assertStringContainsString( 'href="https://ecomweb.app/store/signup" target="_blank"', $html );
		$this->assertStringContainsString( 'Create a store', $html );
		$this->assertStringContainsString( 'Refresh', $html );
	}

	/**
	 * A failed store list shows the error and Refresh.
	 */
	public function test_store_list_error_view() {
		$this->fake->on( 'GET', '/stores', 500, array( 'message' => 'Boom' ) );
		$html = $this->render();

		$this->assertStringContainsString( 'Boom', $html );
		$this->assertStringContainsString( 'Refresh', $html );
	}

	/**
	 * The notice left by a lost-access re-register shows once, with the store name.
	 */
	public function test_lost_access_notice_shown_once() {
		Admin_Page::flash( 'lost_access', array( 'Shop One' ) );
		$this->fake->on( 'GET', '/stores', 200, array() );

		$this->assertStringContainsString( 'You no longer have access to Shop One in ecomweb.', $this->render() );
		$this->assertStringNotContainsString( 'You no longer have access', $this->render() );
	}
}
