<?php
/**
 * Options-table mutex. Talks to the database directly: the options API and the object cache both race.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lock
 */
class Lock {

	const PREFIX = '_ecomweb_lock_';

	/**
	 * Take the named lock, waiting up to $timeout_seconds.
	 *
	 * @param string $name            Lock name.
	 * @param int    $ttl_seconds     Seconds after which a holder is presumed crashed.
	 * @param float  $timeout_seconds Longest wait.
	 * @return string|null Owner token, or null on timeout.
	 */
	public static function acquire( string $name, int $ttl_seconds, float $timeout_seconds ): ?string {
		global $wpdb;

		$option   = self::PREFIX . $name;
		$token    = wp_generate_password( 32, false );
		$deadline = microtime( true ) + $timeout_seconds;

		while ( true ) {
			$value = $token . '|' . ( time() + $ttl_seconds );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic mutex / uninstall cleanup
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $value ) );
			if ( 1 === (int) $inserted ) {
				return $token;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic mutex / uninstall cleanup
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			if ( null !== $current && self::is_expired( (string) $current ) ) {
				// Compare-and-delete: only the exact stale value is removed, so a fresh holder is safe.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic mutex / uninstall cleanup
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $current ) );
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				return null;
			}

			do_action( 'ecomweb_lock_wait', $name );
			usleep( 250000 );
		}
	}

	/**
	 * Release the lock, only if $token owns it.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Token returned by acquire().
	 * @return void
	 */
	public static function release( string $name, string $token ): void {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic mutex / uninstall cleanup
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::PREFIX . $name, $wpdb->esc_like( $token . '|' ) . '%' ) );
	}

	/**
	 * Whether a stored "<token>|<expires>" value is past its expiry. Malformed values count as expired.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	private static function is_expired( string $value ): bool {
		$parts = explode( '|', $value );
		return (int) end( $parts ) <= time();
	}
}
