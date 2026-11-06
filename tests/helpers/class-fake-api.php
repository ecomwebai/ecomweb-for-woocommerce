<?php
/**
 * Fakes the ecomweb API through pre_http_request. Fails the test on any insecure or unmatched request.
 *
 * @package ecomweb-for-woocommerce
 */

use PHPUnit\Framework\Assert;

/**
 * Class Fake_Api
 */
class Fake_Api {

	/**
	 * Routes: "METHOD suffix" => list of responses.
	 *
	 * @var array
	 */
	private $routes = array();

	/**
	 * Per-route position in the queue.
	 *
	 * @var array
	 */
	private $positions = array();

	/**
	 * Recorded requests.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Filter callback.
	 *
	 * @var callable
	 */
	private $filter;

	/**
	 * Starts intercepting.
	 *
	 * @return Fake_Api
	 */
	public static function start(): Fake_Api {
		$fake         = new self();
		$fake->filter = array( $fake, 'intercept' );
		add_filter( 'pre_http_request', $fake->filter, 10, 3 );
		return $fake;
	}

	/**
	 * Stops intercepting.
	 */
	public function stop(): void {
		remove_filter( 'pre_http_request', $this->filter, 10 );
	}

	/**
	 * Queues a response for a route; the last one repeats.
	 *
	 * @param string $method      HTTP method.
	 * @param string $path_suffix URL path suffix.
	 * @param int    $status      Status.
	 * @param array  $json        JSON body.
	 * @param array  $headers     Response headers.
	 * @return Fake_Api
	 */
	public function on( string $method, string $path_suffix, int $status, array $json = array(), array $headers = array() ): Fake_Api {
		$this->routes[ $this->key( $method, $path_suffix ) ][] = array(
			'status'  => $status,
			'json'    => $json,
			'headers' => $headers,
		);
		return $this;
	}

	/**
	 * Queues a transport failure for a route.
	 *
	 * @param string $method        HTTP method.
	 * @param string $path_suffix   URL path suffix.
	 * @param string $wp_error_code WP_Error code.
	 * @return Fake_Api
	 */
	public function fail( string $method, string $path_suffix, string $wp_error_code ): Fake_Api {
		$this->routes[ $this->key( $method, $path_suffix ) ][] = array( 'error' => $wp_error_code );
		return $this;
	}

	/**
	 * Recorded requests, optionally only those whose path ends with the suffix.
	 *
	 * @param string|null $path_suffix Suffix filter.
	 * @return array Each has method, url, headers, body.
	 */
	public function calls( ?string $path_suffix = null ): array {
		if ( null === $path_suffix ) {
			return $this->calls;
		}
		return array_values(
			array_filter(
				$this->calls,
				function ( $call ) use ( $path_suffix ) {
					return $this->path_ends_with( $call['url'], $path_suffix );
				}
			)
		);
	}

	/**
	 * Number of recorded requests for a suffix.
	 *
	 * @param string $path_suffix URL path suffix.
	 * @return int
	 */
	public function count( string $path_suffix ): int {
		return count( $this->calls( $path_suffix ) );
	}

	/**
	 * The pre_http_request filter.
	 *
	 * @param mixed  $pre  Preempt value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array|WP_Error
	 */
	public function intercept( $pre, $args, $url ) {
		$method = strtoupper( $args['method'] ?? 'GET' );
		Assert::assertTrue( ( $args['sslverify'] ?? null ) === true, "Insecure request: sslverify must be true ($method $url)" );
		Assert::assertTrue( ( $args['redirection'] ?? null ) === 0, "Insecure request: redirection must be 0 ($method $url)" );

		$this->calls[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $args['headers'] ?? array(),
			'body'    => $args['body'] ?? null,
		);

		$best = null;
		foreach ( array_keys( $this->routes ) as $key ) {
			list( $route_method, $suffix ) = explode( ' ', $key, 2 );
			if ( $route_method === $method && $this->path_ends_with( $url, $suffix ) && ( null === $best || strlen( $key ) > strlen( $best ) ) ) {
				$best = $key;
			}
		}
		if ( null === $best ) {
			Assert::fail( "Unmatched request: $method $url" );
		}

		$pos      = $this->positions[ $best ] ?? 0;
		$queue    = $this->routes[ $best ];
		$response = $queue[ min( $pos, count( $queue ) - 1 ) ];

		$this->positions[ $best ] = $pos + 1;

		if ( isset( $response['error'] ) ) {
			return new WP_Error( $response['error'], 'Fake transport error' );
		}
		return array(
			'headers'  => $response['headers'],
			'body'     => wp_json_encode( $response['json'] ),
			'response' => array(
				'code'    => $response['status'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Route key.
	 *
	 * @param string $method HTTP method.
	 * @param string $suffix Path suffix.
	 * @return string
	 */
	private function key( string $method, string $suffix ): string {
		return strtoupper( $method ) . ' ' . $suffix;
	}

	/**
	 * Whether the URL's path (no query) ends with the suffix.
	 *
	 * @param string $url    URL.
	 * @param string $suffix Suffix.
	 * @return bool
	 */
	private function path_ends_with( string $url, string $suffix ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $suffix || substr( $path, -strlen( $suffix ) ) === $suffix;
	}
}
