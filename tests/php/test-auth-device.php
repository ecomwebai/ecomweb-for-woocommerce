<?php
/**
 * Auth device-code sign-in tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Lock;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Auth_Device
 */
class Test_Auth_Device extends WP_UnitTestCase {

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
	 * User id.
	 *
	 * @var int
	 */
	private $uid;

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
		$this->uid  = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Queues the device-code answer.
	 */
	private function queue_code() {
		$this->fake->on(
			'POST',
			'/auth/device/code',
			201,
			array(
				'device_code'               => 'DC-secret',
				'user_code'                 => 'ABCD-EFGH',
				'verification_uri'          => 'https://ecomweb.app/device',
				'verification_uri_complete' => 'https://ecomweb.app/device?code=ABCD-EFGH',
				'expires_in'                => 600,
				'interval'                  => 5,
			)
		);
	}

	/**
	 * Starts the flow and moves next_poll_at into the past.
	 */
	private function start_and_wait() {
		$this->queue_code();
		$this->auth->start_device_flow( $this->uid );
		$key                     = "ecomweb_device_{$this->uid}";
		$pending                 = get_transient( $key );
		$pending['next_poll_at'] = time() - 1;
		set_transient( $key, $pending, 600 );
	}

	/**
	 * The start call sends the client and device name, and never returns the device code.
	 */
	public function test_start_sends_wordpress_client_and_hides_device_code() {
		$this->queue_code();
		$out = $this->auth->start_device_flow( $this->uid );

		$this->assertSame(
			array(
				'client'      => 'wordpress',
				'device_name' => 'WooCommerce – example.org',
			),
			json_decode( $this->fake->calls()[0]['body'], true )
		);
		$this->assertSame( Config::api_url() . '/auth/device/code', $this->fake->calls()[0]['url'] );
		$this->assertSame(
			array(
				'user_code'                 => 'ABCD-EFGH',
				'verification_uri_complete' => 'https://ecomweb.app/device?code=ABCD-EFGH',
				'interval'                  => 5,
				'expires_in'                => 600,
			),
			$out
		);
		$this->assertArrayNotHasKey( 'device_code', $out );
		$this->assertStringNotContainsString( 'DC-secret', wp_json_encode( $out ) );
		$this->assertSame( 'DC-secret', get_transient( "ecomweb_device_{$this->uid}" )['device_code'] );
	}

	/**
	 * Only a link on the dashboard's origin reaches the browser.
	 *
	 * @dataProvider verification_links
	 * @param string $given    Link from the API.
	 * @param string $expected Link returned.
	 * @param bool   $replaced Whether the replacement notice fires.
	 */
	public function test_verification_uri_must_match_dashboard_origin( string $given, string $expected, bool $replaced ) {
		$this->fake->on(
			'POST',
			'/auth/device/code',
			201,
			array(
				'device_code'               => 'DC-secret',
				'user_code'                 => 'AB CD&1',
				'verification_uri_complete' => $given,
				'expires_in'                => 600,
				'interval'                  => 5,
			)
		);
		$fired = 0;
		add_action(
			'ecomweb_verification_uri_replaced',
			function () use ( &$fired ) {
				++$fired;
			}
		);
		$out = $this->auth->start_device_flow( $this->uid );

		$this->assertSame( $expected, $out['verification_uri_complete'] );
		$this->assertSame( $replaced ? 1 : 0, $fired );
	}

	/**
	 * Links and what comes back.
	 *
	 * @return array
	 */
	public function verification_links(): array {
		$built = Config::dashboard_url() . '/device?code=AB%20CD%261';
		$host  = (string) wp_parse_url( Config::dashboard_url(), PHP_URL_HOST );
		return array(
			'matching origin' => array( Config::dashboard_url() . '/device?code=X', Config::dashboard_url() . '/device?code=X', false ),
			'other host'      => array( 'https://evil.example/device?code=X', $built, true ),
			'lookalike host'  => array( 'https://' . $host . '.evil.example/device?code=X', $built, true ),
			'userinfo trick'  => array( 'https://' . $host . '@evil.example/device?code=X', $built, true ),
			'other scheme'    => array( 'javascript:alert(1)', $built, true ),
		);
	}

	/**
	 * A failed start gives a WP_Error and no transient.
	 */
	public function test_start_failure_returns_error() {
		$this->fake->fail( 'POST', '/auth/device/code', 'http_request_failed' );
		$this->assertInstanceOf( WP_Error::class, $this->auth->start_device_flow( $this->uid ) );
		$this->assertFalse( get_transient( "ecomweb_device_{$this->uid}" ) );
	}

	/**
	 * Polling before the interval has passed makes no call.
	 */
	public function test_poll_before_interval_makes_no_call() {
		$this->queue_code();
		$this->auth->start_device_flow( $this->uid );
		$r = $this->auth->poll_device_flow( $this->uid );
		$this->assertSame( 0, $this->fake->count( '/auth/device/token' ) );
		$this->assertSame( 'pending', $r['status'] );
		$this->assertSame( 5, $r['interval'] );
	}

	/**
	 * API codes and RFC names.
	 *
	 * @return array
	 */
	public function poll_codes() {
		return array(
			'DEVICE_AUTH_PENDING'      => array( 'DEVICE_AUTH_PENDING', 'pending', 5 ),
			'authorization_pending'    => array( 'authorization_pending', 'pending', 5 ),
			'DEVICE_AUTH_SLOW_DOWN'    => array( 'DEVICE_AUTH_SLOW_DOWN', 'pending', 10 ),
			'slow_down'                => array( 'slow_down', 'pending', 10 ),
			'DEVICE_AUTH_DENIED'       => array( 'DEVICE_AUTH_DENIED', 'denied', 5 ),
			'access_denied'            => array( 'access_denied', 'denied', 5 ),
			'DEVICE_AUTH_EXPIRED'      => array( 'DEVICE_AUTH_EXPIRED', 'expired', 5 ),
			'DEVICE_AUTH_INVALID_CODE' => array( 'DEVICE_AUTH_INVALID_CODE', 'expired', 5 ),
			'expired_token'            => array( 'expired_token', 'expired', 5 ),
		);
	}

	/**
	 * Each error code maps to a status; denied and expired drop the transient.
	 *
	 * @dataProvider poll_codes
	 *
	 * @param string $code     Error code.
	 * @param string $status   Expected status.
	 * @param int    $interval Expected interval.
	 */
	public function test_poll_pending_slow_down_denied_expired( $code, $status, $interval ) {
		$this->start_and_wait();
		$this->fake->on(
			'POST',
			'/auth/device/token',
			400,
			array(
				'code'    => $code,
				'message' => 'x',
			)
		);

		$r = $this->auth->poll_device_flow( $this->uid );

		$this->assertSame(
			array(
				'status'   => $status,
				'interval' => $interval,
				'email'    => null,
			),
			$r
		);
		$this->assertSame( array( 'device_code' => 'DC-secret' ), json_decode( $this->fake->calls( '/auth/device/token' )[0]['body'], true ) );
		$pending = get_transient( "ecomweb_device_{$this->uid}" );
		if ( 'pending' === $status ) {
			$this->assertSame( $interval, $pending['interval'] );
			$this->assertGreaterThan( time(), $pending['next_poll_at'] );
		} else {
			$this->assertFalse( $pending );
		}
		$this->assertNull( ( new Session_Store() )->read() );
	}

	/**
	 * Approval saves the session and clears the transient and the signed-out reason.
	 */
	public function test_poll_approved_saves_session() {
		update_option( 'ecomweb_signed_out_reason', 'revoked', false );
		$this->start_and_wait();
		$this->fake->on(
			'POST',
			'/auth/device/token',
			200,
			array(
				'access_token'  => 'AT',
				'refresh_token' => 'RT',
				'expires_at'    => time() + 900,
				'expires_in'    => 900,
				'user'          => array(
					'id'    => 'u1',
					'email' => 'm@shop.vn',
					'name'  => null,
				),
			)
		);

		$r = $this->auth->poll_device_flow( $this->uid );

		$this->assertSame(
			array(
				'status'   => 'approved',
				'interval' => 5,
				'email'    => 'm@shop.vn',
			),
			$r
		);
		$session = ( new Session_Store() )->read();
		$this->assertSame( 'example.org', $session['signed_in_home_url'] );
		$this->assertSame( Config::api_url(), $session['api_url'] );
		$this->assertSame( 'AT', $session['access_token'] );
		$this->assertSame( 'RT', $session['refresh_token'] );
		$this->assertSame( 'u1', $session['user_id'] );
		$this->assertFalse( get_transient( "ecomweb_device_{$this->uid}" ) );
		$this->assertFalse( get_option( 'ecomweb_signed_out_reason' ) );
		$this->assertTrue( $this->auth->is_signed_in() );
		$this->assertSame( 'm@shop.vn', $this->auth->email() );
	}

	/**
	 * Approval fires ecomweb_signed_in only when a connection exists.
	 */
	public function test_poll_approved_fires_signed_in_only_with_connection() {
		$fired = 0;
		add_action(
			'ecomweb_signed_in',
			function () use ( &$fired ) {
				++$fired;
			}
		);
		$tokens = array(
			'access_token'  => 'AT',
			'refresh_token' => 'RT',
			'expires_at'    => time() + 900,
			'expires_in'    => 900,
			'user'          => array(
				'id'    => 'u1',
				'email' => null,
				'name'  => null,
			),
		);
		$this->fake->on( 'POST', '/auth/device/token', 200, $tokens );

		$this->start_and_wait();
		$this->assertSame( 'approved', $this->auth->poll_device_flow( $this->uid )['status'] );
		$this->assertSame( 0, $fired );

		update_option( 'ecomweb_connection', array( 'connection_id' => 'c1' ), false );
		$this->start_and_wait();
		$r = $this->auth->poll_device_flow( $this->uid );
		$this->assertSame( 'approved', $r['status'] );
		$this->assertNull( $r['email'] );
		$this->assertSame( 1, $fired );
	}

	/**
	 * A 2xx without both tokens is not a session.
	 */
	public function test_poll_2xx_without_tokens_saves_nothing() {
		$this->start_and_wait();
		$this->fake->on( 'POST', '/auth/device/token', 200, array( 'access_token' => 'AT' ) );
		$this->assertInstanceOf( WP_Error::class, $this->auth->poll_device_flow( $this->uid ) );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * A transient past its expiry gives expired without a call.
	 */
	public function test_poll_expired_locally_makes_no_call() {
		set_transient(
			"ecomweb_device_{$this->uid}",
			array(
				'device_code'  => 'DC-secret',
				'interval'     => 5,
				'expires_at'   => time() - 1,
				'next_poll_at' => time() - 10,
			),
			600
		);
		$r = $this->auth->poll_device_flow( $this->uid );
		$this->assertSame( 'expired', $r['status'] );
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertFalse( get_transient( "ecomweb_device_{$this->uid}" ) );
	}

	/**
	 * No transient at all gives expired without a call.
	 */
	public function test_poll_without_flow_is_expired() {
		$this->assertSame( 'expired', $this->auth->poll_device_flow( $this->uid )['status'] );
		$this->assertSame( array(), $this->fake->calls() );
	}
}
