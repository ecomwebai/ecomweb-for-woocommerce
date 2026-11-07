<?php
/**
 * What this site knows about a product's copy in ecomweb: post meta only.
 *
 * Writes use update_post_meta, never $product->save(), so other plugins' save hooks don't fire.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Meta
 */
class Product_Meta {

	const PRODUCT_ID    = '_ecomweb_product_id';
	const CONNECTION_ID = '_ecomweb_connection_id';
	const PRODUCT_URL   = '_ecomweb_product_url';
	const COPIED_AT     = '_ecomweb_copied_at';
	const VARIANT_IDS   = '_ecomweb_variant_ids';

	/**
	 * Records one CopyResult.
	 *
	 * Created/updated write every key and the variations' ecomweb IDs. Skipped fills in what is
	 * missing or belongs to another connection, and keeps an existing copied_at. Failed writes nothing.
	 *
	 * @param array  $result        CopyResult.
	 * @param string $connection_id Connection the copy ran on.
	 * @return void
	 */
	public static function apply_result( array $result, string $connection_id ): void {
		$product_id = isset( $result['wc_product_id'] ) ? (int) $result['wc_product_id'] : 0;
		$ecomweb_id = $result['ecomweb_product_id'] ?? null;
		$url        = isset( $result['url'] ) && is_string( $result['url'] ) ? $result['url'] : null;
		$kind       = $result['result'] ?? null;
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) || ! is_string( $ecomweb_id ) || '' === $ecomweb_id ) {
			return;
		}

		if ( 'created' === $kind || 'updated' === $kind ) {
			update_post_meta( $product_id, self::PRODUCT_ID, $ecomweb_id );
			update_post_meta( $product_id, self::CONNECTION_ID, $connection_id );
			self::set_url( $product_id, $url );
			update_post_meta( $product_id, self::COPIED_AT, time() );
			self::apply_variants( $product_id, isset( $result['variants'] ) && is_array( $result['variants'] ) ? $result['variants'] : array() );
			return;
		}

		if ( 'skipped' === $kind ) {
			// Variation meta is left alone: skipped results carry no variants (X7).
			$other = get_post_meta( $product_id, self::CONNECTION_ID, true ) !== $connection_id;
			if ( $other || '' === (string) get_post_meta( $product_id, self::PRODUCT_ID, true ) ) {
				update_post_meta( $product_id, self::PRODUCT_ID, $ecomweb_id );
			}
			if ( $other || '' === (string) get_post_meta( $product_id, self::PRODUCT_URL, true ) ) {
				self::set_url( $product_id, $url );
			}
			update_post_meta( $product_id, self::CONNECTION_ID, $connection_id );
			if ( '' === (string) get_post_meta( $product_id, self::COPIED_AT, true ) ) {
				update_post_meta( $product_id, self::COPIED_AT, time() );
			}
		}
	}

	/**
	 * Deletes the product's keys and its variations' ecomweb IDs.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function forget( int $product_id ): void {
		foreach ( array( self::PRODUCT_ID, self::CONNECTION_ID, self::PRODUCT_URL, self::COPIED_AT, self::VARIANT_IDS ) as $key ) {
			delete_post_meta( $product_id, $key );
		}
		foreach ( self::variation_ids( $product_id ) as $variation_id ) {
			delete_post_meta( $variation_id, self::VARIANT_IDS );
		}
	}

	/**
	 * The product's copy in ecomweb through this connection.
	 *
	 * @param int    $product_id    Product ID.
	 * @param string $connection_id Connection ID.
	 * @return array|null `{id, url, copied_at}`, or null when not copied through this connection.
	 */
	public static function copied( int $product_id, string $connection_id ): ?array {
		$id = (string) get_post_meta( $product_id, self::PRODUCT_ID, true );
		if ( '' === $id || get_post_meta( $product_id, self::CONNECTION_ID, true ) !== $connection_id ) {
			return null;
		}
		$url       = (string) get_post_meta( $product_id, self::PRODUCT_URL, true );
		$copied_at = (int) get_post_meta( $product_id, self::COPIED_AT, true );
		return array(
			'id'        => $id,
			'url'       => '' === $url ? null : $url,
			'copied_at' => $copied_at > 0 ? $copied_at : null,
		);
	}

	/**
	 * Saves the product URL, or removes it when the result has none.
	 *
	 * @param int         $product_id Product ID.
	 * @param string|null $url        URL.
	 * @return void
	 */
	private static function set_url( int $product_id, ?string $url ): void {
		if ( null === $url || '' === $url ) {
			delete_post_meta( $product_id, self::PRODUCT_URL );
			return;
		}
		update_post_meta( $product_id, self::PRODUCT_URL, esc_url_raw( $url ) );
	}

	/**
	 * Writes `_ecomweb_variant_ids` on each listed variation of this product: every ecomweb
	 * variant ID whose wc_variation_id matches ("any" variations map to several). A simple
	 * product's single variant is listed under the product's own ID.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $variants   `{wc_variation_id, ecomweb_variant_id}` items.
	 * @return void
	 */
	private static function apply_variants( int $product_id, array $variants ): void {
		$by_variation = array();
		foreach ( $variants as $variant ) {
			$wc_id = isset( $variant['wc_variation_id'] ) ? (int) $variant['wc_variation_id'] : 0;
			$ecom  = $variant['ecomweb_variant_id'] ?? null;
			if ( $wc_id > 0 && is_string( $ecom ) && '' !== $ecom ) {
				$by_variation[ $wc_id ][] = $ecom;
			}
		}
		if ( empty( $by_variation ) ) {
			return;
		}

		$own = array_merge( array( $product_id ), self::variation_ids( $product_id ) );
		foreach ( $by_variation as $wc_id => $ids ) {
			if ( in_array( $wc_id, $own, true ) ) {
				update_post_meta( $wc_id, self::VARIANT_IDS, wp_slash( (string) wp_json_encode( array_values( array_unique( $ids ) ) ) ) );
			}
		}
	}

	/**
	 * IDs of the product's variations, in any status.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	private static function variation_ids( int $product_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return array_map( 'intval', $ids );
	}
}
