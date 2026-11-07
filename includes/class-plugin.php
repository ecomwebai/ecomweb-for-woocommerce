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
	 * Shared connection.
	 *
	 * @var Connection|null
	 */
	private $connection = null;

	/**
	 * Shared HTTP wrapper.
	 *
	 * @var Http|null
	 */
	private $http = null;

	/**
	 * Shared Auth.
	 *
	 * @var Auth|null
	 */
	private $auth = null;

	/**
	 * Shared API client.
	 *
	 * @var Api_Client|null
	 */
	private $api = null;

	/**
	 * Shared copy runner.
	 *
	 * @var Copy_Runner|null
	 */
	private $runner = null;

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
	 * The one Auth: all ecomweb authentication.
	 *
	 * @return Auth
	 */
	public function auth(): Auth {
		if ( null === $this->auth ) {
			$this->auth = new Auth( $this->http(), new Session_Store(), new Lock() );
		}
		return $this->auth;
	}

	/**
	 * The authenticated API client.
	 *
	 * @return Api_Client
	 */
	public function api(): Api_Client {
		if ( null === $this->api ) {
			$this->api = new Api_Client( $this->auth(), $this->http() );
		}
		return $this->api;
	}

	/**
	 * The store connection.
	 *
	 * @return Connection
	 */
	public function connection(): Connection {
		if ( null === $this->connection ) {
			$this->connection = new Connection( $this->api() );
		}
		return $this->connection;
	}

	/**
	 * The copy runner.
	 *
	 * @return Copy_Runner
	 */
	public function runner(): Copy_Runner {
		if ( null === $this->runner ) {
			$this->runner = new Copy_Runner( $this->api(), $this->connection() );
		}
		return $this->runner;
	}

	/**
	 * Shared HTTP wrapper.
	 *
	 * @return Http
	 */
	private function http(): Http {
		if ( null === $this->http ) {
			$this->http = new Http();
		}
		return $this->http;
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

		( new Ajax( $this->auth() ) )->register();
		( new Admin_Page( $this->auth(), $this->connection() ) )->register();
		$this->connection()->listen();
		( new Probe_Route() )->register();
		$this->runner()->register();
		( new Products_List( $this->connection() ) )->register();
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
