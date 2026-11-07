<?php
/**
 * Probe route tests.
 *
 * @package ecomweb-for-woocommerce
 */

/**
 * Class Test_Probe_Route
 */
class Test_Probe_Route extends WP_UnitTestCase {

	/**
	 * Fresh REST server for each test.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- rest_get_server() rebuilds it and fires rest_api_init.
	}

	/**
	 * Drop the server so other tests don't see it.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Anyone can call it, and it answers the site UUID only.
	 */
	public function test_probe_is_public_and_returns_only_site_uuid() {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/ecomweb/v1/probe' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'site_uuid' => get_option( 'ecomweb_site_uuid' ) ), $response->get_data() );
		$this->assertNotEmpty( get_option( 'ecomweb_site_uuid' ) );
	}

	/**
	 * Only GET is allowed.
	 */
	public function test_probe_rejects_post() {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/ecomweb/v1/probe' ) );
		$this->assertSame( 404, $response->get_status() );
	}
}
