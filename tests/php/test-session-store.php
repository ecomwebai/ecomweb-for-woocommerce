<?php
/**
 * Session store tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Crypto;
use Ecomweb\Woo\Session_Store;

/**
 * Class Test_Session_Store
 */
class Test_Session_Store extends WP_UnitTestCase {

	/**
	 * A session.
	 *
	 * @param string $access Access token.
	 * @return array
	 */
	private function session( $access = 'AT' ) {
		return array(
			'access_token'       => $access,
			'refresh_token'      => 'RT',
			'expires_at'         => 1900000000,
			'email'              => 'a@b.co',
			'user_id'            => 'u1',
			'signed_in_home_url' => 'https://shop.test',
			'api_url'            => 'https://apibase.ecomweb.app/api/v1',
		);
	}

	/**
	 * Round trip, and the raw option holds no token.
	 */
	public function test_write_read_round_trip_and_raw_option_is_encrypted() {
		global $wpdb;
		$store = new Session_Store();
		$store->write( $this->session() );
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'ecomweb_session' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertStringNotContainsString( 'AT', $raw );
		$this->assertStringNotContainsString( 'RT', $raw );
		$this->assertSame( 'AT', $store->read()['access_token'] );
		$this->assertSame( $this->session(), $store->read() );
	}

	/**
	 * Autoload is off, on first write and on rewrite.
	 */
	public function test_option_not_autoloaded() {
		global $wpdb;
		$store = new Session_Store();
		$store->write( $this->session() );
		$store->write( $this->session( 'AT2' ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", 'ecomweb_session' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * Undecryptable session is removed and the reason recorded.
	 */
	public function test_undecryptable_session_is_deleted_and_reason_set() {
		$store = new Session_Store();
		$store->write( $this->session() );
		add_filter(
			'salt',
			function ( $salt ) {
				return $salt . 'rotated';
			}
		);
		$this->assertNull( $store->read() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
		$this->assertSame( 'salts_changed', get_option( 'ecomweb_signed_out_reason' ) );
	}

	/**
	 * A plaintext option is never accepted.
	 */
	public function test_plaintext_option_is_not_accepted() {
		update_option( 'ecomweb_session', wp_json_encode( $this->session() ), false );
		$store = new Session_Store();
		$this->assertNull( $store->read() );
		$this->assertFalse( get_option( 'ecomweb_session' ) );
	}

	/**
	 * Reads see a change made behind the object cache.
	 */
	public function test_read_bypasses_object_cache() {
		global $wpdb;
		$store = new Session_Store();
		$store->write( $this->session( 'A' ) );
		$this->assertSame( 'A', $store->read()['access_token'] );
		$wpdb->update( $wpdb->options, array( 'option_value' => Crypto::encrypt( wp_json_encode( $this->session( 'B' ) ) ) ), array( 'option_name' => 'ecomweb_session' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'B', $store->read()['access_token'] );
	}

	/**
	 * Delete removes it; reading nothing leaves no reason.
	 */
	public function test_delete_and_missing() {
		$store = new Session_Store();
		$this->assertNull( $store->read() );
		$this->assertFalse( get_option( 'ecomweb_signed_out_reason' ) );
		$store->write( $this->session() );
		$store->delete();
		$this->assertNull( $store->read() );
	}
}
