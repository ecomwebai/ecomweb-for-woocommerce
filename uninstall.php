<?php
/**
 * Removes the plugin's secrets and working state when it is deleted.
 *
 * Kept on purpose: the site UUID (`ecomweb_site_uuid`) and every `_ecomweb_*` post and attachment
 * meta, so a reinstall maps to the same ecomweb connection and does not duplicate products.
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Loads the autoloader. plugins_loaded has already fired, so nothing boots.
if ( ! defined( 'ECOMWEB_WOO_DIR' ) ) {
	require_once __DIR__ . '/ecomweb-for-woocommerce.php';
}

// Best effort: revoke the sign-in token (skipped by Auth when the site moved or there is no session).
\Ecomweb\Woo\Plugin::instance()->auth()->sign_out();

delete_option( 'ecomweb_session' );
delete_option( 'ecomweb_connection' );
delete_option( 'ecomweb_copy_state' );
delete_option( 'ecomweb_signed_out_reason' );

global $wpdb;
$ecomweb_patterns = array(
	$wpdb->esc_like( '_ecomweb_lock_' ) . '%',
	$wpdb->esc_like( '_transient_ecomweb_device_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_ecomweb_device_' ) . '%',
	$wpdb->esc_like( '_transient_ecomweb_selection_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_ecomweb_selection_' ) . '%',
	$wpdb->esc_like( '_transient_ecomweb_notice_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_ecomweb_notice_' ) . '%',
);
foreach ( $ecomweb_patterns as $ecomweb_pattern ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ecomweb_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic mutex / uninstall cleanup
}
wp_cache_flush();

// WooCommerce (and with it Action Scheduler) may already be deleted. Hook-only form: Action Scheduler 4.1.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( \Ecomweb\Woo\Copy_Runner::SEND_HOOK );
	as_unschedule_all_actions( \Ecomweb\Woo\Copy_Runner::POLL_HOOK );
}
