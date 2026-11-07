<?php
/**
 * Uninstall tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Config;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Uninstall
 */
class Test_Uninstall extends WP_UnitTestCase {

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'option_home', '_config_wp_home' );
		remove_filter( 'option_siteurl', '_config_wp_siteurl' );
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		$this->fake = Fake_Api::start();
		$this->fake->on( 'POST', '/auth/logout', 200, array( 'ok' => true ) );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * Saves a session signed in at $home.
	 *
	 * @param string $home Normalised home.
	 */
	private function seed_session( $home ) {
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 600,
				'email'              => 'm@shop.vn',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => $home,
			)
		);
	}

	/**
	 * Runs uninstall.php.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ecomweb-for-woocommerce/ecomweb-for-woocommerce.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Seeds everything uninstall may touch.
	 *
	 * @return int Product ID.
	 */
	private function seed_everything() {
		global $wpdb;
		update_option( 'ecomweb_connection', array( 'connection_id' => 'c1' ), false );
		update_option( 'ecomweb_copy_state', array( 'status' => 'done' ), false );
		update_option( 'ecomweb_signed_out_reason', 'revoked', false );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", '_ecomweb_lock_copy', 'tok|' . ( time() + 60 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		set_transient( 'ecomweb_device_1', 'dev', 600 );
		set_transient( 'ecomweb_selection_1', array( 1, 2 ), 600 );
		set_transient( 'ecomweb_notice_1', array( 'key' => 'x' ), 60 );
		set_transient( 'unrelated_transient', 'keep', 600 );
		Plugin::site_uuid();
		$pid = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $pid, '_ecomweb_product_id', 'E1' );
		return $pid;
	}

	/**
	 * Secrets and state go; the site UUID and the product mapping stay.
	 */
	public function test_uninstall_removes_secrets_keeps_mapping() {
		global $wpdb;
		$this->seed_session( 'example.org' );
		$pid  = $this->seed_everything();
		$uuid = get_option( 'ecomweb_site_uuid' );

		$this->run_uninstall();

		wp_cache_flush();
		foreach ( array( 'ecomweb_session', 'ecomweb_connection', 'ecomweb_copy_state', 'ecomweb_signed_out_reason' ) as $name ) {
			$this->assertFalse( get_option( $name ), $name );
		}
		$locks = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_ecomweb\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', $locks );
		$left = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%transient%ecomweb\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', $left );
		$this->assertSame( 'keep', get_transient( 'unrelated_transient' ) );
		$this->assertSame( $uuid, get_option( 'ecomweb_site_uuid' ) );
		$this->assertSame( 'E1', get_post_meta( $pid, '_ecomweb_product_id', true ) );
		$this->assertSame( 1, $this->fake->count( '/auth/logout' ) );
	}

	/**
	 * When the site moved, the token is never sent.
	 */
	public function test_uninstall_after_move_makes_no_http() {
		$this->seed_session( 'old-site.example.net' );
		$this->seed_everything();

		$this->run_uninstall();

		$this->assertSame( array(), $this->fake->calls() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
	}

	/**
	 * No session at all: nothing is sent, and the rest is still cleaned.
	 */
	public function test_uninstall_without_session_makes_no_http() {
		$this->seed_everything();
		$this->run_uninstall();
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertFalse( get_option( 'ecomweb_copy_state' ) );
	}

	/**
	 * The runner's scheduled actions are removed with the hook-only form.
	 */
	public function test_uninstall_unschedules_both_runner_hooks() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		as_schedule_single_action( time() + 600, 'ecomweb_copy_send', array( 'copy-1' ), 'ecomweb' );
		as_schedule_single_action( time() + 600, 'ecomweb_copy_poll', array( 'copy-1' ), 'ecomweb' );
		$this->assertNotFalse( as_next_scheduled_action( 'ecomweb_copy_send' ) );

		$this->run_uninstall();

		$this->assertFalse( as_next_scheduled_action( 'ecomweb_copy_send' ) );
		$this->assertFalse( as_next_scheduled_action( 'ecomweb_copy_poll' ) );
	}
}
