<?php
/**
 * Golden-file tests for the WooProduct v1 fixtures shared with the ecomweb API.
 *
 * Regenerate with:
 * npx wp-env run tests-cli --env-cwd=wp-content/plugins/ecomweb-for-woocommerce env ECOMWEB_UPDATE_FIXTURES=1 vendor/bin/phpunit --filter Test_Serializer_Fixtures
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Fetch_Image_Resolver;
use Ecomweb\Woo\Product_Serializer;

/**
 * Class Test_Serializer_Fixtures
 */
class Test_Serializer_Fixtures extends WP_UnitTestCase {

	/**
	 * Pins the settings the fixtures depend on.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'uploads_use_yearmonth_folders', 0 );
		update_option( 'woocommerce_weight_unit', 'kg' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Fixture file, canonical product id, Product_Factory method.
	 *
	 * @return array
	 */
	public function fixtures() {
		return array(
			array( 'simple', 101, 'simple' ),
			array( 'variable', 102, 'variable' ),
			array( 'variable-any', 103, 'variable_any' ),
			array( 'on-sale', 104, 'on_sale' ),
			array( 'out-of-stock', 105, 'out_of_stock' ),
			array( 'no-images', 106, 'no_images' ),
			array( 'unsupported-axes', 107, 'unsupported_axes' ),
		);
	}

	/**
	 * The serialised, normalised product is byte-identical to its golden file.
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $file    Fixture name, without .json.
	 * @param int    $p       Canonical product id.
	 * @param string $factory Product_Factory method.
	 */
	public function test_fixture_matches( $file, $p, $factory ) {
		$product = Product_Factory::$factory();
		$payload = ( new Product_Serializer() )->serialize( $product, new Fetch_Image_Resolver() );
		$json    = Fixture_Normaliser::encode( Fixture_Normaliser::normalise( $payload, $p ) );
		$path    = dirname( __DIR__ ) . '/fixtures/' . $file . '.json';

		if ( getenv( 'ECOMWEB_UPDATE_FIXTURES' ) ) {
			file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test-only golden file writer.
		}

		$this->assertFileExists( $path );
		$this->assertSame( file_get_contents( $path ), $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture file.
	}

	/**
	 * Simple product shape, independent of the golden file.
	 */
	public function test_simple_shape() {
		$product = Product_Factory::simple();
		$data    = $this->serialize( $product );

		$this->assertSame( 1, $data['schema_version'] );
		$this->assertSame( 'simple', $data['type'] );
		$this->assertSame( '19.99', $data['price'] );
		$this->assertNull( $data['sale_price'] );
		$this->assertFalse( $data['on_sale'] );
		$this->assertSame( 42, $data['stock_quantity'] );
		$this->assertSame( array(), $data['variations'] );
		$this->assertStringContainsString( '<script>alert(1)</script>', $data['description'] );
		$this->assertStringContainsString( '[caption', $data['description'] );
		$this->assertSame( (int) $product->get_image_id(), $data['images'][0]['attachment_id'] );

		$this->assertSame( array( 'Clothing', 'T-Shirts' ), wp_list_pluck( $data['categories'], 'name' ) );
		$this->assertSame( $data['categories'][0]['id'], $data['categories'][1]['parent'] );

		$this->assertSame(
			array(
				'name'      => 'Material',
				'taxonomy'  => null,
				'variation' => false,
				'options'   => array( 'Cotton' ),
			),
			$data['attributes'][0]
		);
	}

	/**
	 * On sale: price is the sale price.
	 */
	public function test_on_sale_price_is_sale_price() {
		$data = $this->serialize( Product_Factory::on_sale() );

		$this->assertSame( '19.99', $data['price'] );
		$this->assertSame( '25.00', $data['regular_price'] );
		$this->assertSame( '19.99', $data['sale_price'] );
		$this->assertTrue( $data['on_sale'] );
	}

	/**
	 * Variations: status, own stock, inherited weight, own SKU, display attributes, own image.
	 */
	public function test_variable_variations() {
		$data       = $this->serialize( Product_Factory::variable() );
		$variations = array_column( $data['variations'], null, 'sku' );

		$this->assertCount( 4, $data['variations'] );
		$this->assertSame( 'private', $variations['HD-M-GRY']['status'] );
		$this->assertSame( 'publish', $variations['HD-S-BLK']['status'] );

		$this->assertTrue( $variations['HD-S-BLK']['manage_stock'] );
		$this->assertSame( 5, $variations['HD-S-BLK']['stock_quantity'] );
		foreach ( array( 'HD-S-GRY', 'HD-M-BLK', 'HD-M-GRY' ) as $sku ) {
			$this->assertFalse( $variations[ $sku ]['manage_stock'], $sku );
		}

		foreach ( $data['variations'] as $variation ) {
			$this->assertSame( '0.5', $variation['weight'] );
		}

		$this->assertSame(
			array(
				array(
					'name'   => 'Size',
					'option' => 'S',
				),
				array(
					'name'   => 'Color',
					'option' => 'Black',
				),
			),
			$variations['HD-S-BLK']['attributes']
		);

		$this->assertNotNull( $variations['HD-M-GRY']['image'] );
		foreach ( array( 'HD-S-BLK', 'HD-S-GRY', 'HD-M-BLK' ) as $sku ) {
			$this->assertNull( $variations[ $sku ]['image'], $sku );
		}
	}

	/**
	 * An "any" variation value is null.
	 */
	public function test_variable_any_option_null() {
		$data = $this->serialize( Product_Factory::variable_any() );

		$this->assertSame(
			array(
				'name'   => 'Model',
				'option' => null,
			),
			$data['variations'][0]['attributes'][0]
		);
	}

	/**
	 * Out of stock.
	 */
	public function test_out_of_stock() {
		$data = $this->serialize( Product_Factory::out_of_stock() );

		$this->assertTrue( $data['manage_stock'] );
		$this->assertSame( 0, $data['stock_quantity'] );
		$this->assertSame( 'no', $data['backorders'] );
		$this->assertSame( 'outofstock', $data['stock_status'] );
	}

	/**
	 * No images, no SKU, no weight; the default category is dropped.
	 */
	public function test_no_images() {
		$data = $this->serialize( Product_Factory::no_images() );

		$this->assertSame( array(), $data['images'] );
		$this->assertNull( $data['sku'] );
		$this->assertNull( $data['weight'] );
		$this->assertSame( array(), $data['categories'] );
		$this->assertSame( 'draft', $data['status'] );
	}

	/**
	 * Four variation axes are still serialised; the API rejects them with TOO_MANY_VARIANT_AXES.
	 */
	public function test_unsupported_axes_still_serialised() {
		$data = $this->serialize( Product_Factory::unsupported_axes() );

		$this->assertCount( 4, $data['attributes'] );
		foreach ( $data['attributes'] as $attribute ) {
			$this->assertTrue( $attribute['variation'], $attribute['name'] );
		}
	}

	/**
	 * Serialises with the fetch resolver.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private function serialize( WC_Product $product ) {
		$data = ( new Product_Serializer() )->serialize( $product, new Fetch_Image_Resolver() );
		$this->assertIsArray( $data );
		return $data;
	}
}
