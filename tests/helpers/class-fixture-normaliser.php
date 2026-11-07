<?php
/**
 * Renumbers a serialised product so its golden file is stable across runs and sites.
 *
 * @package ecomweb-for-woocommerce
 */

/**
 * Class Fixture_Normaliser
 */
class Fixture_Normaliser {

	const SRC_BASE = 'https://shop.example/wp-content/uploads/';

	/**
	 * Renumbers IDs by first appearance and rewrites image URLs.
	 *
	 * Product → P, variations → P*100+n, attachments → P*100+50+n,
	 * categories → 10+n (parents remapped, 0 stays 0), tags → 20+n.
	 *
	 * @param array $payload              Serialised WooProduct.
	 * @param int   $canonical_product_id P.
	 * @return array
	 */
	public static function normalise( array $payload, $canonical_product_id ) {
		$p           = (int) $canonical_product_id;
		$attachments = array();
		$image       = function ( $img ) use ( &$attachments, $p ) {
			if ( null === $img ) {
				return null;
			}
			$id = $img['attachment_id'];
			if ( ! isset( $attachments[ $id ] ) ) {
				$attachments[ $id ] = $p * 100 + 50 + count( $attachments ) + 1;
			}
			$img['attachment_id'] = $attachments[ $id ];
			if ( isset( $img['src'] ) ) {
				$img['src'] = self::SRC_BASE . self::basename( $img['src'] );
			}
			return $img;
		};

		$payload['id'] = $p;

		$categories = array();
		foreach ( $payload['categories'] as $category ) {
			$categories[ $category['id'] ] = 10 + count( $categories ) + 1;
		}
		foreach ( $payload['categories'] as $i => $category ) {
			$parent = $category['parent'];
			if ( 0 !== $parent && ! isset( $categories[ $parent ] ) ) {
				$categories[ $parent ] = 10 + count( $categories ) + 1;
			}
			$payload['categories'][ $i ]['id']     = $categories[ $category['id'] ];
			$payload['categories'][ $i ]['parent'] = 0 === $parent ? 0 : $categories[ $parent ];
		}

		foreach ( $payload['tags'] as $i => $tag ) {
			$payload['tags'][ $i ]['id'] = 20 + $i + 1;
		}

		foreach ( $payload['images'] as $i => $img ) {
			$payload['images'][ $i ] = $image( $img );
		}

		foreach ( $payload['variations'] as $i => $variation ) {
			$payload['variations'][ $i ]['id']    = $p * 100 + $i + 1;
			$payload['variations'][ $i ]['image'] = $image( $variation['image'] );
		}

		return $payload;
	}

	/**
	 * The canonical, byte-exact fixture encoding.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	public static function encode( array $payload ) {
		return wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/**
	 * File name of a URL, without a WP uniqueness suffix (-1, -2 …) before the extension.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function basename( $url ) {
		$name = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return (string) preg_replace( '/-\d+(\.[^.]+)$/', '$1', $name );
	}
}
