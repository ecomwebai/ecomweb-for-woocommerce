<?php
/**
 * Authenticated calls to the ecomweb API: bearer token, one forced refresh and retry on 401.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Api_Client
 */
class Api_Client {

	const TIMEOUT        = 15;
	const UPLOAD_TIMEOUT = 60;
	const FILE_FIELD     = 'file';

	/**
	 * Auth.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * HTTP wrapper.
	 *
	 * @var Http
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param Auth $auth Auth.
	 * @param Http $http HTTP wrapper.
	 */
	public function __construct( Auth $auth, Http $http ) {
		$this->auth = $auth;
		$this->http = $http;
	}

	/**
	 * Path under a store.
	 *
	 * @param string $store_ref Store ref.
	 * @param string $suffix    Path after the store, starting with "/".
	 * @return string
	 */
	public static function store_path( string $store_ref, string $suffix ): string {
		return '/stores/' . rawurlencode( $store_ref ) . $suffix;
	}

	/**
	 * GET.
	 *
	 * @param string $path    API path.
	 * @param array  $query   Query arguments.
	 * @param int    $timeout Seconds.
	 * @return Http_Response|\WP_Error
	 */
	public function get( string $path, array $query = array(), int $timeout = self::TIMEOUT ) {
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		return $this->send( 'GET', $path, array( 'timeout' => $timeout ) );
	}

	/**
	 * POST a JSON body.
	 *
	 * @param string $path    API path.
	 * @param array  $body    JSON body.
	 * @param int    $timeout Seconds.
	 * @return Http_Response|\WP_Error
	 */
	public function post( string $path, array $body, int $timeout = self::TIMEOUT ) {
		return $this->send(
			'POST',
			$path,
			array(
				'json'    => $body,
				'timeout' => $timeout,
			)
		);
	}

	/**
	 * POST a file as multipart/form-data, in the field "file".
	 *
	 * @param string $path      API path.
	 * @param array  $fields    Text fields.
	 * @param string $file_path Local file.
	 * @param string $filename  File name sent to the server.
	 * @param string $mime      MIME type.
	 * @return Http_Response|\WP_Error
	 */
	public function upload( string $path, array $fields, string $file_path, string $filename, string $mime ) {
		$bytes = is_readable( $file_path ) ? file_get_contents( $file_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local attachment file.
		if ( false === $bytes ) {
			return new \WP_Error( 'ecomweb_file_unreadable', __( 'The image file could not be read.', 'ecomweb-for-woocommerce' ) );
		}

		list( $body, $content_type ) = Multipart::build( $fields, self::FILE_FIELD, $filename, $mime, $bytes );
		return $this->send(
			'POST',
			$path,
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => $content_type ),
				'timeout' => self::UPLOAD_TIMEOUT,
			)
		);
	}

	/**
	 * Sends with the bearer token. A 401 forces one refresh and one retry; a second 401 signs out.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path, with any query.
	 * @param array  $opts   Http::request options.
	 * @return Http_Response|\WP_Error
	 */
	private function send( string $method, string $path, array $opts ) {
		$token = $this->auth->access_token();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$attempt_opts            = $opts;
			$attempt_opts['headers'] = array_merge( $opts['headers'] ?? array(), array( 'Authorization' => 'Bearer ' . $token ) );

			$response = $this->http->request( $method, Config::api_url() . $path, $attempt_opts );
			if ( is_wp_error( $response ) ) {
				return new \WP_Error(
					'ecomweb_unreachable',
					__( 'Could not reach ecomweb. Try again in a moment.', 'ecomweb-for-woocommerce' ),
					array( 'cause' => $response->get_error_code() )
				);
			}
			if ( 401 !== $response->status ) {
				return $response;
			}
			if ( 0 === $attempt ) {
				$token = $this->auth->access_token( true );
			}
		}

		$this->auth->sign_out_local( 'revoked' );
		return new \WP_Error( 'ecomweb_signed_out', __( 'You were signed out of ecomweb. Sign in again.', 'ecomweb-for-woocommerce' ), array( 'reason' => 'revoked' ) );
	}
}
