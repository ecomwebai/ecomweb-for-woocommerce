<?php
/**
 * Auth access-token refresh tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Auth_Refresh
 */
class Test_Auth_Refresh extends WP_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Store.
	 *
	 * @var Session_Store
	 */
	private $store;

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
		$this->fake  = Fake_Api::start();
		$this->store = new Session_Store();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Auth under test.
	 *
	 * @param float $lock_timeout Lock wait.
	 * @return Auth
	 */
	private function auth( $lock_timeout = 15.0 ) {
		return new Auth( new Http(), $this->store, new Lock(), $lock_timeout );
	}

	/**
	 * Saves a session.
	 *
	 * @param int    $expires_at Expiry.
	 * @param string $access     Access token.
	 * @param string $refresh    Refresh token.
	 * @param string $api_url    API base.
	 */
	private function seed( $expires_at, $access = 'AT', $refresh = 'RT', $api_url = null ) {
		$this->store->write(
			array(
				'access_token'       => $access,
				'refresh_token'      => $refresh,
				'expires_at'         => $expires_at,
				'email'              => 'm@shop.vn',
				'user_id'            => 'u1',
				'api_url'            => null === $api_url ? Config::api_url() : $api_url,
				'signed_in_home_url' => 'example.org',
			)
		);
	}

	/**
	 * A TokenResponse.
	 *
	 * @param string $access  Access token.
	 * @param string $refresh Refresh token.
	 * @return array
	 */
	private function tokens( $access = 'AT2', $refresh = 'RT2' ) {
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_at'    => time() + 900,
			'expires_in'    => 900,
			'user'          => array(
				'id'    => 'u1',
				'email' => 'm@shop.vn',
				'name'  => null,
			),
		);
	}

	/**
	 * A fresh token is returned as is.
	 */
	public function test_fresh_token_returned_without_refresh() {
		$this->seed( time() + 600 );
		$this->assertSame( 'AT', $this->auth()->access_token() );
		$this->assertSame( 0, $this->fake->count( '/auth/session/refresh' ) );
	}

	/**
	 * An expiring token is refreshed and the new pair saved.
	 */
	public function test_expiring_token_refreshed_and_saved() {
		$this->seed( time() + 30 );
		$this->fake->on( 'POST', '/auth/session/refresh', 200, $this->tokens() );

		$this->assertSame( 'AT2', $this->auth()->access_token() );

		$session = $this->store->read();
		$this->assertSame( 'RT2', $session['refresh_token'] );
		$this->assertSame( 'example.org', $session['signed_in_home_url'] );
		$this->assertSame( Config::api_url(), $session['api_url'] );
		$call = $this->fake->calls( '/auth/session/refresh' )[0];
		$this->assertSame( '{"refresh_token":"RT"}', $call['body'] );
		$this->assertSame( Config::api_url() . '/auth/session/refresh', $call['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $call['headers'] );
		$this->assertFalse( get_option( '_ecomweb_lock_session' ) );
	}

	/**
	 * Force refreshes a token that is not expiring.
	 */
	public function test_force_refreshes_fresh_token() {
		$this->seed( time() + 600 );
		$this->fake->on( 'POST', '/auth/session/refresh', 200, $this->tokens() );
		$this->assertSame( 'AT2', $this->auth()->access_token( true ) );
		$this->assertSame( 1, $this->fake->count( '/auth/session/refresh' ) );
	}

	/**
	 * A 401 signs out with reason revoked.
	 */
	public function test_refresh_401_signs_out_with_revoked() {
		$this->seed( time() + 30 );
		$this->fake->on(
			'POST',
			'/auth/session/refresh',
			401,
			array(
				'code'    => 'SESSION_EXPIRED',
				'message' => 'Session expired',
			)
		);

		$r = $this->auth()->access_token();

		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ecomweb_signed_out', $r->get_error_code() );
		$this->assertSame( array( 'reason' => 'revoked' ), $r->get_error_data() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertSame( 'revoked', get_option( 'ecomweb_signed_out_reason' ) );
	}

	/**
	 * AUTH_SESSION_EXPIRED on any 4xx also signs out.
	 */
	public function test_refresh_auth_session_expired_signs_out() {
		$this->seed( time() + 30 );
		$this->fake->on( 'POST', '/auth/session/refresh', 400, array( 'code' => 'AUTH_SESSION_EXPIRED' ) );
		$r = $this->auth()->access_token();
		$this->assertSame( 'ecomweb_signed_out', $r->get_error_code() );
		$this->assertSame( 'revoked', $this->auth()->signed_out_reason() );
	}

	/**
	 * A transport failure keeps the tokens.
	 */
	public function test_refresh_network_error_keeps_tokens() {
		$this->seed( time() + 30 );
		$this->fake->fail( 'POST', '/auth/session/refresh', 'http_request_failed' );
		$r = $this->auth()->access_token();
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ecomweb_unreachable', $r->get_error_code() );
		$this->assertSame( 'RT', $this->store->read()['refresh_token'] );
	}

	/**
	 * A 5xx keeps the tokens.
	 */
	public function test_refresh_5xx_keeps_tokens() {
		$this->seed( time() + 30 );
		$this->fake->on( 'POST', '/auth/session/refresh', 503, array( 'message' => 'down' ) );
		$this->assertSame( 'ecomweb_unreachable', $this->auth()->access_token()->get_error_code() );
		$this->assertSame( 'RT', $this->store->read()['refresh_token'] );
	}

	/**
	 * A 429, or a 2xx without both tokens, is busy and keeps the tokens (W9).
	 *
	 * @return array
	 */
	public function busy_answers() {
		return array(
			'429'                => array( 429, array( 'message' => 'Too many requests' ) ),
			'2xx without tokens' => array( 200, array( 'access_token' => 'AT2' ) ),
			'2xx empty refresh'  => array(
				200,
				array(
					'access_token'  => 'AT2',
					'refresh_token' => '',
				),
			),
		);
	}

	/**
	 * Busy answers never overwrite the session.
	 *
	 * @dataProvider busy_answers
	 *
	 * @param int   $status Status.
	 * @param array $json   Body.
	 */
	public function test_refresh_busy_answers_keep_tokens( $status, $json ) {
		$this->seed( time() + 30 );
		$this->fake->on( 'POST', '/auth/session/refresh', $status, $json );
		$this->assertSame( 'ecomweb_busy', $this->auth()->access_token()->get_error_code() );
		$session = $this->store->read();
		$this->assertSame( 'AT', $session['access_token'] );
		$this->assertSame( 'RT', $session['refresh_token'] );
	}

	/**
	 * Another worker refreshed while this one waited for the lock: no second refresh.
	 */
	public function test_other_worker_refreshed_while_waiting_no_second_refresh() {
		$this->seed( time() + 30, 'A-access', 'A-refresh' );
		$foreign = Lock::acquire( 'session', 30, 0 );
		$this->assertNotNull( $foreign );
		$store = $this->store;
		$done  = false;
		add_action(
			'ecomweb_lock_wait',
			function () use ( $store, $foreign, &$done ) {
				if ( $done ) {
					return;
				}
				$done = true;
				$store->write(
					array(
						'access_token'       => 'B-access',
						'refresh_token'      => 'B-refresh',
						'expires_at'         => time() + 900,
						'email'              => 'm@shop.vn',
						'user_id'            => 'u1',
						'api_url'            => Config::api_url(),
						'signed_in_home_url' => 'example.org',
					)
				);
				Lock::release( 'session', $foreign );
			}
		);

		$this->assertSame( 'B-access', $this->auth()->access_token() );
		$this->assertSame( 0, $this->fake->count( '/auth/session/refresh' ) );
	}

	/**
	 * A lock that never frees gives busy and no refresh.
	 */
	public function test_lock_timeout_returns_busy_without_refresh() {
		$this->seed( time() + 30 );
		$this->assertNotNull( Lock::acquire( 'session', 30, 0 ) );
		$r = $this->auth( 0.3 )->access_token();
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ecomweb_busy', $r->get_error_code() );
		$this->assertSame( 0, $this->fake->count( '/auth/session/refresh' ) );
		$this->assertSame( 'RT', $this->store->read()['refresh_token'] );
	}

	/**
	 * A session made against another API is dropped, never sent or refreshed there.
	 */
	public function test_session_for_other_api_is_dropped_not_sent() {
		$this->seed( time() + 30, 'AT', 'RT', 'https://other.test/api/v1' );
		$r = $this->auth()->access_token();
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'ecomweb_not_signed_in', $r->get_error_code() );
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * No session at all.
	 */
	public function test_no_session_is_not_signed_in() {
		$auth = $this->auth();
		$this->assertSame( 'ecomweb_not_signed_in', $auth->access_token()->get_error_code() );
		$this->assertFalse( $auth->is_signed_in() );
		$this->assertNull( $auth->email() );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * Only known reasons are reported, and they can be cleared.
	 */
	public function test_signed_out_reason_and_clear() {
		$auth = $this->auth();
		update_option( 'ecomweb_signed_out_reason', 'something_else', false );
		$this->assertNull( $auth->signed_out_reason() );
		$auth->sign_out_local( 'salts_changed' );
		$this->assertSame( 'salts_changed', $auth->signed_out_reason() );
		$auth->clear_signed_out_reason();
		$this->assertNull( $auth->signed_out_reason() );
		$this->assertFalse( get_option( 'ecomweb_signed_out_reason' ) );
	}
}
