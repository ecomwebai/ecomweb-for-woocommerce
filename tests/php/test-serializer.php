<?php
/**
 * Product_Serializer edge cases.
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Fetch_Image_Resolver;
use Ecomweb\Woo\Product_Serializer;

/**
 * Class Test_Serializer
 */
class Test_Serializer extends WP_UnitTestCase {

	/**
	 * Same environment as the fixture tests.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'uploads_use_yearmonth_folders', 0 );
		update_option( 'woocommerce_weight_unit', 'kg' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Grouped, external and trashed products are not serialised.
	 */
	public function test_grouped_external_and_trashed_return_null() {
		$grouped = new WC_Product_Grouped();
		$grouped->set_name( 'Bundle' );
		$grouped->save();

		$external = new WC_Product_External();
		$external->set_name( 'Affiliate' );
		$external->set_product_url( 'https://example.com/item' );
		$external->save();

		$trashed = new WC_Product_Simple();
		$trashed->set_name( 'Old' );
		$trashed->set_regular_price( '1.00' );
		$trashed->save();
		wp_trash_post( $trashed->get_id() );

		$serializer = new Product_Serializer();
		foreach ( array( $grouped->get_id(), $external->get_id(), $trashed->get_id() ) as $id ) {
			$product = wc_get_product( $id );
			$this->assertFalse( Product_Serializer::supports( $product ), $product->get_type() . ' ' . $product->get_status() );
			$this->assertNull( $serializer->serialize( $product, new Fetch_Image_Resolver() ) );
		}
	}

	/**
	 * A scheduled product is sent as a draft.
	 */
	public function test_future_status_sent_as_draft() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coming soon' );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'future' );
		$product->set_date_created( time() + DAY_IN_SECONDS );
		$product->save();

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'future', $product->get_status( 'edit' ) );

		$data = ( new Product_Serializer() )->serialize( $product, new Fetch_Image_Resolver() );
		$this->assertSame( 'draft', $data['status'] );
	}

	/**
	 * Review Focus 4: dedupe the featured image, drop missing attachments, cap at 20 and warn.
	 */
	public function test_images_deduped_missing_dropped_and_capped_at_20() {
		$a       = Product_Factory::attachment( 'tote.png', 'cap-a.png' );
		$b       = Product_Factory::attachment( 'tote.png', 'cap-b.png' );
		$deleted = Product_Factory::attachment( 'tote.png', 'cap-deleted.png' );
		wp_delete_attachment( $deleted, true );

		$more = array();
		foreach ( range( 'c', 'w' ) as $letter ) { // 21 more.
			$more[] = Product_Factory::attachment( 'tote.png', 'cap-' . $letter . '.png' );
		}
		$this->assertCount( 21, $more );

		$product = new WC_Product_Simple();
		$product->set_name( 'Many images' );
		$product->set_regular_price( '10.00' );
		$product->set_image_id( $a );
		$product->set_gallery_image_ids( array_merge( array( $a, $b, $deleted ), $more ) );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$serializer = new Product_Serializer();
		$data       = $serializer->serialize( $product, new Fetch_Image_Resolver() );
		$ids        = wp_list_pluck( $data['images'], 'attachment_id' );

		$this->assertSame( $a, $ids[0] );
		$this->assertSame( array( $a ), array_values( array_intersect( $ids, array( $a ) ) ) );
		$this->assertNotContains( $deleted, $ids );
		$this->assertCount( Product_Serializer::MAX_IMAGES, $ids );
		$this->assertSame( array_slice( array_merge( array( $a, $b ), $more ), 0, 20 ), $ids );
		$this->assertContains( 'IMAGES_TRUNCATED', wp_list_pluck( $serializer->warnings( $product->get_id() ), 'code' ) );
	}

	/**
	 * Exactly 20 usable images (with a missing one in the gallery) is not a truncation.
	 */
	public function test_twenty_images_with_a_missing_one_do_not_warn() {
		$deleted = Product_Factory::attachment( 'tote.png', 'cap-deleted.png' );
		wp_delete_attachment( $deleted, true );
		$ids = array();
		foreach ( range( 'a', 't' ) as $letter ) { // 20.
			$ids[] = Product_Factory::attachment( 'tote.png', 'cap-' . $letter . '.png' );
		}

		$product = new WC_Product_Simple();
		$product->set_name( 'Twenty images' );
		$product->set_regular_price( '10.00' );
		$product->set_image_id( $ids[0] );
		$product->set_gallery_image_ids( array_merge( array_slice( $ids, 1 ), array( $deleted ) ) );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$serializer = new Product_Serializer();
		$data       = $serializer->serialize( $product, new Fetch_Image_Resolver() );

		$this->assertCount( 20, $data['images'] );
		$this->assertSame( array(), $serializer->warnings( $product->get_id() ) );
	}

	/**
	 * Review Focus 5: entities decoded, Unicode kept unescaped in the encoded JSON.
	 */
	public function test_entities_decoded_and_unicode_preserved() {
		$category = Product_Factory::term( 'Áo thun & quần', 'product_cat' );
		$this->assertSame( 'Áo thun &amp; quần', get_term( $category, 'product_cat' )->name );

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Màu' );
		$attribute->set_options( array( 'Đỏ & Xanh' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		$product = new WC_Product_Simple();
		$product->set_name( 'Áo "Cổ tròn"' );
		$product->set_regular_price( '150000' );
		$product->set_category_ids( array( $category ) );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$data = ( new Product_Serializer() )->serialize( $product, new Fetch_Image_Resolver() );
		$json = Fixture_Normaliser::encode( $data );

		$this->assertSame( 'Áo thun & quần', $data['categories'][0]['name'] );
		$this->assertSame( 'Áo "Cổ tròn"', $data['name'] );
		$this->assertSame( array( 'Đỏ & Xanh' ), $data['attributes'][0]['options'] );
		$this->assertStringContainsString( 'Áo thun & quần', $json );
		$this->assertStringNotContainsString( '\u00', $json );
		$this->assertStringNotContainsString( '&amp;', $json );
	}

	/**
	 * A variation without its own SKU does not take the parent's.
	 */
	public function test_variation_sku_does_not_inherit_parent() {
		$color = new WC_Product_Attribute();
		$color->set_name( 'Color' );
		$color->set_options( array( 'Red' ) );
		$color->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Parent' );
		$parent->set_sku( 'P1' );
		$parent->set_attributes( array( $color ) );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'color' => 'Red' ) );
		$variation->set_sku( '' );
		$variation->set_regular_price( '5.00' );
		$variation->save();
		WC_Product_Variable::sync( $parent->get_id() );

		$this->assertSame( 'P1', wc_get_product( $variation->get_id() )->get_sku() ); // WC's view context falls back.

		$data = ( new Product_Serializer() )->serialize( wc_get_product( $parent->get_id() ), new Fetch_Image_Resolver() );
		$this->assertSame( 'P1', $data['sku'] );
		$this->assertNull( $data['variations'][0]['sku'] );
	}

	/**
	 * Empty price strings become null.
	 */
	public function test_empty_strings_become_null() {
		$product = new WC_Product_Simple();
		$product->set_name( 'No price' );
		$product->set_regular_price( '' );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$data = ( new Product_Serializer() )->serialize( $product, new Fetch_Image_Resolver() );
		$this->assertNull( $data['regular_price'] );
		$this->assertNull( $data['sale_price'] );
		$this->assertNull( $data['price'] );
	}

	/**
	 * Keys are exactly the contract's, in its order.
	 */
	public function test_keys_follow_contract_order() {
		$data = ( new Product_Serializer() )->serialize( Product_Factory::variable(), new Fetch_Image_Resolver() );

		$this->assertSame(
			array( 'schema_version', 'id', 'type', 'status', 'name', 'slug', 'description', 'short_description', 'sku', 'price', 'regular_price', 'sale_price', 'on_sale', 'manage_stock', 'stock_quantity', 'backorders', 'stock_status', 'weight', 'categories', 'tags', 'images', 'attributes', 'variations' ),
			array_keys( $data )
		);
		$this->assertSame(
			array( 'id', 'status', 'sku', 'price', 'regular_price', 'sale_price', 'on_sale', 'manage_stock', 'stock_quantity', 'backorders', 'stock_status', 'weight', 'image', 'attributes' ),
			array_keys( $data['variations'][0] )
		);
	}
}
