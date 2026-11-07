<?php
/**
 * Asks ecomweb what a copy of some products would do.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Preflight
 */
class Preflight {

	const CHUNK = 5000;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api        API client.
	 * @param Connection $connection Connection.
	 */
	public function __construct( Api_Client $api, Connection $connection ) {
		$this->api        = $api;
		$this->connection = $connection;
	}

	/**
	 * Runs preflight for the products, 5000 per call, and merges the answers.
	 *
	 * Also forgets the local copied-meta of products deleted in ecomweb. That GET-time write only mirrors what
	 * the API already says, so it needs no nonce (W36).
	 *
	 * @param int[] $ids WooCommerce product IDs.
	 * @return array|\WP_Error products, plan (limit, current, new_count), storage, currency, upgrade_url.
	 */
	public function run( array $ids ) {
		$current = $this->connection->ensure_current();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$connection = $this->connection->current();
		if ( null === $connection ) {
			return new \WP_Error( 'ecomweb_not_connected', __( 'Choose an EcomWeb store first.', 'ecomweb-for-woocommerce' ) );
		}

		$ids    = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$merged = array(
			'products'    => array(),
			'plan'        => array(
				'limit'     => -1,
				'current'   => 0,
				'new_count' => 0,
			),
			'storage'     => null,
			'currency'    => null,
			'upgrade_url' => null,
		);
		$path   = Api_Client::store_path( $connection['store']['ref'], '/integrations/woocommerce/preflight' );

		foreach ( array_chunk( $ids, self::CHUNK ) as $index => $chunk ) {
			$response = $this->api->post(
				$path,
				array(
					'connection_id'  => $connection['connection_id'],
					'wc_product_ids' => $chunk,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( 403 === $response->status ) {
				return new \WP_Error( 'ecomweb_no_access', __( "You don't have access to that store in EcomWeb.", 'ecomweb-for-woocommerce' ) );
			}
			if ( 429 === $response->status ) {
				return new \WP_Error( 'ecomweb_throttled', __( 'Checked too often. Try again in a minute.', 'ecomweb-for-woocommerce' ) );
			}
			if ( ! $response->ok() ) {
				return new \WP_Error( 'ecomweb_api', $response->error_message() );
			}
			$json = (array) $response->json;
			if ( ! isset( $json['products'], $json['plan']['limit'], $json['plan']['current'] ) || ! is_array( $json['products'] ) ) {
				return new \WP_Error( 'ecomweb_api', __( 'EcomWeb sent an answer this plugin does not understand.', 'ecomweb-for-woocommerce' ) );
			}

			if ( 0 === $index ) {
				$merged['plan']['limit']   = (int) $json['plan']['limit'];
				$merged['plan']['current'] = (int) $json['plan']['current'];
			}
			$merged['plan']['new_count'] += (int) ( $json['plan']['new_count'] ?? 0 );
			$merged['products']           = array_merge( $merged['products'], $json['products'] );
			foreach ( array( 'storage', 'currency', 'upgrade_url' ) as $key ) {
				if ( null === $merged[ $key ] && ! empty( $json[ $key ] ) ) {
					$merged[ $key ] = $json[ $key ];
				}
			}
		}

		foreach ( $merged['products'] as $product ) {
			if ( 'deleted_in_ecomweb' === ( $product['state'] ?? '' ) ) {
				Product_Meta::forget( (int) $product['wc_product_id'] );
			}
		}
		return $merged;
	}
}
