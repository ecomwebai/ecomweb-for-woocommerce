<?php
/**
 * The current or last copy's progress and results, in one non-autoloaded option.
 *
 * Shape: id, status (running|completed|failed|cancelled), connection_id, store_ref, overwrite,
 * started_at, finished_at, total, queue (int[] not yet sent),
 * in_flight (null | {job_id, product_ids, submitted_at, local_warnings}),
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
	 * Whether a copy is running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		$state = self::get();
		return null !== $state && 'running' === ( $state['status'] ?? null );
	}
}
