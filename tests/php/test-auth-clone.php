<?php
/**
 * Auth clone / move detection tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Auth_Clone
 */
class Test_Auth_Clone extends WP_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Auth under test.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		// wp-env pins WP_HOME and WP_SITEURL to localhost:8889. Let the options decide, and pin example.org.
		remove_filter( 'option_home', '_config_wp_home' );
		remove_filter( 'option_siteurl', '_config_wp_siteurl' );
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		$this->fake = Fake_Api::start();
		$this->auth = new Auth( new Http(), new Session_Store(), new Lock() );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Saves a fresh session signed in at $home.
	 *
	 * @param string $home Normalised home.
	 */
	private function seed( $home = 'example.org' ) {
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 600,
				'email'              => 'm@shop.vn',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => $home,
			)
		);
	}

	/**
	 * Moves the site.
	 *
	 * @param string $url New home and site URL.
	 */
	private function move_to( $url ) {
		update_option( 'home', $url );
		update_option( 'siteurl', $url );
	}

	/**
	 * A changed home URL drops the tokens without any request, and keeps the connection and meta.
	 */
	public function test_changed_home_url_drops_tokens_without_network() {
		$this->seed();
		Plugin::site_uuid();
		update_option( 'ecomweb_connection', array( 'connection_id' => 'c1' ), false );
		$pid = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $pid, '_ecomweb_product_id', 'E1' );
		$this->move_to( 'https://copy.example.net' );

		$r = $this->auth->access_token();

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ecomweb_signed_out', $r->get_error_code() );
		$this->assertSame( array( 'reason' => 'site_moved' ), $r->get_error_data() );
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertSame( 'site_moved', get_option( 'ecomweb_signed_out_reason' ) );
		$this->assertNotFalse( get_option( 'ecomweb_site_uuid' ) );
		$this->assertNotFalse( get_option( 'ecomweb_connection' ) );
		$this->assertSame( 'E1', get_post_meta( $pid, '_ecomweb_product_id', true ) );
	}

	/**
	 * The is_signed_in() check runs the move check.
	 */
	public function test_is_signed_in_detects_move() {
		$this->seed();
		$this->assertTrue( $this->auth->is_signed_in() );
		$this->move_to( 'https://copy.example.net' );
		$this->assertFalse( $this->auth->is_signed_in() );
		$this->assertSame( 'site_moved', $this->auth->signed_out_reason() );
	}

	/**
	 * Signing out after a move never sends the refresh token.
	 */
	public function test_sign_out_after_move_does_not_call_logout() {
		$this->seed();
		$this->move_to( 'https://copy.example.net' );
		$this->auth->sign_out();
		$this->assertSame( 0, $this->fake->count( '/auth/logout' ) );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * Scheme and trailing slash are not a move.
	 */
	public function test_scheme_and_trailing_slash_do_not_count_as_move() {
		$this->seed( Auth::normalise_home( 'https://Example.org/' ) );
		$this->move_to( 'http://example.org' );
		$this->assertFalse( $this->auth->check_site_moved() );
		$this->assertNotFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * A different subdirectory is a move.
	 */
	public function test_subdirectory_change_counts_as_move() {
		$this->seed( Auth::normalise_home( 'https://example.org/shop' ) );
		$this->move_to( 'https://example.org' );
		$this->assertTrue( $this->auth->check_site_moved() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * No session, no move.
	 */
	public function test_no_session_is_not_a_move() {
		$this->move_to( 'https://copy.example.net' );
		$this->assertFalse( $this->auth->check_site_moved() );
		$this->assertFalse( get_option( 'ecomweb_signed_out_reason' ) );
	}

	/**
	 * Normalised forms.
	 *
	 * @return array
	 */
	public function homes() {
		return array(
			'scheme and case' => array( 'https://Example.ORG/', 'example.org' ),
			'port'            => array( 'http://example.org:8080', 'example.org:8080' ),
			'path'            => array( 'https://example.org/Shop/', 'example.org/Shop' ),
			'already normal'  => array( 'example.org/shop', 'example.org/shop' ),
			'host and port'   => array( 'localhost:8889', 'localhost:8889' ),
		);
	}

	/**
	 * Host[:port] lowercased plus the path without trailing slash; idempotent.
	 *
	 * @dataProvider homes
	 *
	 * @param string $url      Input.
	 * @param string $expected Output.
	 */
	public function test_normalise_home( $url, $expected ) {
		$this->assertSame( $expected, Auth::normalise_home( $url ) );
		$this->assertSame( $expected, Auth::normalise_home( $expected ) );
	}
}
