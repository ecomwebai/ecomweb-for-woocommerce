<?php
/**
 * The current or last copy's progress and results, in one non-autoloaded option.
 *
 * Shape: id, status (running|completed|failed|cancelled), connection_id, store_ref, overwrite,
 * started_at, finished_at, total, queue (int[] not yet sent), single (int[] of the queue to send
 * one per batch, after their batch was rejected with a 400), slots (int[] creates still holding one
 * of the plan's free slots), reserve (int[] creates waiting for a slot a failure frees), resend
 * (int[] of the queue to send again with overwrite, after the API reported a cached asset missing),
 * resent (int[] sent again already; at most once each),
 * in_flight (null | {job_id, product_ids, submitted_at, local_warnings, assets {wc_id: {asset_id:
 * attachment_id}}, resend (bool)}),
 * retry {kind: null|conflict|throttled|transient, count (of kind), tries {conflict, throttled,
 * transient}, total} (reset only by a successful step), counts {created, updated, skipped,
 * failed, not_supported}, issues {"<wc_id>": {name, result, url, regular_price, warnings, error}},
 * upload_failed (attachment IDs whose upload failed in this copy), error (null | {code, message}).
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Copy_State
 */
class Copy_State {

	const OPTION = 'ecomweb_copy_state';

	/**
	 * The saved state, read past the object cache (another process may have changed it).
	 *
	 * @return array|null
	 */
	public static function get(): ?array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$state = get_option( self::OPTION, false );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Saves the state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	public static function put( array $state ): void {
		// A stale cached copy equal to $state would make update_option skip the write.
		wp_cache_delete( self::OPTION, 'options' );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Deletes the saved state.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The state to show for a connection: a running copy always (so no second copy is offered
	 * while one runs), a finished one only when it was copied to that connection.
	 *
	 * @param string $connection_id Current connection ID.
	 * @return array|null
	 */
	public static function for_connection( string $connection_id ): ?array {
		$state = self::get();
		if ( null === $state || 'running' === ( $state['status'] ?? null ) ) {
			return $state;
		}
		return (string) ( $state['connection_id'] ?? '' ) === $connection_id ? $state : null;
	}

	/**
	 * Whether a copy is running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		$state = self::get();
		return null !== $state && 'running' === ( $state['status'] ?? null );
	}

	/**
	 * Products handled so far: created + updated + skipped + failed.
	 *
	 * @param array $state State.
	 * @return int
	 */
	public static function done( array $state ): int {
		$counts = (array) ( $state['counts'] ?? array() );
		$done   = 0;
		foreach ( array( 'created', 'updated', 'skipped', 'failed' ) as $key ) {
			$done += (int) ( $counts[ $key ] ?? 0 );
		}
		return $done;
	}
}
