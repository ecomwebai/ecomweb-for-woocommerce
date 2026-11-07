<?php
/**
 * Builds the seven fixture products through WC CRUD.
 *
 * Uses only core WP/WC APIs (no WP_UnitTest_Factory), so it also runs from
 * `wp eval-file` in the cli environment.
 *
 * @package ecomweb-for-woocommerce
 */

/**
 * Class Product_Factory
 */
class Product_Factory {

	/**
	 * Simple product with categories, tags, two images and a custom attribute.
	 *
	 * @return WC_Product
	 */
	public static function simple() {
		$clothing = self::term( 'Clothing', 'product_cat', 0, 0 );
		$tees     = self::term( 'T-Shirts', 'product_cat', $clothing, 1 );

		return self::save(
			new WC_Product_Simple(),
			array(
				'name'              => 'Classic Cotton Tee',
				'slug'              => 'classic-cotton-tee',
				'status'            => 'publish',
				'description'       => "<p>Soft <strong>100% cotton</strong> tee.</p>\n<script>alert(1)</script>\n[caption width=\"300\"]Model is 180 cm[/caption]",
				'short_description' => 'Everyday tee.',
				'sku'               => 'TEE-CLASSIC',
				'regular_price'     => '19.99',
				'manage_stock'      => true,
				'stock_quantity'    => 42,
				'backorders'        => 'no',
				'weight'            => '0.2',
				'category_ids'      => array( $clothing, $tees ),
				'tag_ids'           => array( self::term( 'cotton', 'product_tag' ), self::term( 'summer', 'product_tag' ) ),
				'image_id'          => self::attachment( 'tee-front.png' ),
				'gallery_image_ids' => array( self::attachment( 'tee-back.png' ) ),
				'attributes'        => array( self::custom_attribute( 'Material', array( 'Cotton' ), false ) ),
			)
		);
	}

	/**
	 * Variable product: global Size x custom Color, four variations.
	 *
	 * @return WC_Product
	 */
	public static function variable() {
		$size    = self::global_attribute( 'Size', 'size', array( 'S', 'M' ) );
		$product = self::save(
			new WC_Product_Variable(),
			array(
				'name'         => 'Zip Hoodie',
				'status'       => 'publish',
				'weight'       => '0.5',
				'manage_stock' => false,
				'category_ids' => array( self::term( 'Clothing', 'product_cat' ) ),
				'image_id'     => self::attachment( 'hoodie-black.png' ),
				'attributes'   => array(
					self::taxonomy_attribute( $size, true ),
					self::custom_attribute( 'Color', array( 'Black', 'Grey' ), true ),
				),
			)
		);

		$grey_image = self::attachment( 'hoodie-grey.png' );
		$rows       = array(
			array(
				'S',
				'Black',
				'HD-S-BLK',
				array(
					'manage_stock'   => true,
					'stock_quantity' => 5,
				),
			),
			array( 'S', 'Grey', 'HD-S-GRY', array() ),
			array( 'M', 'Black', 'HD-M-BLK', array() ),
			array(
				'M',
				'Grey',
				'HD-M-GRY',
				array(
					'regular_price' => '42.00',
					'status'        => 'private',
					'image_id'      => $grey_image,
				),
			),
		);
		foreach ( $rows as $row ) {
			list( $size_name, $color, $sku, $extra ) = $row;
			self::variation(
				$product,
				array(
					$size['taxonomy'] => $size['slugs'][ $size_name ],
					'color'           => $color,
				),
				array_merge(
					array(
						'sku'           => $sku,
						'regular_price' => '39.00',
					),
					$extra
				)
			);
		}

		return self::sync( $product );
	}

	/**
	 * Variable product with an "any" Model on its first variation.
	 *
	 * @return WC_Product
	 */
	public static function variable_any() {
		$color   = self::global_attribute( 'Color', 'color', array( 'Red', 'Blue' ) );
		$product = self::save(
			new WC_Product_Variable(),
			array(
				'name'       => 'Phone Case',
				'status'     => 'publish',
				'attributes' => array(
					self::custom_attribute( 'Model', array( 'iPhone 15', 'iPhone 16' ), true ),
					self::taxonomy_attribute( $color, true ),
				),
			)
		);

		self::variation(
			$product,
			array(
				'model'            => '',
				$color['taxonomy'] => $color['slugs']['Red'],
			),
			array( 'regular_price' => '15.00' )
		);
		self::variation(
			$product,
			array(
				'model'            => 'iPhone 16',
				$color['taxonomy'] => $color['slugs']['Blue'],
			),
			array( 'regular_price' => '15.00' )
		);

		return self::sync( $product );
	}

	/**
	 * Simple product on sale, without a schedule.
	 *
	 * @return WC_Product
	 */
	public static function on_sale() {
		return self::save(
			new WC_Product_Simple(),
			array(
				'name'          => 'Canvas Tote',
				'status'        => 'publish',
				'sku'           => 'TOTE-01',
				'regular_price' => '25.00',
				'sale_price'    => '19.99',
				'manage_stock'  => false,
				'image_id'      => self::attachment( 'tote.png' ),
			)
		);
	}

	/**
	 * Simple product with tracked stock at zero.
	 *
	 * @return WC_Product
	 */
	public static function out_of_stock() {
		return self::save(
			new WC_Product_Simple(),
			array(
				'name'           => 'Leather Wallet',
				'status'         => 'publish',
				'sku'            => 'WAL-01',
				'regular_price'  => '45.00',
				'manage_stock'   => true,
				'stock_quantity' => 0,
				'backorders'     => 'no',
				'image_id'       => self::attachment( 'wallet.png' ),
			)
		);
	}

	/**
	 * Draft with no SKU, weight, images or categories (WC adds Uncategorized).
	 *
	 * @return WC_Product
	 */
	public static function no_images() {
		return self::save(
			new WC_Product_Simple(),
			array(
				'name'          => 'Gift Sleeve',
				'status'        => 'draft',
				'regular_price' => '5.00',
			)
		);
	}

	/**
	 * Variable product with four variation attributes.
	 *
	 * @return WC_Product
	 */
	public static function unsupported_axes() {
		$size    = self::global_attribute( 'Size', 'size', array( '41', '42' ) );
		$product = self::save(
			new WC_Product_Variable(),
			array(
				'name'       => 'Court Sneaker',
				'status'     => 'publish',
				'attributes' => array(
					self::taxonomy_attribute( $size, true ),
					self::custom_attribute( 'Color', array( 'White' ), true ),
					self::custom_attribute( 'Width', array( 'Regular' ), true ),
					self::custom_attribute( 'Material', array( 'Canvas' ), true ),
				),
			)
		);

		foreach ( array( '41', '42' ) as $size_name ) {
			self::variation(
				$product,
				array(
					$size['taxonomy'] => $size['slugs'][ $size_name ],
					'color'           => 'White',
					'width'           => 'Regular',
					'material'        => 'Canvas',
				),
				array( 'regular_price' => '60.00' )
			);
		}

		return self::sync( $product );
	}

	/**
	 * Copies a tests/assets image into uploads and registers it as an attachment.
	 * An earlier upload with the same name is deleted first, so WP never adds a -1 suffix.
	 *
	 * @param string $asset   File name in tests/assets.
	 * @param string $as_name Upload file name; defaults to $asset. Must not end in -<digits>.
	 * @return int Attachment ID.
	 * @throws RuntimeException When the copy or insert fails.
	 */
	public static function attachment( $asset, $as_name = '' ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$name   = '' !== $as_name ? $as_name : $asset;
		$upload = wp_upload_dir( null, true, true );
		$target = trailingslashit( $upload['path'] ) . $name;
		if ( file_exists( $target ) ) {
			wp_delete_file( $target );
		}
		if ( ! copy( dirname( __DIR__ ) . '/assets/' . $asset, $target ) ) {
			throw new RuntimeException( 'Could not copy asset ' . esc_html( $asset ) );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => pathinfo( $name, PATHINFO_FILENAME ),
				'post_status'    => 'inherit',
			),
			$target,
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( esc_html( $id->get_error_message() ) );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $target ) );

		return (int) $id;
	}

	/**
	 * Finds or creates a term by name, and pins its WC sort order.
	 *
	 * @param string $name      Term name.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $parent_id Parent term ID.
	 * @param int    $order     WC "order" term meta.
	 * @return int Term ID.
	 * @throws RuntimeException When the insert fails.
	 */
	public static function term( $name, $taxonomy, $parent_id = 0, $order = 0 ) {
		$existing = term_exists( $name, $taxonomy, $parent_id );
		if ( $existing ) {
			$id = (int) $existing['term_id'];
		} else {
			$result = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent_id ) );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( esc_html( $result->get_error_message() ) );
			}
			$id = (int) $result['term_id'];
		}
		update_term_meta( $id, 'order', $order );
		return $id;
	}

	/**
	 * Finds or creates a global attribute and its terms, in the given order.
	 *
	 * @param string   $label Attribute label.
	 * @param string   $slug  Attribute slug, without pa_.
	 * @param string[] $terms Term names.
	 * @return array{id:int, taxonomy:string, ids:int[], slugs:array<string,string>}
	 * @throws RuntimeException When the attribute can't be created.
	 */
	public static function global_attribute( $label, $slug, array $terms ) {
		global $wc_product_attributes;

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		$id       = wc_attribute_taxonomy_id_by_name( $taxonomy );
		if ( ! $id ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy ); // Registered by an earlier call whose row was rolled back; WC refuses the slug otherwise.
			}
			$id = wc_create_attribute(
				array(
					'name'     => $label,
					'slug'     => $slug,
					'type'     => 'select',
					'order_by' => 'menu_order',
				)
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( esc_html( $id->get_error_message() ) );
			}
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product', 'product_variation' ), array( 'hierarchical' => false ) );
		}
		foreach ( wc_get_attribute_taxonomies() as $row ) {
			if ( (int) $row->attribute_id === (int) $id ) {
				$wc_product_attributes[ $taxonomy ] = $row; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- mirrors WC's own registration in this request.
			}
		}

		$result = array(
			'id'       => (int) $id,
			'taxonomy' => $taxonomy,
			'ids'      => array(),
			'slugs'    => array(),
		);
		foreach ( array_values( $terms ) as $order => $name ) {
			$term_id                  = self::term( $name, $taxonomy, 0, $order );
			$result['ids'][]          = $term_id;
			$result['slugs'][ $name ] = get_term( $term_id, $taxonomy )->slug;
		}
		return $result;
	}

	/**
	 * Product attribute for a global taxonomy.
	 *
	 * @param array $global_attr Result of global_attribute().
	 * @param bool  $variation   Used for variations.
	 * @return WC_Product_Attribute
	 */
	private static function taxonomy_attribute( array $global_attr, $variation ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $global_attr['id'] );
		$attribute->set_name( $global_attr['taxonomy'] );
		$attribute->set_options( $global_attr['ids'] );
		$attribute->set_visible( true );
		$attribute->set_variation( $variation );
		return $attribute;
	}

	/**
	 * Custom (product-level) attribute.
	 *
	 * @param string   $name      Name.
	 * @param string[] $options   Values.
	 * @param bool     $variation Used for variations.
	 * @return WC_Product_Attribute
	 */
	private static function custom_attribute( $name, array $options, $variation ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $name );
		$attribute->set_options( $options );
		$attribute->set_visible( true );
		$attribute->set_variation( $variation );
		return $attribute;
	}

	/**
	 * Creates a variation of $product.
	 *
	 * @param WC_Product $product    Variable product.
	 * @param array      $attributes Attribute key => value ('' = any).
	 * @param array      $props      Setter name (without set_) => value.
	 */
	private static function variation( WC_Product $product, array $attributes, array $props ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( $attributes );
		self::save( $variation, $props );
	}

	/**
	 * Applies setters, saves, and returns a fresh copy from the database.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $props   Setter name (without set_) => value.
	 * @return WC_Product
	 */
	private static function save( WC_Product $product, array $props ) {
		foreach ( $props as $prop => $value ) {
			$product->{'set_' . $prop}( $value );
		}
		$product->save();
		return wc_get_product( $product->get_id() );
	}

	/**
	 * Syncs a variable product's prices and stock from its variations, and reloads it.
	 *
	 * @param WC_Product $product Variable product.
	 * @return WC_Product
	 */
	private static function sync( WC_Product $product ) {
		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
		return wc_get_product( $product->get_id() );
	}
}
