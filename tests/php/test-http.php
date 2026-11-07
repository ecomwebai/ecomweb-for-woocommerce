<?php
/**
 * HTTP wrapper and Fake_Api tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Config;
use Ecomweb\Woo\Http;
use Ecomweb\Woo\Http_Response;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Class Test_Http
 */
class Test_Http extends WP_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Tear down.
	 */
	public function tear_down() {
		if ( $this->fake ) {
			$this->fake->stop();
		}
		parent::tear_down();
	}

	/**
	 * TLS on, redirects off, as seen by the fake.
	 */
	public function test_sends_tls_verify_and_no_redirects() {
		$this->fake = Fake_Api::start()->on( 'GET', '/ping', 200, array( 'ok' => true ) );
		$r          = ( new Http() )->request( 'GET', Config::api_url() . '/ping' );
		$this->assertSame( 1, $this->fake->count( '/ping' ) );
		$this->assertInstanceOf( Http_Response::class, $r );
		$this->assertTrue( $r->ok() );
		$this->assertSame( array( 'ok' => true ), $r->json );
	}

	/**
	 * Fake_Api rejects an insecure request.
	 */
	public function test_fake_api_fails_insecure_request() {
		$this->fake = Fake_Api::start()->on( 'GET', '/ping', 200 );
		$this->expectException( AssertionFailedError::class );
		wp_remote_request(
			Config::api_url() . '/ping',
			array(
				'sslverify'   => false,
				'redirection' => 0,
			)
		);
	}

	/**
	 * Fake_Api rejects a redirecting request.
	 */
	public function test_fake_api_fails_redirecting_request() {
		$this->fake = Fake_Api::start()->on( 'GET', '/ping', 200 );
		$this->expectException( AssertionFailedError::class );
		wp_remote_request( Config::api_url() . '/ping', array( 'sslverify' => true ) );
	}

	/**
	 * Fake_Api rejects an unmatched request.
	 */
	public function test_fake_api_fails_unmatched_request() {
		$this->fake = Fake_Api::start()->on( 'GET', '/ping', 200 );
		$this->expectException( AssertionFailedError::class );
		( new Http() )->request( 'GET', Config::api_url() . '/other' );
	}

	/**
	 * Queued responses answer in order; the last repeats.
	 */
	public function test_queue_answers_in_order_and_last_repeats() {
		$this->fake = Fake_Api::start()->on( 'GET', '/q', 500 )->on( 'GET', '/q', 200 );
		$http       = new Http();
		$this->assertSame( 500, $http->request( 'GET', Config::api_url() . '/q' )->status );
		$this->assertSame( 200, $http->request( 'GET', Config::api_url() . '/q' )->status );
		$this->assertSame( 200, $http->request( 'GET', Config::api_url() . '/q?x=1' )->status );
	}

	/**
	 * Error code and message.
	 */
	public function test_parses_error_code_and_message() {
		$this->fake = Fake_Api::start()->on(
			'POST',
			'/x',
			400,
			array(
				'code'    => 'DEVICE_AUTH_PENDING',
				'message' => 'Waiting',
			)
		); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
		$r          = ( new Http() )->request( 'POST', Config::api_url() . '/x' );
		$this->assertSame( 'DEVICE_AUTH_PENDING', $r->error_code() );
		$this->assertSame( 'Waiting', $r->error_message() );
		$this->assertFalse( $r->ok() );
	}

	/**
	 * Validation-style array message, error fallback, status fallback.
	 */
	public function test_error_message_variants() {
		$r = new Http_Response( 400, array(), array( 'message' => array( 'name must be a string', 'price must be positive' ) ) );
		$this->assertSame( 'name must be a string; price must be positive', $r->error_message() );
		$this->assertNull( ( new Http_Response( 400, array(), array( 'code' => 12 ) ) )->error_code() );
		$this->assertSame( 'Bad', ( new Http_Response( 400, array(), array( 'error' => 'Bad' ) ) )->error_message() );
		$this->assertSame( 'HTTP 502', ( new Http_Response( 502, array(), null ) )->error_message() );
	}

	/**
	 * 2xx is ok, others are not.
	 */
	public function test_ok_is_2xx_only() {
		$this->assertTrue( ( new Http_Response( 202, array(), null ) )->ok() );
		$this->assertTrue( ( new Http_Response( 201, array(), null ) )->ok() );
		$this->assertFalse( ( new Http_Response( 304, array(), null ) )->ok() );
		$this->assertFalse( ( new Http_Response( 199, array(), null ) )->ok() );
	}

	/**
	 * Transport failure.
	 */
	public function test_transport_error_is_wp_error() {
		$this->fake = Fake_Api::start()->fail( 'GET', '/x', 'http_request_failed' );
		$r          = ( new Http() )->request( 'GET', Config::api_url() . '/x' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'http_request_failed', $r->get_error_code() );
	}

	/**
	 * JSON body and content type.
	 */
	public function test_json_body_and_content_type() {
		$this->fake = Fake_Api::start()->on( 'POST', '/j', 200 );
		( new Http() )->request( 'POST', Config::api_url() . '/j', array( 'json' => array( 'a' => 1 ) ) );
		$call = $this->fake->calls()[0];
		$this->assertSame( '{"a":1}', $call['body'] );
		$this->assertSame( 'application/json', $call['headers']['Content-Type'] );
	}

	/**
	 * Default headers, caller headers, and response header lookup.
	 */
	public function test_headers_and_user_agent() {
		$this->fake = Fake_Api::start()->on( 'GET', '/h', 200, array(), array( 'X-Request-Id' => 'abc' ) );
		$r          = ( new Http() )->request( 'GET', Config::api_url() . '/h', array( 'headers' => array( 'Authorization' => 'Bearer t' ) ) );
		$h          = $this->fake->calls( '/h' )[0]['headers'];
		$this->assertSame( 'application/json', $h['Accept'] );
		$this->assertSame( 'Bearer t', $h['Authorization'] );
		$this->assertSame( 'ecomweb-for-woocommerce/' . ECOMWEB_WOO_VERSION . '; ' . home_url(), $h['User-Agent'] );
		$this->assertSame( 'abc', $r->header( 'x-request-id' ) );
		$this->assertNull( $r->header( 'missing' ) );
	}

	/**
	 * Raw body passes through unchanged.
	 */
	public function test_raw_body_and_timeout() {
		$this->fake = Fake_Api::start()->on( 'PUT', '/b', 200 );
		( new Http() )->request(
			'PUT',
			Config::api_url() . '/b',
			array(
				'body'    => 'raw-bytes',
				'timeout' => 30,
			)
		); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
		$this->assertSame( 'raw-bytes', $this->fake->calls( '/b' )[0]['body'] );
	}
}
