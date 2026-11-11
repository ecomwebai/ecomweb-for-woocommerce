<?php
/**
 * Plugin Name:       EcomWeb for WooCommerce
 * Plugin URI:        https://help.ecomweb.app/en/migrate-to-ecomweb/import-from-woocommerce
 * Description:       Move your WooCommerce products to EcomWeb in a few clicks: images, prices, stock and variations, copied as drafts you can review.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 * Author:            EcomWeb
 * Author URI:        https://ecomweb.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ecomweb-for-woocommerce
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

define( 'ECOMWEB_WOO_VERSION', '1.0.0' );
define( 'ECOMWEB_WOO_FILE', __FILE__ );
define( 'ECOMWEB_WOO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Maps Ecomweb\Woo\Foo_Bar to includes/class-foo-bar.php.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
		$type = 'image-resolver' === $name ? 'interface' : 'class';
		$file = ECOMWEB_WOO_DIR . 'includes/' . $type . '-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::instance(), 'boot' ) );
