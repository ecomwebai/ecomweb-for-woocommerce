<?php
/**
 * Copy status AJAX tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Copy_State;

/**
 * Class Test_Copy_Status_Ajax
 */
class Test_Copy_Status_Ajax extends WP_Ajax_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array( '_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes' ) as $callback ) {
			remove_action( 'admin_init', $callback );
		}
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( Copy_State::OPTION );
		parent::tear_down();
	}

	/**
	 * Runs the action and returns the decoded response.
	 *
	 * @return array
	 */
	private function call(): array {
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'ecomweb_copy_status' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		return json_decode( $this->_last_response, true );
	}

	/**
	 * Nonce and capability.
	 */
	public function test_copy_status_ajax_requires_nonce_and_capability() {
		Copy_State::put(
			array(
				'status' => 'running',
				'total'  => 10,
				'counts' => array(
					'created' => 2,
					'failed'  => 1,
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( WPAjaxDieStopException::class );
		try {
			$this->_handleAjax( 'ecomweb_copy_status' );
		} finally {
			unset( $_POST['nonce'] );
		}
	}

	/**
	 * Editors are refused.
	 */
	public function test_editor_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST['nonce'] = wp_create_nonce( 'ecomweb_ajax' );
		$this->assertFalse( $this->call()['success'] );
	}

	/**
	 * Shop managers get status, done, total and counts.
	 */
	public function test_returns_status_done_total_counts() {
		Copy_State::put(
			array(
				'status' => 'running',
				'total'  => 10,
				'counts' => array(
					'created' => 2,
					'failed'  => 1,
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$_POST['nonce'] = wp_create_nonce( 'ecomweb_ajax' );
		$data           = $this->call()['data'];
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( 3, $data['done'] );
		$this->assertSame( 10, $data['total'] );
		$this->assertSame( 2, $data['counts']['created'] );
	}

	/**
	 * No copy yet.
	 */
	public function test_no_state() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['nonce'] = wp_create_nonce( 'ecomweb_ajax' );
		$data           = $this->call()['data'];
		$this->assertSame( '', $data['status'] );
		$this->assertSame( 0, $data['total'] );
	}
}
