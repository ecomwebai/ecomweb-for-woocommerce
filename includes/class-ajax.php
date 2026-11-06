<?php
/**
 * AJAX handlers for the device sign-in. The device code never reaches the browser.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax
 */
class Ajax {

	const NONCE_ACTION = 'ecomweb_ajax';
	const CAPABILITY   = 'manage_woocommerce';

	/**
	 * Auth.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param Auth $auth Auth.
	 */
	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Registers the handlers. Logged-in users only: there are no nopriv handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_ecomweb_device_start', array( $this, 'device_start' ) );
		add_action( 'wp_ajax_ecomweb_device_poll', array( $this, 'device_poll' ) );
	}

	/**
	 * Starts a device sign-in for the current user.
	 *
	 * @return void
	 */
	public function device_start(): void {
		$this->guard();
		$result = $this->auth->start_device_flow( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}
		wp_send_json_success(
			array(
				'user_code'                 => $result['user_code'],
				'verification_uri_complete' => $result['verification_uri_complete'],
				'interval'                  => $result['interval'],
				'expires_in'                => $result['expires_in'],
			)
		);
	}

	/**
	 * Polls the current user's device sign-in.
	 *
	 * @return void
	 */
	public function device_poll(): void {
		$this->guard();
		$result = $this->auth->poll_device_flow( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}
		wp_send_json_success(
			array(
				'status'   => $result['status'],
				'interval' => $result['interval'],
			)
		);
	}

	/**
	 * Nonce first, then the capability. Either failure ends the request.
	 *
	 * @return void
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	/**
	 * Sends a WP_Error as a JSON error with its message.
	 *
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	private function fail( \WP_Error $error ): void {
		wp_send_json_error( array( 'message' => $error->get_error_message() ), 502 );
	}
}
