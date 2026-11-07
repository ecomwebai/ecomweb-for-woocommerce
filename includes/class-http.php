<?php
/**
 * The only place that makes HTTP requests: TLS verified, redirects off.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Http
 */
class Http {

	/**
	 * Sends a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Absolute URL.
	 * @param array  $opts   headers (array), json (array), body (string), timeout (int).
	 * @return Http_Response|\WP_Error
	 */
	public function request( string $method, string $url, array $opts = array() ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'ecomweb-for-woocommerce/' . Config::version() . '; ' . home_url(),
		);
		if ( ! empty( $opts['headers'] ) && is_array( $opts['headers'] ) ) {
			$headers = array_merge( $headers, $opts['headers'] );
		}

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 15,
			'sslverify'   => true,
			'redirection' => 0,
		);
		if ( isset( $opts['json'] ) ) {
			$headers['Content-Type'] = 'application/json';
			$args['body']            = wp_json_encode( $opts['json'] );
		} elseif ( isset( $opts['body'] ) ) {
			$args['body'] = (string) $opts['body'];
		}
		$args['headers'] = $headers;

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return Http_Response::from_wp( $response );
	}
}
