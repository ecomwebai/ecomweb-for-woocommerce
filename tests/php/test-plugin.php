<?php
/**
 * Plugin bootstrap tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Copy_Runner;
use Ecomweb\Woo\Copy_State;
use Ecomweb\Woo\Plugin;

/**
 * Class Test_Plugin
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Activation creates site uuid not autoloaded.
	 */
	public function test_activation_creates_site_uuid_not_autoloaded() {
		global $wpdb;
		delete_option( 'ecomweb_site_uuid' );
		Plugin::activate();
		$uuid = get_option( 'ecomweb_site_uuid' );
		$this->assertTrue( wp_is_uuid( $uuid, 4 ) );
		$autoload = $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name='ecomweb_site_uuid'" ); // phpcs:ignore WordPress.DB
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * Activation keeps existing site uuid.
	 */
	public function test_activation_keeps_existing_site_uuid() {
		$uuid = wp_generate_uuid4();
		update_option( 'ecomweb_site_uuid', $uuid );
		Plugin::activate();
		$this->assertSame( $uuid, get_option( 'ecomweb_site_uuid' ) );
	}

	/**
	 * Deactivation stops a running copy: its pending actions would have no callback.
	 */
	public function test_deactivation_cancels_running_copy() {
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( ECOMWEB_WOO_FILE ), array( Plugin::class, 'deactivate' ) ) );
		add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
		Copy_State::put(
			array(
				'id'     => 'copy-1',
				'status' => 'running',
			)
		);
		as_enqueue_async_action( Copy_Runner::SEND_HOOK, array( 'copy-1' ), Copy_Runner::GROUP );
		as_schedule_single_action( time() + 30, Copy_Runner::POLL_HOOK, array( 'copy-1' ), Copy_Runner::GROUP );

		Plugin::deactivate();

		$this->assertSame( 'cancelled', Copy_State::get()['status'] );
		$this->assertFalse( as_next_scheduled_action( Copy_Runner::SEND_HOOK, array( 'copy-1' ), Copy_Runner::GROUP ) );
		$this->assertFalse( as_next_scheduled_action( Copy_Runner::POLL_HOOK, array( 'copy-1' ), Copy_Runner::GROUP ) );
	}

	/**
	 * Site uuid created lazily when missing.
	 */
	public function test_site_uuid_created_lazily_when_missing() {
		delete_option( 'ecomweb_site_uuid' );
		$this->assertTrue( wp_is_uuid( Plugin::site_uuid(), 4 ) );
	}

	/**
	 * Declares hpos compatibility.
	 */
	public function test_declares_hpos_compatibility() {
		$compatible = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( 'ecomweb-for-woocommerce/ecomweb-for-woocommerce.php', $compatible['compatible'] );
	}

	/**
	 * Admin notice and no hooks when woocommerce missing.
	 */
	public function test_admin_notice_and_no_hooks_when_woocommerce_missing() {
		add_filter( 'ecomweb_woocommerce_version', '__return_null' );
		$plugin = new Plugin();
		$this->assertFalse( $plugin->requirements_met() );
		$plugin->boot();
		$this->assertNotFalse( has_action( 'admin_notices', array( $plugin, 'requirements_notice' ) ) );
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( $plugin, 'declare_hpos_compatibility' ) ) );

		ob_start();
		$plugin->requirements_notice();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'EcomWeb for WooCommerce needs WooCommerce 8.0 or later.', $output );
	}

	/**
	 * Requirements fail below wc 8.
	 */
	public function test_requirements_fail_below_wc_8() {
		add_filter(
			'ecomweb_woocommerce_version',
			function () {
				return '7.9.0';
			}
		);
		$this->assertFalse( Plugin::instance()->requirements_met() );
	}

	/**
	 * Requirements met on wc 8.
	 */
	public function test_requirements_met_on_wc_8() {
		$this->assertTrue( Plugin::instance()->requirements_met() );
	}

	/**
	 * No requirements notice when requirements met.
	 */
	public function test_no_notice_when_requirements_met() {
		$plugin = new Plugin();
		$plugin->boot();
		$this->assertFalse( has_action( 'admin_notices', array( $plugin, 'requirements_notice' ) ) );
	}
}
