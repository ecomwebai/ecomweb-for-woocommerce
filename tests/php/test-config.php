<?php
/**
 * Config tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Config;

/**
 * Class Test_Config
 */
class Test_Config extends WP_UnitTestCase {

	/**
	 * Defaults.
	 */
	public function test_defaults() {
		$this->assertSame( 'https://apibase.ecomweb.app/api/v1', Config::api_url() );
		$this->assertSame( 'https://ecomweb.app', Config::dashboard_url() );
		$this->assertSame( ECOMWEB_WOO_VERSION, Config::version() );
	}

	/**
	 * Constant override is used and untrailingslashed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_override_untrailingslashed() {
		define( 'ECOMWEB_API_URL', 'http://host.docker.internal:3001/api/v1/' );
		define( 'ECOMWEB_DASHBOARD_URL', 'http://localhost:3000/' );
		$this->assertSame( 'http://host.docker.internal:3001/api/v1', Config::api_url() );
		$this->assertSame( 'http://localhost:3000', Config::dashboard_url() );
	}

	/**
	 * No filter can change api url.
	 */
	public function test_no_filter_can_change_api_url() {
		add_filter(
			'ecomweb_api_url',
			function () {
				return 'https://evil.test';
			}
		);
		add_filter(
			'ecomweb_dashboard_url',
			function () {
				return 'https://evil.test';
			}
		);
		$this->assertSame( 'https://apibase.ecomweb.app/api/v1', Config::api_url() );
		$this->assertSame( 'https://ecomweb.app', Config::dashboard_url() );
	}
}
