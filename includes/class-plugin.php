<?php
/**
 * Plugin bootstrap and shared objects.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	const MIN_WC_VERSION = '8.0';
	const UUID_OPTION    = 'ecomweb_site_uuid';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. The HPOS declaration is made even when requirements fail.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		if ( ! $this->requirements_met() ) {
			add_action( 'admin_notices', array( $this, 'requirements_notice' ) );
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Activation hook: make sure the site UUID exists.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::site_uuid();
	}

	/**
	 * Stable per-site UUID. Created on first use and never autoloaded.
	 *
	 * @return string
	 */
	public static function site_uuid(): string {
		$uuid = get_option( self::UUID_OPTION );
		if ( is_string( $uuid ) && wp_is_uuid( $uuid, 4 ) ) {
			return $uuid;
		}
		$uuid = wp_generate_uuid4();
		update_option( self::UUID_OPTION, $uuid, false );
		return $uuid;
	}

	/**
	 * Whether WooCommerce is active and recent enough.
	 *
	 * @return bool
	 */
	public function requirements_met(): bool {
		$version = apply_filters( 'ecomweb_woocommerce_version', defined( 'WC_VERSION' ) ? WC_VERSION : null );
		return is_string( $version ) && version_compare( $version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Admin notice shown when requirements are not met.
	 *
	 * @return void
	 */
	public function requirements_notice(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'ecomweb for WooCommerce needs WooCommerce 8.0 or later.', 'ecomweb-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Declare compatibility with High-Performance Order Storage.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ECOMWEB_WOO_FILE, true );
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ecomweb-for-woocommerce', false, dirname( plugin_basename( ECOMWEB_WOO_FILE ) ) . '/languages' );
	}
}
