<?php
/**
 * The products the merchant chose to copy, kept between the page and the confirm screen.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Selection
 */
class Selection {

	const KEY = 'ecomweb_selection_';
	const TTL = HOUR_IN_SECONDS;

	/**
	 * Saves a selection.
	 *
	 * @param int   $user_id       User.
	 * @param int[] $ids           Product IDs.
	 * @param bool  $explicit      True when the merchant picked the products (bulk action); false for "Copy all".
	 * @param int   $not_supported Products left out because their type can't be copied.
	 * @return void
	 */
	public static function save( int $user_id, array $ids, bool $explicit, int $not_supported ): void {
		set_transient(
			self::KEY . $user_id,
			array(
				'ids'           => array_values( array_unique( array_map( 'intval', $ids ) ) ),
				'explicit'      => $explicit,
				'not_supported' => max( 0, $not_supported ),
			),
			self::TTL
		);
	}

	/**
	 * The saved selection.
	 *
	 * @param int $user_id User.
	 * @return array|null Keys ids, explicit, not_supported.
	 */
	public static function get( int $user_id ): ?array {
		$value = get_transient( self::KEY . $user_id );
		if ( ! is_array( $value ) || ! isset( $value['ids'] ) || ! is_array( $value['ids'] ) ) {
			return null;
		}
		return array(
			'ids'           => array_map( 'intval', $value['ids'] ),
			'explicit'      => ! empty( $value['explicit'] ),
			'not_supported' => (int) ( $value['not_supported'] ?? 0 ),
		);
	}

	/**
	 * Forgets the selection.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function clear( int $user_id ): void {
		delete_transient( self::KEY . $user_id );
	}
}
