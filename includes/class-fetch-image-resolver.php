<?php
/**
 * Fetch mode: ecomweb downloads each image from its public URL.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fetch_Image_Resolver
 */
class Fetch_Image_Resolver implements Image_Resolver {

	/**
	 * WooImage with the attachment's URL, or null when it is missing or not an image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $product_id    Product ID (unused in fetch mode).
	 * @return array|null
	 */
	public function resolve( int $attachment_id, int $product_id ): ?array {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}
		$src = wp_get_attachment_url( $attachment_id );
		if ( ! $src ) {
			return null;
		}
		return array(
			'attachment_id' => $attachment_id,
			'src'           => $src,
		);
	}

	/**
	 * Fetch mode records no warnings: ecomweb reports failed downloads itself.
	 *
	 * @param int $product_id Product ID.
	 * @return array[]
	 */
	public function warnings( int $product_id ): array {
		return array();
	}
}
