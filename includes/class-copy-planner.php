<?php
/**
 * Works out what a copy would do from a preflight answer. The only owner of the plan-cap formula.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Copy_Planner
 */
class Copy_Planner {

	/**
	 * Plans a copy.
	 *
	 * The cap comes from plan.limit and plan.current, not from the API's new_count or fits: the API counts
	 * deleted_in_ecomweb products as new, and "Copy all" leaves them out.
	 *
	 * @param array $preflight       Merged Preflight::run() result.
	 * @param bool  $overwrite       Also queue products already copied.
	 * @param bool  $include_deleted Also create products deleted in ecomweb.
	 * @param bool  $limit_to_fits   Cut the products to create to what the plan allows.
	 * @return array queue, slots, reserve, creates, fits, over_cap, overwrite_count, already_copied, deleted, currency_blocked.
	 */
	public static function plan( array $preflight, bool $overwrite, bool $include_deleted, bool $limit_to_fits ): array {
		$new     = array();
		$copied  = array();
		$deleted = array();
		foreach ( (array) ( $preflight['products'] ?? array() ) as $product ) {
			$id = (int) ( $product['wc_product_id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			switch ( $product['state'] ?? '' ) {
				case 'new':
					$new[] = $id;
					break;
				case 'copied':
					$copied[] = $id;
					break;
				case 'deleted_in_ecomweb':
					$deleted[] = $id;
					break;
			}
		}

		$creates = $include_deleted ? array_merge( $new, $deleted ) : $new;
		sort( $creates );
		$limit   = (int) ( $preflight['plan']['limit'] ?? -1 );
		$current = (int) ( $preflight['plan']['current'] ?? 0 );
		$fits    = -1 === $limit ? count( $creates ) : max( 0, min( count( $creates ), $limit - $current ) );

		// Limited to what fits, the queued creates hold the plan's free slots and the others wait
		// as the reserve: the runner gives a failed create's slot to the next one.
		$slots   = $limit_to_fits ? array_slice( $creates, 0, $fits ) : array();
		$reserve = $limit_to_fits ? array_slice( $creates, $fits ) : array();
		$queue   = $limit_to_fits ? $slots : $creates;
		if ( $overwrite ) {
			$queue = array_merge( $queue, $copied );
		}
		sort( $queue );

		return array(
			'queue'            => array_values( $queue ),
			'slots'            => array_values( $slots ),
			'reserve'          => array_values( $reserve ),
			'creates'          => count( $creates ),
			'fits'             => $fits,
			'over_cap'         => $fits < count( $creates ),
			'overwrite_count'  => $overwrite ? count( $copied ) : 0,
			'already_copied'   => count( $copied ),
			'deleted'          => count( $deleted ),
			'currency_blocked' => isset( $preflight['currency']['matches'] ) && ! $preflight['currency']['matches'],
		);
	}
}
