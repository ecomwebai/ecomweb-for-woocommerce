<?php
/**
 * Device sign-in AJAX tests.
 *
 * @package ecomweb-for-woocommerce
 */

/**
 * Class Test_Ajax_Device
 */
class Test_Ajax_Device extends WP_Ajax_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		// _handleAjax() fires admin_init, whose update checks would call wordpress.org.
		foreach ( array( '_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes' ) as $callback ) {
			remove_action( 'admin_init', $callback );
		}
		$this->fake = Fake_Api::start();
		// Plugin::boot() ran before the test; its handlers are restored with the hooks.
		$this->assertNotFalse( has_action( 'wp_ajax_ecomweb_device_start' ) );
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
				'verification_uri_complete' => 'https://ecomweb.app/device?code=ABCD-EFGH',
				'expires_in'                => 600,
				'interval'                  => 5,
			)
		);
	}

	/**
	 * Logs in a user with a role and posts a valid nonce.
	 *
	 * @param string $role Role.
	 */
	private function act_as( string $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$_POST['nonce'] = wp_create_nonce( 'ecomweb_ajax' );
	}

	/**
	 * Runs an action and returns the decoded response.
	 *
	 * @param string $action Action.
	 * @return array
	 */
	private function call( string $action ): array {
		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		return json_decode( $this->_last_response, true );
	}

	/**
	 * A missing nonce is refused before anything else happens.
	 */
	public function test_device_start_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( WPAjaxDieStopException::class );
		try {
			$this->_handleAjax( 'ecomweb_device_start' );
		} finally {
			$this->assertSame( array(), $this->fake->calls() );
		}
	}

	/**
	 * Shop managers may sign in, editors may not.
	 */
	public function test_device_start_requires_manage_woocommerce() {
		$this->queue_code();
		$this->act_as( 'shop_manager' );
		$this->assertTrue( $this->call( 'ecomweb_device_start' )['success'] );

		$this->fake->stop();
		$this->fake = Fake_Api::start();
		$this->act_as( 'editor' );
		$this->assertFalse( $this->call( 'ecomweb_device_start' )['success'] );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * Visitors have no handler at all.
	 */
	public function test_no_nopriv_handlers() {
		$this->assertFalse( has_action( 'wp_ajax_nopriv_ecomweb_device_start' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_ecomweb_device_poll' ) );
	}

	/**
	 * The browser gets the user code and link, never the device code.
	 */
	public function test_device_start_response_has_no_device_code() {
		$this->queue_code();
		$this->act_as( 'administrator' );
		$response = $this->call( 'ecomweb_device_start' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'ABCD-EFGH', $response['data']['user_code'] );
		$this->assertSame( 'https://ecomweb.app/device?code=ABCD-EFGH', $response['data']['verification_uri_complete'] );
		$this->assertStringNotContainsString( 'DC-secret', $this->_last_response );
	}

	/**
	 * A failed start is an error with a message.
	 */
	public function test_device_start_failure_is_an_error() {
		$this->fake->fail( 'POST', '/auth/device/code', 'http_request_failed' );
		$this->act_as( 'administrator' );
		$response = $this->call( 'ecomweb_device_start' );

		$this->assertFalse( $response['success'] );
		$this->assertNotEmpty( $response['data']['message'] );
	}

	/**
	 * Polling answers with status and interval.
	 */
	public function test_device_poll_returns_status() {
		$this->queue_code();
		$this->act_as( 'administrator' );
		$this->call( 'ecomweb_device_start' );
		$_POST['nonce'] = wp_create_nonce( 'ecomweb_ajax' );
		$response       = $this->call( 'ecomweb_device_poll' );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'status'   => 'pending',
					'interval' => 5,
				),
			),
			$response
		);
		$this->assertStringNotContainsString( 'DC-secret', $this->_last_response );
	}

	/**
	 * Polling is for managers with a nonce too.
	 */
	public function test_device_poll_requires_capability() {
		$this->act_as( 'editor' );
		$this->assertFalse( $this->call( 'ecomweb_device_poll' )['success'] );
		$this->assertSame( array(), $this->fake->calls() );
	}
}
