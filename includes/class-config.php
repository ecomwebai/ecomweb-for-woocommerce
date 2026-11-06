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
	 * Plugin version.
	 *
	 * @return string
	 */
	public static function version(): string {
		return ECOMWEB_WOO_VERSION;
	}
}
