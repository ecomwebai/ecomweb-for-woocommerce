<?php
/**
 * The link between this site and one ecomweb store: store list, registration, image probe.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connection
 */
class Connection {

	const OPTION = Auth::CONNECTION_OPTION;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api API client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Hooks the re-registration after a sign-in.
	 *
	 * @return void
	 */
	public function listen(): void {
		add_action( 'ecomweb_signed_in', array( $this, 'on_signed_in' ) );
	}

	/**
	 * The merchant's stores.
	 *
	 * @return array|\WP_Error List of ref, name, role, can_copy, reason.
	 */
	public function list_stores() {
		$response = $this->api->get( '/stores', array( 'limit' => 100 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response->ok() ) {
			return new \WP_Error( 'ecomweb_api', $response->error_message() );
		}

		$items = (array) $response->json;
		if ( isset( $items['data'] ) && is_array( $items['data'] ) ) {
			$items = $items['data'];
		}

		$stores = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$ref = $item['ref'] ?? ( $item['id'] ?? null );
			if ( ! is_scalar( $ref ) || '' === (string) $ref ) {
				continue;
			}
			$role     = isset( $item['role'] ) && is_string( $item['role'] ) ? $item['role'] : null;
			$can_copy = null === $role || in_array( strtolower( $role ), array( 'owner', 'admin' ), true );

			$stores[] = array(
				'ref'      => (string) $ref,
				'name'     => isset( $item['name'] ) && is_string( $item['name'] ) ? $item['name'] : (string) $ref,
				'role'     => $role,
				'can_copy' => $can_copy,
				'reason'   => $can_copy ? null : __( 'Only the store owner or an admin can copy products', 'ecomweb-for-woocommerce' ),
			);
		}
		return $stores;
	}

	/**
	 * What the API is told about this site.
	 *
	 * @return array
	 */
	public function registration_payload(): array {
		return array(
			'site_uuid'            => Plugin::site_uuid(),
			'site_url'             => Auth::home(),
			'woocommerce_currency' => get_woocommerce_currency(),
			'weight_unit'          => (string) get_option( 'woocommerce_weight_unit', 'kg' ),
			'plugin_version'       => Config::version(),
		);
	}

	/**
	 * Registers this site with a store and saves the connection.
	 *
	 * @param string $store_ref Store ref.
	 * @return array|\WP_Error The saved connection.
	 */
	public function register( string $store_ref ) {
		$payload  = $this->registration_payload();
		$response = $this->api->post( Api_Client::store_path( $store_ref, '/integrations/woocommerce/connections' ), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 403 === $response->status ) {
			return new \WP_Error( 'ecomweb_no_access', __( "You don't have access to that store in EcomWeb.", 'ecomweb-for-woocommerce' ) );
		}
		if ( ! $response->ok() ) {
			return new \WP_Error( 'ecomweb_api', $response->error_message() );
		}

		$json  = (array) $response->json;
		$store = isset( $json['store'] ) && is_array( $json['store'] ) ? $json['store'] : array();
		$id    = $json['connection_id'] ?? null;
		$mode  = $json['images_mode'] ?? null;
		if ( ! is_string( $id ) || '' === $id || ! in_array( $mode, array( 'fetch', 'upload' ), true ) ) {
			return new \WP_Error( 'ecomweb_api', __( 'EcomWeb sent an answer this plugin does not understand.', 'ecomweb-for-woocommerce' ) );
		}

		$connection = array(
			'connection_id' => $id,
			'images_mode'   => $mode,
			'store'         => array(
				'id'            => (string) ( $store['id'] ?? $store_ref ),
				'ref'           => (string) ( $store['ref'] ?? $store_ref ),
				'name'          => (string) ( $store['name'] ?? $store_ref ),
				'currency'      => (string) ( $store['currency'] ?? '' ),
				'dashboard_url' => (string) ( $store['dashboard_url'] ?? '' ),
			),
			'payload_hash'  => self::hash( $payload ),
		);
		update_option( self::OPTION, $connection, false );
		return $connection;
	}

	/**
	 * The saved connection.
	 *
	 * @return array|null
	 */
	public function current(): ?array {
		$connection = get_option( self::OPTION, false );
		return is_array( $connection ) && ! empty( $connection['connection_id'] ) && ! empty( $connection['store']['ref'] ) ? $connection : null;
	}

	/**
	 * Drops the saved connection. Copied-product meta is kept.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Re-registers the saved connection when the site details sent last time are out of date (W22).
	 *
	 * Run before every preflight: a changed WooCommerce currency or weight unit must reach the API.
	 *
	 * @return true|\WP_Error True when there is nothing to do or the update worked.
	 */
	public function ensure_current() {
		$connection = $this->current();
		if ( null === $connection || ( $connection['payload_hash'] ?? '' ) === self::hash( $this->registration_payload() ) ) {
			return true;
		}
		$result = $this->reregister( $connection );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Runs the image probe again.
	 *
	 * @return string|\WP_Error The new images mode.
	 */
	public function recheck_images() {
		$connection = $this->current();
		if ( null === $connection ) {
			return new \WP_Error( 'ecomweb_not_connected', __( 'Choose an EcomWeb store first.', 'ecomweb-for-woocommerce' ) );
		}

		$path     = Api_Client::store_path( $connection['store']['ref'], '/integrations/woocommerce/connections/' . rawurlencode( $connection['connection_id'] ) . '/probe' );
		$response = $this->api->post( $path, array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 429 === $response->status ) {
			return new \WP_Error( 'ecomweb_throttled', __( 'Checked too often. Try again in a minute.', 'ecomweb-for-woocommerce' ) );
		}
		if ( 403 === $response->status ) {
			return new \WP_Error( 'ecomweb_no_access', __( "You don't have access to that store in EcomWeb.", 'ecomweb-for-woocommerce' ) );
		}
		$mode = $response->json['images_mode'] ?? null;
		if ( ! $response->ok() || ! in_array( $mode, array( 'fetch', 'upload' ), true ) ) {
			return new \WP_Error( 'ecomweb_api', $response->ok() ? __( 'EcomWeb sent an answer this plugin does not understand.', 'ecomweb-for-woocommerce' ) : $response->error_message() );
		}

		$connection['images_mode'] = $mode;
		update_option( self::OPTION, $connection, false );
		return $mode;
	}

	/**
	 * After a sign-in: tells the API the current site address again (it may have moved).
	 *
	 * @return void
	 */
	public function on_signed_in(): void {
		$connection = $this->current();
		if ( null !== $connection ) {
			$this->reregister( $connection );
		}
	}

	/**
	 * Registers the saved store again. A 403 forgets the connection and leaves a notice.
	 *
	 * @param array $connection Saved connection.
	 * @return array|\WP_Error
	 */
	private function reregister( array $connection ) {
		$result = $this->register( $connection['store']['ref'] );
		if ( is_wp_error( $result ) && 'ecomweb_no_access' === $result->get_error_code() ) {
			$this->forget();
			Admin_Page::flash( 'lost_access', array( $connection['store']['name'] ?? $connection['store']['ref'] ) );
		}
		return $result;
	}

	/**
	 * Hash of the registration payload.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private static function hash( array $payload ): string {
		return md5( (string) wp_json_encode( $payload ) );
	}
}
