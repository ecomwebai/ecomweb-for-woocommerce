<?php
/**
 * Endpoint configuration. Constants only: no filter, option or setting can change a URL.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Config
 */
class Config {

	const DEFAULT_API_URL       = 'https://apibase.ecomweb.app/api/v1';
	const DEFAULT_DASHBOARD_URL = 'https://ecomweb.app';

	/**
	 * API base URL, without a trailing slash.
	 *
	 * @return string
	 */
	public static function api_url(): string {
		return untrailingslashit( defined( 'ECOMWEB_API_URL' ) ? (string) ECOMWEB_API_URL : self::DEFAULT_API_URL );
	}

	/**
	 * Dashboard base URL, without a trailing slash.
	 *
	 * @return string
	 */
	public static function dashboard_url(): string {
		return untrailingslashit( defined( 'ECOMWEB_DASHBOARD_URL' ) ? (string) ECOMWEB_DASHBOARD_URL : self::DEFAULT_DASHBOARD_URL );
	}

	/**
	 * The help guide for this plugin, in Vietnamese for a Vietnamese admin and English otherwise.
	 *
	 * @return string
	 */
	public static function help_url(): string {
		$lang = 0 === strpos( get_user_locale(), 'vi' ) ? 'vi' : 'en';
		return 'https://help.ecomweb.app/' . $lang . '/migrate-to-ecomweb/import-from-woocommerce';
	}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public static function version(): string {
		return ECOMWEB_WOO_VERSION;
	}
}
