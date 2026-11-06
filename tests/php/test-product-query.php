<?php
/**
 * Product_Query tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Product_Query;

/**
 * Class Test_Product_Query
 */
class Test_Product_Query extends WP_UnitTestCase {

	/**
	 * Creates and saves a product of the given class.
	 *
	 * @param string $class_name WC product class.
	 * @param string $status     Post status.
	 * @return int
	 */
	private function product( $class_name, $status = 'publish' ) {
		$product = new $class_name();
		$product->set_name( $class_name . ' ' . $status );
		$product->set_status( $status );
		if ( 'future' === $status ) {
			$product->set_date_created( time() + DAY_IN_SECONDS );
		}
		$product->save();
		return $product->get_id();
	}

	/**
	 * Only simple and variable products outside the trash, by ID ascending.
	 */
	public function test_all_supported_ids_excludes_grouped_external_trash_and_orders_by_id() {
		$simple   = $this->product( 'WC_Product_Simple' );
		$grouped  = $this->product( 'WC_Product_Grouped' );
		$variable = $this->product( 'WC_Product_Variable', 'draft' );
		$external = $this->product( 'WC_Product_External' );
		$pending  = $this->product( 'WC_Product_Simple', 'pending' );
		$private  = $this->product( 'WC_Product_Variable', 'private' );
		$future   = $this->product( 'WC_Product_Simple', 'future' );
		$trashed  = $this->product( 'WC_Product_Simple' );
		wp_trash_post( $trashed );

		$this->assertSame( 'future', get_post_status( $future ) );
		$this->assertSame( array( $simple, $variable, $pending, $private, $future ), Product_Query::all_supported_ids() );
		$this->assertNotContains( $grouped, Product_Query::all_supported_ids() );
		$this->assertNotContains( $external, Product_Query::all_supported_ids() );
	}

	/**
	 * Split keeps input order, reports unsupported products and drops missing IDs.
	 */
	public function test_split_reports_not_supported_and_drops_missing() {
		$simple   = $this->product( 'WC_Product_Simple' );
		$grouped  = $this->product( 'WC_Product_Grouped' );
		$variable = $this->product( 'WC_Product_Variable' );
		$trashed  = $this->product( 'WC_Product_Simple' );
		wp_trash_post( $trashed );
		$missing = 999999;

		$this->assertSame(
			array(
				'supported'     => array( $variable, $simple ),
				'not_supported' => array( $grouped, $trashed ),
			),
			Product_Query::split( array( $variable, $grouped, $missing, $simple, $trashed ) )
		);
	}
}
