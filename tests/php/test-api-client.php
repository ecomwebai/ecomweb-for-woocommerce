<?php
/**
 * Api_Client and sign-out tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Api_Client;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Http_Response;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Multipart;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Api_Client
 */
class Test_Api_Client extends WP_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Auth.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * Client under test.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Set up: a fresh session.
	 */
	public function set_up() {
		parent::set_up();
		// wp-env pins WP_HOME and WP_SITEURL to localhost:8889. Let the options decide, and pin example.org.
		remove_filter( 'option_home', '_config_wp_home' );
		remove_filter( 'option_siteurl', '_config_wp_siteurl' );
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		$this->fake = Fake_Api::start();
		$http       = new Http();
		$this->auth = new Auth( $http, new Session_Store(), new Lock() );
		$this->api  = new Api_Client( $this->auth, $http );
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 600,
				'email'              => 'm@shop.vn',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => 'example.org',
			)
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Queues a refresh to AT2.
	 */
	private function queue_refresh() {
		$this->fake->on(
			'POST',
			'/auth/session/refresh',
			200,
			array(
				'access_token'  => 'AT2',
				'refresh_token' => 'RT2',
				'expires_at'    => time() + 900,
				'expires_in'    => 900,
				'user'          => array(
					'id'    => 'u1',
					'email' => 'm@shop.vn',
					'name'  => null,
				),
			)
		);
	}

	/**
	 * Every call carries the bearer token, to the configured API only.
	 */
	public function test_adds_bearer() {
		$this->fake->on( 'GET', '/stores', 200, array( 'data' => array() ) );
		$r = $this->api->get( '/stores', array( 'limit' => 100 ) );
		$this->assertInstanceOf( Http_Response::class, $r );
		$call = $this->fake->calls()[0];
		$this->assertSame( 'Bearer AT', $call['headers']['Authorization'] );
		$this->assertSame( Config::api_url() . '/stores?limit=100', $call['url'] );
	}

	/**
	 * POST sends JSON with the bearer token and returns non-401 statuses to the caller.
	 */
	public function test_post_sends_json_and_returns_other_statuses() {
		$this->fake->on( 'POST', '/stores/s1/x', 422, array( 'code' => 'BAD' ) );
		$r = $this->api->post( '/stores/s1/x', array( 'a' => 1 ) );
		$this->assertInstanceOf( Http_Response::class, $r );
		$this->assertSame( 422, $r->status );
		$call = $this->fake->calls()[0];
		$this->assertSame( '{"a":1}', $call['body'] );
		$this->assertSame( 'application/json', $call['headers']['Content-Type'] );
		$this->assertSame( 'Bearer AT', $call['headers']['Authorization'] );
	}

	/**
	 * Timeouts: 15 s by default, 30 s when asked, 60 s for uploads.
	 */
	public function test_timeouts() {
		$seen = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$seen ) {
				$seen[] = $args['timeout'];
				return $pre;
			},
			5,
			2
		);
		$this->fake->on( 'GET', '/stores', 200 )->on( 'POST', '/batch', 202 )->on( 'POST', '/assets/upload', 201 );
		$file = wp_tempnam( 'tee.png' );
		file_put_contents( $file, 'PNG' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->api->get( '/stores' );
		$this->api->post( '/batch', array(), 30 );
		$this->api->upload( '/assets/upload', array(), $file, 'tee.png', 'image/png' );
		wp_delete_file( $file );
		$this->assertSame( array( 15, 30, 60 ), $seen );
	}

	/**
	 * A 401 forces one refresh and one retry.
	 */
	public function test_401_forces_one_refresh_and_retries_once() {
		$this->fake->on( 'GET', '/stores', 401 )->on( 'GET', '/stores', 200, array( 'data' => array() ) );
		$this->queue_refresh();

		$r = $this->api->get( '/stores' );

		$this->assertSame( 200, $r->status );
		$calls = $this->fake->calls( '/stores' );
		$this->assertSame( 'Bearer AT', $calls[0]['headers']['Authorization'] );
		$this->assertSame( 'Bearer AT2', $calls[1]['headers']['Authorization'] );
		$this->assertSame( 2, $this->fake->count( '/stores' ) );
		$this->assertSame( 1, $this->fake->count( '/auth/session/refresh' ) );
	}

	/**
	 * A second 401 signs out with reason revoked.
	 */
	public function test_second_401_signs_out() {
		$this->fake->on( 'GET', '/stores', 401 )->on( 'GET', '/stores', 401 );
		$this->queue_refresh();

		$r = $this->api->get( '/stores' );

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ecomweb_signed_out', $r->get_error_code() );
		$this->assertSame( array( 'reason' => 'revoked' ), $r->get_error_data() );
		$this->assertSame( 'revoked', get_option( 'ecomweb_signed_out_reason' ) );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertSame( 2, $this->fake->count( '/stores' ) );
	}

	/**
	 * Not signed in: no request at all.
	 */
	public function test_not_signed_in_sends_nothing() {
		( new Session_Store() )->delete();
		$r = $this->api->get( '/stores' );
		$this->assertSame( 'ecomweb_not_signed_in', $r->get_error_code() );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * A transport failure is reported as unreachable.
	 */
	public function test_transport_failure_is_unreachable() {
		$this->fake->fail( 'GET', '/stores', 'http_request_failed' );
		$this->assertSame( 'ecomweb_unreachable', $this->api->get( '/stores' )->get_error_code() );
		$this->assertNotFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * Store refs are URL-encoded.
	 */
	public function test_store_path_encodes_ref() {
		$this->assertSame( '/stores/a%2Fb/assets/upload', Api_Client::store_path( 'a/b', '/assets/upload' ) );
	}

	/**
	 * Sign-out posts the refresh token to logout, deletes the session and sets no reason.
	 */
	public function test_sign_out_posts_logout_and_deletes() {
		$this->fake->on( 'POST', '/auth/logout', 200 );
		$seen_timeout = null;
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$seen_timeout ) {
				$seen_timeout = $args['timeout'];
				return $pre;
			},
			5,
			2
		);

		$this->auth->sign_out();

		$call = $this->fake->calls( '/auth/logout' )[0];
		$this->assertSame( '{"refresh_token":"RT"}', $call['body'] );
		$this->assertSame( Config::api_url() . '/auth/logout', $call['url'] );
		$this->assertSame( 5, $seen_timeout );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertFalse( get_option( 'ecomweb_signed_out_reason' ) );
	}

	/**
	 * A failed logout still deletes the session.
	 */
	public function test_sign_out_ignores_logout_failure() {
		$this->fake->fail( 'POST', '/auth/logout', 'http_request_failed' );
		$this->auth->sign_out();
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertFalse( get_option( 'ecomweb_signed_out_reason' ) );
	}

	/**
	 * Sign-out never sends a token to another API.
	 */
	public function test_sign_out_with_other_api_session_sends_nothing() {
		$session            = ( new Session_Store() )->read();
		$session['api_url'] = 'https://other.test/api/v1';
		( new Session_Store() )->write( $session );
		$this->auth->sign_out();
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * Upload sends multipart with the fields and the file.
	 */
	public function test_upload_sends_multipart() {
		$png = wp_tempnam( 'tee.png' );
		file_put_contents( $png, "\x89PNG-bytes" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->fake->on( 'POST', '/stores/s1/assets/upload', 201, array( 'asset' => array( 'id' => 'a1' ) ) );

		$r = $this->api->upload( '/stores/s1/assets/upload', array( 'asset_category' => 'catalog' ), $png, 'tee.png', 'image/png' );
		wp_delete_file( $png );

		$this->assertSame( 201, $r->status );
		$call = $this->fake->calls()[0];
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $call['headers']['Content-Type'] );
		$this->assertSame( 'Bearer AT', $call['headers']['Authorization'] );
		$this->assertStringContainsString( 'name="asset_category"' . "\r\n\r\ncatalog", $call['body'] );
		$this->assertStringContainsString( 'name="file"; filename="tee.png"', $call['body'] );
		$this->assertStringContainsString( "Content-Type: image/png\r\n\r\n\x89PNG-bytes\r\n", $call['body'] );
	}

	/**
	 * A 401 on upload retries with the same body.
	 */
	public function test_upload_retries_after_401() {
		$png = wp_tempnam( 'tee.png' );
		file_put_contents( $png, 'PNG' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->fake->on( 'POST', '/assets/upload', 401 )->on( 'POST', '/assets/upload', 201 );
		$this->queue_refresh();
		$r = $this->api->upload( '/assets/upload', array(), $png, 'tee.png', 'image/png' );
		wp_delete_file( $png );
		$this->assertSame( 201, $r->status );
		$calls = $this->fake->calls( '/assets/upload' );
		$this->assertSame( $calls[0]['body'], $calls[1]['body'] );
		$this->assertSame( 'Bearer AT2', $calls[1]['headers']['Authorization'] );
	}

	/**
	 * An unreadable file gives an error and no request.
	 */
	public function test_upload_missing_file_sends_nothing() {
		$r = $this->api->upload( '/assets/upload', array(), '/nonexistent/tee.png', 'tee.png', 'image/png' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * Multipart body layout, and quotes or newlines can't break a header.
	 */
	public function test_multipart_build() {
		list( $body, $type ) = Multipart::build( array( 'k' => 'v' ), 'file', "a\"b\r\n.png", 'image/png', 'BYTES' );
		$this->assertSame( 1, preg_match( '/^multipart\/form-data; boundary=(\S+)$/', $type, $m ) );
		$boundary = $m[1];
		$this->assertSame(
			"--$boundary\r\nContent-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n" .
			"--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a%22b.png\"\r\nContent-Type: image/png\r\n\r\nBYTES\r\n" .
			"--$boundary--\r\n",
			$body
		);
	}

	/**
	 * The plugin shares one Auth and one Api_Client.
	 */
	public function test_plugin_accessors() {
		$this->assertInstanceOf( Auth::class, Plugin::instance()->auth() );
		$this->assertSame( Plugin::instance()->auth(), Plugin::instance()->auth() );
		$this->assertInstanceOf( Api_Client::class, Plugin::instance()->api() );
		$this->assertSame( Plugin::instance()->api(), Plugin::instance()->api() );
	}
}
