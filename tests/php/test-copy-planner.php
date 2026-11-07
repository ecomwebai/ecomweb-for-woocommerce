<?php
/**
 * Copy_Planner tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Copy_Planner;

/**
 * Class Test_Copy_Planner
 */
class Test_Copy_Planner extends WP_UnitTestCase {

	/**
	 * A preflight answer.
	 *
	 * @param array $states   ID => state.
	 * @param int   $limit    Plan limit.
	 * @param int   $current  Products in the store.
	 * @param bool  $matches  Currency matches.
	 * @return array
	 */
	private function preflight( array $states, int $limit = -1, int $current = 0, bool $matches = true ): array {
		$products = array();
		foreach ( $states as $id => $state ) {
			$products[] = array(
				'wc_product_id'      => $id,
				'state'              => $state,
				'ecomweb_product_id' => 'new' === $state ? null : 'E' . $id,
			);
		}
		return array(
			'products' => $products,
			'plan'     => array(
				'limit'     => $limit,
				'current'   => $current,
				'new_count' => 999,
			),
			'currency' => array(
				'store'       => 'VND',
				'woocommerce' => $matches ? 'VND' : 'USD',
				'matches'     => $matches,
			),
		);
	}

	/**
	 * Only new products by default.
	 */
	public function test_new_only_by_default() {
		$plan = Copy_Planner::plan(
			$this->preflight(
				array(
					1 => 'new',
					2 => 'copied',
					3 => 'deleted_in_ecomweb',
				)
			),
			false,
			false,
			false
		);
		$this->assertSame( array( 1 ), $plan['queue'] );
		$this->assertSame( 1, $plan['already_copied'] );
		$this->assertSame( 1, $plan['deleted'] );
		$this->assertSame( 0, $plan['overwrite_count'] );
	}

	/**
	 * An explicit selection recreates deleted products.
	 */
	public function test_explicit_selection_includes_deleted() {
		$plan = Copy_Planner::plan(
			$this->preflight(
				array(
					3 => 'deleted_in_ecomweb',
					1 => 'new',
					2 => 'copied',
				)
			),
			false,
			true,
			false
		);
		$this->assertSame( array( 1, 3 ), $plan['queue'] );
		$this->assertSame( 2, $plan['creates'] );
	}

	/**
	 * Overwrite adds copied products.
	 */
	public function test_overwrite_adds_copied_and_counts() {
		$plan = Copy_Planner::plan(
			$this->preflight(
				array(
					1 => 'new',
					2 => 'copied',
				)
			),
			true,
			false,
			false
		);
		$this->assertSame( array( 1, 2 ), $plan['queue'] );
		$this->assertSame( 1, $plan['overwrite_count'] );
	}

	/**
	 * The cap comes from limit and current, never from new_count.
	 */
	public function test_cap_computed_from_limit_and_current() {
		$states = array(
			5 => 'new',
			1 => 'new',
			3 => 'new',
			2 => 'new',
			4 => 'new',
		);
		$pf     = $this->preflight( $states, 20, 18 );
		$plan   = Copy_Planner::plan( $pf, false, false, false );
		$this->assertSame( 2, $plan['fits'] );
		$this->assertTrue( $plan['over_cap'] );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $plan['queue'] );

		$cut = Copy_Planner::plan( $pf, false, false, true );
		$this->assertSame( array( 1, 2 ), $cut['queue'] );
	}

	/**
	 * Over the cap already: nothing fits, never negative.
	 */
	public function test_cap_never_negative() {
		$plan = Copy_Planner::plan( $this->preflight( array( 1 => 'new' ), 20, 25 ), false, false, true );
		$this->assertSame( 0, $plan['fits'] );
		$this->assertSame( array(), $plan['queue'] );
	}

	/**
	 * -1 means unlimited.
	 */
	public function test_unlimited_plan() {
		$plan = Copy_Planner::plan(
			$this->preflight(
				array(
					1 => 'new',
					2 => 'new',
					3 => 'new',
				),
				-1,
				500
			),
			false,
			false,
			false
		);
		$this->assertSame( 3, $plan['fits'] );
		$this->assertFalse( $plan['over_cap'] );
	}

	/**
	 * Overwriting does not use cap room.
	 */
	public function test_copied_not_counted_against_cap() {
		$plan = Copy_Planner::plan(
			$this->preflight(
				array(
					7 => 'copied',
					8 => 'copied',
					9 => 'copied',
				),
				20,
				20
			),
			true,
			false,
			true
		);
		$this->assertSame( 0, $plan['fits'] );
		$this->assertFalse( $plan['over_cap'] );
		$this->assertSame( array( 7, 8, 9 ), $plan['queue'] );
	}

	/**
	 * A currency mismatch blocks.
	 */
	public function test_currency_blocked() {
		$this->assertTrue( Copy_Planner::plan( $this->preflight( array( 1 => 'new' ), -1, 0, false ), false, false, false )['currency_blocked'] );
		$this->assertFalse( Copy_Planner::plan( $this->preflight( array( 1 => 'new' ) ), false, false, false )['currency_blocked'] );
	}
}
