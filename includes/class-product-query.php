<?php
/**
 * Finds the products that can be copied.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Query
 */
class Product_Query {

	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * IDs of every simple or variable product outside the trash, ID ascending.
	 *
	 * @return int[]
	 */
	public static function all_supported_ids(): array {
		$ids = wc_get_products(
			array(
				'type'    => Product_Serializer::SUPPORTED_TYPES,
				'status'  => self::STATUSES,
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Splits IDs into supported and not supported, keeping their order. Missing IDs are dropped.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array{supported:int[], not_supported:int[]}
	 */
	public static function split( array $ids ): array {
		$result = array(
			'supported'     => array(),
			'not_supported' => array(),
		);
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$result[ Product_Serializer::supports( $product ) ? 'supported' : 'not_supported' ][] = $id;
		}
		return $result;
	}
}
