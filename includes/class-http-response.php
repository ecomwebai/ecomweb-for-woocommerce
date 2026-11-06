<?php
/**
 * A parsed HTTP response.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Http_Response
 */
class Http_Response {

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Response headers, lower-cased names.
	 *
	 * @var array
	 */
	public $headers;

	/**
	 * Decoded JSON object or array body, or null.
	 *
	 * @var array|null
	 */
	public $json;

	/**
	 * Constructor.
	 *
	 * @param int        $status  Status code.
	 * @param array      $headers Headers.
	 * @param array|null $json    Decoded body.
	 */
	public function __construct( int $status, array $headers, ?array $json ) {
		$this->status  = $status;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->json    = $json;
	}

	/**
	 * Builds a response from a wp_remote_request() result.
	 *
	 * @param array $response Result of wp_remote_request().
	 * @return Http_Response
	 */
	public static function from_wp( array $response ): Http_Response {
		$raw     = wp_remote_retrieve_headers( $response );
		$headers = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		$json    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return new self(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers,
			is_array( $json ) ? $json : null
		);
	}

	/**
	 * Whether the status is 2xx.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * The API error code (json.code), if it is a string.
	 *
	 * @return string|null
	 */
	public function error_code(): ?string {
		return isset( $this->json['code'] ) && is_string( $this->json['code'] ) ? $this->json['code'] : null;
	}

	/**
	 * Human-readable error: json.message (arrays joined with "; "), json.error, or "HTTP <status>".
	 *
	 * @return string
	 */
	public function error_message(): string {
		foreach ( array( 'message', 'error' ) as $key ) {
			$value = $this->json[ $key ] ?? null;
			if ( is_array( $value ) ) {
				$value = implode( '; ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			}
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return 'HTTP ' . $this->status;
	}

	/**
	 * A header value, case-insensitive.
	 *
	 * @param string $name Header name.
	 * @return string|null
	 */
	public function header( string $name ): ?string {
		$value = $this->headers[ strtolower( $name ) ] ?? null;
		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}
		return null === $value ? null : (string) $value;
	}
}
