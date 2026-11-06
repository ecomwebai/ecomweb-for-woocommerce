<?php
/**
 * Admin page tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Admin_Page;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Admin_Page
 */
class Test_Admin_Page extends WP_UnitTestCase {

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
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->page = new Admin_Page( new Auth( new Http(), new Session_Store(), new Lock() ) );
	}

	/**
	 * Renders the page and returns its output.
	 *
	 * @return string
	 */
	private function render(): string {
		$level = ob_get_level();
		ob_start();
		try {
			$this->page->render();
			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Saves a session so the merchant counts as signed in.
	 */
	private function sign_in() {
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT-xyz',
				'refresh_token'      => 'RT-xyz',
				'expires_at'         => time() + 3600,
				'email'              => 'merchant@example.com',
				'user_id'            => 'u1',
				'api_url'            => Ecomweb\Woo\Config::api_url(),
				'signed_in_home_url' => Auth::normalise_home( home_url() ),
			)
		);
	}

	/**
	 * The menu sits under WooCommerce.
	 */
	public function test_menu_registered_under_woocommerce_with_capability() {
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin_menu callbacks expect the menu globals to exist.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->page->register();
		do_action( 'admin_menu' );

		$this->assertContains( array( 'ecomweb', 'manage_woocommerce', 'ecomweb', 'ecomweb' ), $submenu['woocommerce'] );
	}

	/**
	 * Signed out: button and host note, and never the old wording.
	 */
	public function test_signed_out_view_has_sign_in_button_and_host_note() {
		$html = $this->render();

		$this->assertStringContainsString( 'Sign in with ecomweb', $html );
		$this->assertStringContainsString( 'ecomweb will show your web host&#039;s location, not yours.', $html );
		$this->assertStringNotContainsString( 'Connect to ecomweb', $html );
	}

	/**
	 * The revoked reason is shown.
	 */
	public function test_revoked_notice_shown() {
		update_option( Session_Store::REASON_OPTION, 'revoked', false );
		$this->assertStringContainsString( 'Signed out of ecomweb. Sign in again.', $this->render() );
	}

	/**
	 * The other reasons use their own labels.
	 */
	public function test_other_reason_notices_shown() {
		update_option( Session_Store::REASON_OPTION, 'salts_changed', false );
		$this->assertStringContainsString( 'Your WordPress security keys changed, so the saved sign-in can&#039;t be read. Sign in again.', $this->render() );
		update_option( Session_Store::REASON_OPTION, 'site_moved', false );
		$this->assertStringContainsString( 'This site&#039;s address changed, so you were signed out of ecomweb. Sign in again.', $this->render() );
	}

	/**
	 * A pending device flow shows the signing-in view.
	 */
	public function test_pending_device_flow_shows_signing_in_view() {
		set_transient( "ecomweb_device_{$this->admin}", array( 'device_code' => 'DC-secret' ), 600 );
		$html = $this->render();

		$this->assertStringContainsString( 'Check that ecomweb shows the same code, then approve.', $html );
		$this->assertStringContainsString( 'Open ecomweb', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
		$this->assertStringNotContainsString( 'DC-secret', $html );
	}

	/**
	 * Every view, signed out or not, hides the tokens and the device code.
	 *
	 * @dataProvider states
	 * @param string $state State.
	 */
	public function test_render_never_prints_tokens( string $state ) {
		if ( 'signed_in' === $state ) {
			$this->sign_in();
		}
		if ( 'signing_in' === $state ) {
			set_transient( "ecomweb_device_{$this->admin}", array( 'device_code' => 'DC-secret' ), 600 );
		}
		$html = $this->render();

		$this->assertNotSame( '', $html );
		foreach ( array( 'AT-xyz', 'RT-xyz', 'DC-secret' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $html );
		}
	}

	/**
	 * Views to cover. Later tasks add theirs.
	 *
	 * @return array
	 */
	public function states(): array {
		return array(
			'signed out' => array( 'signed_out' ),
			'signing in' => array( 'signing_in' ),
			'signed in'  => array( 'signed_in' ),
		);
	}

	/**
	 * Signed in with no view yet: a plain placeholder.
	 */
	public function test_signed_in_without_view_shows_placeholder() {
		$this->sign_in();
		$html = $this->render();

		$this->assertStringContainsString( 'Not available yet', $html );
		$this->assertStringNotContainsString( 'Sign in with ecomweb', $html );
	}

	/**
	 * Users without the capability get wp_die.
	 */
	public function test_render_denied_for_user_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		$this->render();
	}

	/**
	 * The script is loaded on the plugin page only, with translated strings and a nonce.
	 */
	public function test_enqueue_only_on_plugin_page() {
		global $admin_page_hooks;
		$admin_page_hooks['woocommerce'] = 'woocommerce'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WooCommerce's own menu isn't loaded here.
		$this->page->register_menu();

		$this->page->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'ecomweb-admin', 'enqueued' ) );

		$this->page->enqueue( 'woocommerce_page_ecomweb' );
		$this->assertTrue( wp_script_is( 'ecomweb-admin', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'ecomweb-admin', 'data' );
		$this->assertStringContainsString( 'ecomwebWoo', $data );
		$this->assertStringContainsString( 'admin-ajax.php', $data );
		$this->assertStringContainsString( wp_create_nonce( 'ecomweb_ajax' ), $data );
		$this->assertStringContainsString( 'Sign-in was denied', $data );
	}

	/**
	 * The page URL.
	 */
	public function test_url() {
		$this->assertSame( admin_url( 'admin.php?page=ecomweb' ), Admin_Page::url() );
		$this->assertSame( admin_url( 'admin.php?page=ecomweb&view=confirm' ), Admin_Page::url( array( 'view' => 'confirm' ) ) );
	}
}
