<?php
/**
 * Product_Meta tests.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Product_Meta;

/**
 * Class Test_Product_Meta
 */
class Test_Product_Meta extends WP_UnitTestCase {

	/**
	 * A simple product.
	 *
	 * @return int
	 */
	private function simple(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Tee' );
		$product->set_regular_price( '10.00' );
		return $product->save();
	}

	/**
	 * A CopyResult.
	 *
	 * @param int    $id       WC product ID.
	 * @param string $result   Result.
	 * @param array  $variants Variants.
	 * @param string $ecom_id  ecomweb product ID.
	 * @return array
	 */
	private function result( int $id, string $result, array $variants = array(), string $ecom_id = 'E1' ): array {
		return array(
			'wc_product_id'      => $id,
			'result'             => $result,
			'ecomweb_product_id' => 'failed' === $result ? null : $ecom_id,
			'url'                => 'failed' === $result ? null : 'https://ecomweb.app/store/s1/products/' . $ecom_id,
			'variants'           => $variants,
			'warnings'           => array(),
			'error'              => null,
		);
	}

	/**
	 * Created writes the four product keys.
	 */
	public function test_created_writes_product_meta() {
		$id = $this->simple();
		Product_Meta::apply_result( $this->result( $id, 'created' ), 'c1' );

		$this->assertSame( 'E1', get_post_meta( $id, Product_Meta::PRODUCT_ID, true ) );
		$this->assertSame( 'c1', get_post_meta( $id, Product_Meta::CONNECTION_ID, true ) );
		$this->assertSame( 'https://ecomweb.app/store/s1/products/E1', get_post_meta( $id, Product_Meta::PRODUCT_URL, true ) );
		$this->assertEqualsWithDelta( time(), (int) get_post_meta( $id, Product_Meta::COPIED_AT, true ), 2 );
	}

	/**
	 * An "any" variation becomes several ecomweb variants (moved here from T7, W1).
	 */
	public function test_any_variation_maps_to_several_variants() {
		$product  = Product_Factory::variable_any();
		$children = $product->get_children();
		$any      = (int) $children[0];

		Product_Meta::apply_result(
			$this->result(
				$product->get_id(),
				'created',
				array(
					array(
						'wc_variation_id'    => $any,
						'ecomweb_variant_id' => 'V1',
					),
					array(
						'wc_variation_id'    => $any,
						'ecomweb_variant_id' => 'V2',
					),
					array(
						'wc_variation_id'    => (int) $children[1],
						'ecomweb_variant_id' => 'V3',
					),
				)
			),
			'c1'
		);

		$this->assertSame( '["V1","V2"]', get_post_meta( $any, Product_Meta::VARIANT_IDS, true ) );
		$this->assertSame( '["V3"]', get_post_meta( (int) $children[1], Product_Meta::VARIANT_IDS, true ) );
	}

	/**
	 * A variation ID that isn't this product's is ignored.
	 */
	public function test_variation_of_another_product_is_ignored() {
		$id    = $this->simple();
		$other = $this->simple();
		Product_Meta::apply_result(
			$this->result(
				$id,
				'updated',
				array(
					array(
						'wc_variation_id'    => $other,
						'ecomweb_variant_id' => 'VX',
					),
					array(
						'wc_variation_id'    => $id,
						'ecomweb_variant_id' => 'VS',
					),
				)
			),
			'c1'
		);

		$this->assertSame( '', get_post_meta( $other, Product_Meta::VARIANT_IDS, true ) );
		$this->assertSame( '["VS"]', get_post_meta( $id, Product_Meta::VARIANT_IDS, true ) );
	}

	/**
	 * Skipped fills in missing IDs and keeps an existing copied_at.
	 */
	public function test_skipped_fills_missing_ids_but_keeps_copied_at() {
		$id = $this->simple();
		update_post_meta( $id, Product_Meta::COPIED_AT, 1000 );
		update_post_meta( $id, Product_Meta::CONNECTION_ID, 'c1' );

		Product_Meta::apply_result( $this->result( $id, 'skipped', array(), 'E5' ), 'c1' );

		$this->assertSame( '1000', get_post_meta( $id, Product_Meta::COPIED_AT, true ) );
		$this->assertSame( 'E5', get_post_meta( $id, Product_Meta::PRODUCT_ID, true ) );
		$this->assertSame( 'https://ecomweb.app/store/s1/products/E5', get_post_meta( $id, Product_Meta::PRODUCT_URL, true ) );
	}

	/**
	 * Skipped keeps IDs already saved for the same connection, and replaces another connection's.
	 */
	public function test_skipped_keeps_same_connection_ids_and_replaces_other_connection() {
		$same = $this->simple();
		update_post_meta( $same, Product_Meta::PRODUCT_ID, 'OLD' );
		update_post_meta( $same, Product_Meta::CONNECTION_ID, 'c1' );
		Product_Meta::apply_result( $this->result( $same, 'skipped', array(), 'NEW' ), 'c1' );
		$this->assertSame( 'OLD', get_post_meta( $same, Product_Meta::PRODUCT_ID, true ) );

		$other = $this->simple();
		update_post_meta( $other, Product_Meta::PRODUCT_ID, 'OLD' );
		update_post_meta( $other, Product_Meta::CONNECTION_ID, 'c0' );
		Product_Meta::apply_result( $this->result( $other, 'skipped', array(), 'NEW' ), 'c1' );
		$this->assertSame( 'NEW', get_post_meta( $other, Product_Meta::PRODUCT_ID, true ) );
		$this->assertSame( 'c1', get_post_meta( $other, Product_Meta::CONNECTION_ID, true ) );
		$this->assertEqualsWithDelta( time(), (int) get_post_meta( $other, Product_Meta::COPIED_AT, true ), 2 );
	}

	/**
	 * Failed writes nothing.
	 */
	public function test_failed_writes_nothing() {
		$id = $this->simple();
		Product_Meta::apply_result( $this->result( $id, 'failed' ), 'c1' );

		$this->assertSame( array(), array_intersect_key( get_post_meta( $id ), array_flip( array( Product_Meta::PRODUCT_ID, Product_Meta::CONNECTION_ID, Product_Meta::PRODUCT_URL, Product_Meta::COPIED_AT ) ) ) );
	}

	/**
	 * Writes go straight to post meta: no product save hooks fire.
	 */
	public function test_writes_do_not_fire_product_save_hooks() {
		$id    = $this->simple();
		$saves = 0;
		$count = function () use ( &$saves ) {
			++$saves;
		};
		add_action( 'woocommerce_update_product', $count );
		add_action( 'save_post', $count );

		Product_Meta::apply_result( $this->result( $id, 'created' ), 'c1' );

		$this->assertSame( 0, $saves );
	}

	/**
	 * Copied ignores another connection's meta.
	 */
	public function test_copied_ignores_other_connection() {
		$id = $this->simple();
		Product_Meta::apply_result( $this->result( $id, 'created' ), 'c0' );

		$this->assertNull( Product_Meta::copied( $id, 'c1' ) );
		$copied = Product_Meta::copied( $id, 'c0' );
		$this->assertSame( 'E1', $copied['id'] );
		$this->assertSame( 'https://ecomweb.app/store/s1/products/E1', $copied['url'] );
		$this->assertIsInt( $copied['copied_at'] );
		$this->assertNull( Product_Meta::copied( $this->simple(), 'c0' ) );
	}

	/**
	 * Forget removes product keys and variation IDs.
	 */
	public function test_forget_removes_product_and_variation_meta() {
		$product  = Product_Factory::variable_any();
		$children = $product->get_children();
		Product_Meta::apply_result(
			$this->result(
				$product->get_id(),
				'created',
				array(
					array(
						'wc_variation_id'    => (int) $children[0],
						'ecomweb_variant_id' => 'V1',
					),
				)
			),
			'c1'
		);

		Product_Meta::forget( $product->get_id() );

		$this->assertNull( Product_Meta::copied( $product->get_id(), 'c1' ) );
		foreach ( array( Product_Meta::PRODUCT_ID, Product_Meta::CONNECTION_ID, Product_Meta::PRODUCT_URL, Product_Meta::COPIED_AT ) as $key ) {
			$this->assertSame( '', get_post_meta( $product->get_id(), $key, true ), $key );
		}
		$this->assertSame( '', get_post_meta( (int) $children[0], Product_Meta::VARIANT_IDS, true ) );
	}
}
