<?php
/**
 * Translated text for the API's warning and error codes, and for the plugin's own error codes.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Messages
 */
class Messages {

	/**
	 * The text for a warning. An unknown code shows the API's own message.
	 *
	 * @param array $w     `{code, message}`.
	 * @param array $issue The issue the warning belongs to (for `regular_price`).
	 * @return string
	 */
	public static function warning( array $w, array $issue ): string {
		$code = (string) ( $w['code'] ?? '' );
		switch ( $code ) {
			case 'ON_SALE_REGULAR_PRICE_NOT_COPIED':
				$price = $issue['regular_price'] ?? null;
				if ( null === $price || '' === $price ) {
					return __( 'On sale in WooCommerce; the regular price was not copied', 'ecomweb-for-woocommerce' );
				}
				/* translators: %s: the regular price. */
				return sprintf( __( 'On sale in WooCommerce; regular price %s was not copied', 'ecomweb-for-woocommerce' ), wp_strip_all_tags( wc_price( (float) $price ) ) );
			case 'IMAGE_FAILED':
				return __( 'An image could not be copied.', 'ecomweb-for-woocommerce' );
			case 'IMAGES_TRUNCATED':
				return __( 'Only the first 20 images were copied.', 'ecomweb-for-woocommerce' );
			case 'NEEDS_CATEGORY':
				return __( 'Choose a product category for it in EcomWeb.', 'ecomweb-for-woocommerce' );
			case 'SLUG_SUFFIXED':
				return __( 'Its URL slug was taken in EcomWeb, so a suffix was added.', 'ecomweb-for-woocommerce' );
			case 'CONTENT_REMOVED':
				return __( 'Some description markup (scripts, styles, forms or embeds) was removed.', 'ecomweb-for-woocommerce' );
			case 'VARIANT_DEACTIVATED':
				return __( 'A variation removed in WooCommerce was set to inactive in EcomWeb.', 'ecomweb-for-woocommerce' );
		}
		return (string) ( $w['message'] ?? '' );
	}

	/**
	 * The text for an error. An unknown code shows the message it came with.
	 *
	 * @param array $e `{code, message}`.
	 * @return string
	 */
	public static function error( array $e ): string {
		$code    = (string) ( $e['code'] ?? '' );
		$message = (string) ( $e['message'] ?? '' );
		switch ( $code ) {
			case 'UNSUPPORTED_PRODUCT_TYPE':
				return __( "This product can't be copied: grouped and external products, and variable products without variations, aren't supported.", 'ecomweb-for-woocommerce' );
			case 'TOO_MANY_VARIANT_AXES':
				return __( 'EcomWeb supports up to 3 variation attributes; this product has more.', 'ecomweb-for-woocommerce' );
			case 'TOO_MANY_VARIANTS':
				return __( 'Too many variations for EcomWeb once “Any” values are expanded.', 'ecomweb-for-woocommerce' );
			case 'ANY_ATTRIBUTE_WITHOUT_VALUES':
				return __( 'A variation uses “Any” for an attribute that has no values.', 'ecomweb-for-woocommerce' );
			case 'INTERNAL':
				return __( "EcomWeb couldn't copy this product. Try again later.", 'ecomweb-for-woocommerce' );
			case 'BATCH_REJECTED':
				/* translators: %s: the reason ecomweb gave. */
				return sprintf( __( 'EcomWeb rejected this batch: %s', 'ecomweb-for-woocommerce' ), $message );
			case 'PLAN_LIMIT_EXCEEDED':
				return __( "Your EcomWeb plan's product limit was reached. Upgrade, then copy again.", 'ecomweb-for-woocommerce' );
			case 'WOOCOMMERCE_CURRENCY_MISMATCH':
				return __( 'The store currency no longer matches WooCommerce.', 'ecomweb-for-woocommerce' );
			case 'WOOCOMMERCE_CONNECTION_NOT_FOUND':
				return __( "This site's connection was removed in EcomWeb. Choose the store again.", 'ecomweb-for-woocommerce' );
			case 'SIGNED_OUT':
				return __( 'Signed out of EcomWeb. Sign in again.', 'ecomweb-for-woocommerce' );
			case 'NO_ACCESS':
				return __( 'You no longer have access to this store in EcomWeb.', 'ecomweb-for-woocommerce' );
			case 'API_UNREACHABLE':
				return __( "EcomWeb can't be reached right now. Copy again later; products already copied are skipped.", 'ecomweb-for-woocommerce' );
			case 'JOB_TIMEOUT':
				return __( 'EcomWeb took too long to answer. Copy again later; products already copied are skipped.', 'ecomweb-for-woocommerce' );
			case 'COPY_NOT_STARTED':
				return __( "The copy couldn't be scheduled on this site. Try again.", 'ecomweb-for-woocommerce' );
			case 'COPY_INTERRUPTED':
				return __( 'The copy stopped unexpectedly on this site. Copy again; products already copied are skipped.', 'ecomweb-for-woocommerce' );
			case 'WOOCOMMERCE_COPY_IN_PROGRESS':
				return __( 'EcomWeb is still busy with another copy of this site. Copy again later; products already copied are skipped.', 'ecomweb-for-woocommerce' );
			case 'WOOCOMMERCE_RATE_LIMITED':
				return __( 'EcomWeb is limiting requests right now. Copy again later; products already copied are skipped.', 'ecomweb-for-woocommerce' );
		}
		return '' !== $message ? $message : __( 'Something went wrong. Try again.', 'ecomweb-for-woocommerce' );
	}

	/**
	 * The texts for an issue's warnings, each shown once (the API sends one ON_SALE per variation).
	 * A warning with an unknown code and no message has no text and is left out.
	 *
	 * @param array $issue Issue with `warnings` and `regular_price`.
	 * @return string[]
	 */
	public static function warnings( array $issue ): array {
		$out = array();
		foreach ( (array) ( $issue['warnings'] ?? array() ) as $warning ) {
			$text = is_array( $warning ) ? self::warning( $warning, $issue ) : '';
			if ( '' !== trim( $text ) ) {
				$out[] = $text;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
