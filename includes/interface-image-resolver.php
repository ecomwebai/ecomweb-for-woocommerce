<?php
/**
 * Turns an attachment into a WooImage for the payload.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Image_Resolver
 */
interface Image_Resolver {

	/**
	 * WooImage for an attachment, or null when the image can't be used.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $product_id    Product the image belongs to (for warnings).
	 * @return array|null `{attachment_id, src}` (fetch) or `{attachment_id, asset_id}` (upload).
	 */
	public function resolve( int $attachment_id, int $product_id ): ?array;

	/**
	 * Local warnings recorded while resolving a product's images.
	 *
	 * @param int $product_id Product ID.
	 * @return array[] `{code, message}` items.
	 */
	public function warnings( int $product_id ): array;
}
