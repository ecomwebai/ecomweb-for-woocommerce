<?php
/**
 * Connection tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Admin_Page;
use Ecomweb\Woo\Auth;
use Ecomweb\Woo\Config;
use Ecomweb\Woo\Plugin;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Connection
 */
class Test_Connection extends WP_UnitTestCase {

	const BASE = '/stores/s1/integrations/woocommerce/connections';

	/**
	 * Fake.
	 *
	 * @var Fake_Api
	 */
	private $fake;

	/**
	 * Set up: signed in, fake API.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Session_Store() )->write(
			array(
				'access_token'       => 'AT',
				'refresh_token'      => 'RT',
				'expires_at'         => time() + 3600,
				'email'              => 'merchant@example.com',
				'user_id'            => 'u1',
				'api_url'            => Config::api_url(),
				'signed_in_home_url' => Auth::normalise_home( home_url() ),
			)
		);
		$this->fake = Fake_Api::start();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->fake->stop();
		parent::tear_down();
	}

	/**
	 * The connection under test.
	 *
	 * @return Ecomweb\Woo\Connection
	 */
	private function connection() {
		return Plugin::instance()->connection();
	}

	/**
	 * A registration answer.
	 *
	 * @param string $mode Images mode.
	 * @return array
	 */
	private function registered( string $mode = 'upload' ): array {
		return array(
			'connection_id' => 'c1',
			'images_mode'   => $mode,
			'store'         => array(
				'id'            => 's1',
				'ref'           => 's1',
				'name'          => 'A',
				'currency'      => 'VND',
				'dashboard_url' => 'https://ecomweb.app/store/s1',
			),
		);
	}

	/**
	 * Owners and admins in any case can copy; staff cannot.
	 */
	public function test_list_stores_marks_owner_admin_enabled_staff_disabled() {
		$this->fake->on(
			'GET',
			'/stores',
			200,
			array(
				'data' => array(
					array(
						'ref'  => 's1',
						'name' => 'A',
						'role' => 'OWNER',
					),
					array(
						'ref'  => 's2',
						'name' => 'B',
						'role' => 'admin',
					),
					array(
						'ref'  => 's3',
						'name' => 'C',
						'role' => 'STAFF',
					),
				),
			)
		);
		$list = $this->connection()->list_stores();

		$this->assertSame( array( true, true, false ), array_column( $list, 'can_copy' ) );
		$this->assertSame( 'Only the store owner or an admin can copy products', $list[2]['reason'] );
		$this->assertNull( $list[0]['reason'] );
		$this->assertStringContainsString( 'limit=100', $this->fake->calls( '/stores' )[0]['url'] );
	}

	/**
	 * A bare array works, and a missing role counts as enabled.
	 */
	public function test_list_stores_accepts_bare_array() {
		$this->fake->on(
			'GET',
			'/stores',
			200,
			array(
				array(
					'ref'  => 's1',
					'name' => 'A',
					'role' => 'owner',
				),
				array(
					'ref'  => 's2',
					'name' => 'B',
				),
			)
		);
		$list = $this->connection()->list_stores();

		$this->assertCount( 2, $list );
		$this->assertTrue( $list[1]['can_copy'] );
		$this->assertNull( $list[1]['role'] );
	}

	/**
	 * A failure is an error.
	 */
	public function test_list_stores_failure_is_error() {
		$this->fake->on( 'GET', '/stores', 500, array( 'message' => 'Boom' ) );
		$this->assertWPError( $this->connection()->list_stores() );
	}

	/**
	 * Registration sends the site details and saves the connection, not autoloaded.
	 */
	public function test_register_sends_payload_and_saves_connection() {
		update_option( 'woocommerce_currency', 'VND' );
		update_option( 'woocommerce_weight_unit', 'g' );
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );

		$saved = $this->connection()->register( 's1' );

		$call = $this->fake->calls( self::BASE )[0];
		$this->assertSame(
			array(
				'site_uuid'            => get_option( 'ecomweb_site_uuid' ),
				'site_url'             => get_option( 'home' ),
				'woocommerce_currency' => 'VND',
				'weight_unit'          => 'g',
				'plugin_version'       => '1.0.0',
			),
			json_decode( $call['body'], true )
		);
		$this->assertSame( 'c1', get_option( 'ecomweb_connection' )['connection_id'] );
		$this->assertSame( 'c1', $saved['connection_id'] );
		$this->assertSame( 'A', $this->connection()->current()['store']['name'] );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'ecomweb_connection' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * A 403 is no access and saves nothing.
	 */
	public function test_register_403_is_no_access() {
		$this->fake->on( 'POST', self::BASE, 403, array( 'message' => 'Forbidden' ) );
		$result = $this->connection()->register( 's1' );

		$this->assertWPError( $result );
		$this->assertSame( 'ecomweb_no_access', $result->get_error_code() );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
	}

	/**
	 * Other failures carry the API message, including a 429 on registration (X12).
	 */
	public function test_register_other_failures_are_api_errors() {
		$this->fake->on( 'POST', self::BASE, 429, array( 'message' => 'Slow down' ) );
		$result = $this->connection()->register( 's1' );

		$this->assertSame( 'ecomweb_api', $result->get_error_code() );
		$this->assertSame( 'Slow down', $result->get_error_message() );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
	}

	/**
	 * An answer without a connection id is not saved.
	 */
	public function test_register_bad_answer_is_not_saved() {
		$this->fake->on( 'POST', self::BASE, 200, array( 'images_mode' => 'upload' ) );
		$this->assertSame( 'ecomweb_api', $this->connection()->register( 's1' )->get_error_code() );
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
	}

	/**
	 * Recheck saves the new mode.
	 */
	public function test_recheck_updates_images_mode() {
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->fake->on( 'POST', self::BASE . '/c1/probe', 200, array( 'images_mode' => 'fetch' ) );
		$this->connection()->register( 's1' );

		$this->assertSame( 'fetch', $this->connection()->recheck_images() );
		$this->assertSame( 'fetch', get_option( 'ecomweb_connection' )['images_mode'] );
	}

	/**
	 * A 429 is throttled and keeps the mode.
	 */
	public function test_recheck_429_is_throttled() {
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->fake->on( 'POST', self::BASE . '/c1/probe', 429, array( 'message' => 'Too many' ) );
		$this->connection()->register( 's1' );
		$result = $this->connection()->recheck_images();

		$this->assertSame( 'ecomweb_throttled', $result->get_error_code() );
		$this->assertSame( 'Checked too often. Try again in a minute.', $result->get_error_message() );
		$this->assertSame( 'upload', get_option( 'ecomweb_connection' )['images_mode'] );
	}

	/**
	 * Without a connection there is nothing to recheck.
	 */
	public function test_recheck_without_connection_sends_nothing() {
		$this->assertSame( 'ecomweb_not_connected', $this->connection()->recheck_images()->get_error_code() );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * A sign-in registers the existing connection again (the site may have moved).
	 */
	public function test_signed_in_hook_reregisters_existing_connection() {
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->connection()->register( 's1' );

		do_action( 'ecomweb_signed_in' );

		$this->assertSame( 2, $this->fake->count( self::BASE ) );
	}

	/**
	 * With no connection the hook sends nothing.
	 */
	public function test_signed_in_hook_without_connection_sends_nothing() {
		do_action( 'ecomweb_signed_in' );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * Losing access on re-register forgets the connection and leaves the notice for the page.
	 */
	public function test_signed_in_hook_403_forgets_connection_and_flashes() {
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->connection()->register( 's1' );
		$this->fake->on( 'POST', self::BASE, 403, array( 'message' => 'Forbidden' ) );

		do_action( 'ecomweb_signed_in' );

		$this->assertFalse( get_option( 'ecomweb_connection' ) );
		$stored = get_transient( 'ecomweb_notice_' . get_current_user_id() );
		$this->assertSame( 'lost_access', $stored['key'] );
		$this->assertSame( array( 'A' ), $stored['args'] );
	}

	/**
	 * Another failure on re-register keeps the connection.
	 */
	public function test_signed_in_hook_failure_keeps_connection() {
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->connection()->register( 's1' );
		$this->fake->on( 'POST', self::BASE, 500, array( 'message' => 'Boom' ) );

		do_action( 'ecomweb_signed_in' );

		$this->assertSame( 'c1', get_option( 'ecomweb_connection' )['connection_id'] );
	}

	/**
	 * W22: a changed WooCommerce currency or unit is sent again before a preflight.
	 */
	public function test_changed_currency_reregisters_before_preflight() {
		update_option( 'woocommerce_currency', 'USD' );
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->connection()->register( 's1' );

		$this->assertTrue( $this->connection()->ensure_current() );
		$this->assertSame( 1, $this->fake->count( self::BASE ), 'Unchanged: nothing sent.' );

		update_option( 'woocommerce_currency', 'VND' );
		$this->assertTrue( $this->connection()->ensure_current() );
		$this->assertSame( 2, $this->fake->count( self::BASE ) );
		$this->assertSame( 'VND', json_decode( $this->fake->calls( self::BASE )[1]['body'], true )['woocommerce_currency'] );

		$this->assertTrue( $this->connection()->ensure_current() );
		$this->assertSame( 2, $this->fake->count( self::BASE ), 'Up to date again.' );
	}

	/**
	 * The payload's site_url is the home option, so a plugin that filters home_url() per request
	 * neither changes the payload nor triggers a re-register.
	 */
	public function test_filtered_home_url_changes_neither_payload_nor_hash() {
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->connection()->register( 's1' );
		$filter = function () {
			return 'https://fr.example.net/fr';
		};
		add_filter( 'home_url', $filter );

		$payload = $this->connection()->registration_payload();
		$current = $this->connection()->ensure_current();
		remove_filter( 'home_url', $filter );

		$this->assertSame( get_option( 'home' ), $payload['site_url'] );
		$this->assertTrue( $current );
		$this->assertSame( 1, $this->fake->count( self::BASE ) );
	}

	/**
	 * W22: a failed update keeps the old connection and reports the error; a 403 forgets it.
	 */
	public function test_ensure_current_failure_keeps_and_403_forgets() {
		update_option( 'woocommerce_weight_unit', 'kg' );
		$this->fake->on( 'POST', self::BASE, 200, $this->registered() );
		$this->connection()->register( 's1' );
		update_option( 'woocommerce_weight_unit', 'g' );

		$this->fake->on( 'POST', self::BASE, 500, array( 'message' => 'Boom' ) );
		$result = $this->connection()->ensure_current();
		$this->assertSame( 'ecomweb_api', $result->get_error_code() );
		$this->assertNotNull( $this->connection()->current() );

		$this->fake->on( 'POST', self::BASE, 403, array( 'message' => 'Forbidden' ) );
		$result = $this->connection()->ensure_current();
		$this->assertSame( 'ecomweb_no_access', $result->get_error_code() );
		$this->assertNull( $this->connection()->current() );
	}

	/**
	 * With no connection, ensure_current has nothing to do.
	 */
	public function test_ensure_current_without_connection_is_true() {
		$this->assertTrue( $this->connection()->ensure_current() );
		$this->assertSame( array(), $this->fake->calls() );
	}

	/**
	 * Forget drops the connection only.
	 */
	public function test_forget_removes_connection() {
		update_option( 'ecomweb_connection', array( 'connection_id' => 'c1' ) );
		$this->assertNull( $this->connection()->current(), 'An incomplete record is not a connection.' );
		$this->connection()->forget();
		$this->assertFalse( get_option( 'ecomweb_connection' ) );
		$this->assertTrue( class_exists( Admin_Page::class ) );
	}
}
