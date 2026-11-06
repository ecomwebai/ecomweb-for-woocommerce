<?php
/**
 * Lock tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Lock;

/**
 * Class Test_Lock
 */
class Test_Lock extends WP_UnitTestCase {

	/**
	 * A second acquire fails while the first holds the lock.
	 */
	public function test_acquire_then_second_acquire_times_out() {
		$token = Lock::acquire( 'x', 30, 0 );
		$this->assertNotNull( $token );
		$this->assertNull( Lock::acquire( 'x', 30, 0.3 ) );
	}

	/**
	 * Release frees the lock.
	 */
	public function test_release_allows_reacquire() {
		$token = Lock::acquire( 'x', 30, 0 );
		Lock::release( 'x', $token );
		$this->assertNotNull( Lock::acquire( 'x', 30, 0 ) );
	}

	/**
	 * Only the owner token releases.
	 */
	public function test_release_with_wrong_token_keeps_lock() {
		$token = Lock::acquire( 'x', 30, 0 );
		$this->assertNotNull( $token );
		Lock::release( 'x', 'not-mine' );
		$this->assertNull( Lock::acquire( 'x', 30, 0 ) );
	}

	/**
	 * A crashed holder's expired lock is broken.
	 */
	public function test_expired_lock_is_broken() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture row.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => '_ecomweb_lock_x',
				'option_value' => 'old|' . ( time() - 1 ),
				'autoload'     => 'no',
			)
		);
		$this->assertNotNull( Lock::acquire( 'x', 30, 0 ) );
	}

	/**
	 * A live foreign lock is not broken.
	 */
	public function test_unexpired_foreign_lock_is_kept() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture row.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => '_ecomweb_lock_x',
				'option_value' => 'other|' . ( time() + 60 ),
				'autoload'     => 'no',
			)
		);
		$this->assertNull( Lock::acquire( 'x', 30, 0 ) );
	}

	/**
	 * The lock row is not autoloaded and ignores the object cache.
	 */
	public function test_row_is_not_autoloaded_and_bypasses_cache() {
		global $wpdb;
		$token = Lock::acquire( 'x', 30, 0 );
		$row   = $wpdb->get_row( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = '_ecomweb_lock_x'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'no', $row->autoload );
		$this->assertSame( 0, strpos( $row->option_value, $token . '|' ) );
		$this->assertFalse( wp_cache_get( '_ecomweb_lock_x', 'options' ) );
		$this->assertArrayNotHasKey( '_ecomweb_lock_x', wp_load_alloptions() );
	}

	/**
	 * Waiting fires the hook.
	 */
	public function test_wait_hook_fires_while_waiting() {
		Lock::acquire( 'x', 30, 0 );
		$spins = 0;
		add_action(
			'ecomweb_lock_wait',
			function () use ( &$spins ) {
				++$spins;
			}
		);
		Lock::acquire( 'x', 30, 0.6 );
		$this->assertGreaterThanOrEqual( 1, $spins );
	}

	/**
	 * The lock can be taken after the holder releases during a wait.
	 */
	public function test_waiter_acquires_when_holder_releases() {
		$token = Lock::acquire( 'x', 30, 0 );
		add_action(
			'ecomweb_lock_wait',
			function () use ( $token ) {
				Lock::release( 'x', $token );
			}
		);
		$this->assertNotNull( Lock::acquire( 'x', 30, 2 ) );
	}
}
