<?php
/**
 * Serialises a WC_Product into the WooProduct v1 shape (interfaces.md). The API does all mapping.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Serializer
 */
class Product_Serializer {

	const SCHEMA_VERSION  = 1;
	const MAX_IMAGES      = 20;
	const SUPPORTED_TYPES = array( 'simple', 'variable' );
	const STATUSES        = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Local warnings by product ID, from the last serialize() of that product.
	 *
	 * @var array<int, array[]>
	 */
	private $warnings = array();

	/**
	 * Whether the plugin sends this product: simple or variable, and not in the trash.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function supports( \WC_Product $product ): bool {
		return in_array( $product->get_type(), self::SUPPORTED_TYPES, true ) && 'trash' !== $product->get_status( 'edit' );
	}

	/**
	 * WooProduct payload, or null when the product isn't supported.
	 *
	 * @param \WC_Product    $product Product.
	 * @param Image_Resolver $images  Image resolver.
	 * @return array|null
	 */
	public function serialize( \WC_Product $product, Image_Resolver $images ): ?array {
		$id = $product->get_id();
		unset( $this->warnings[ $id ] );
		if ( ! self::supports( $product ) ) {
			return null;
		}

		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'id'                => $id,
			'type'              => $product->get_type(),
			'status'            => self::status( $product->get_status( 'edit' ) ),
			'name'              => self::decode( $product->get_name( 'edit' ) ),
			'slug'              => self::decode( $product->get_slug( 'edit' ) ),
			'description'       => (string) $product->get_description( 'edit' ),
			'short_description' => (string) $product->get_short_description( 'edit' ),
			'sku'               => self::nullable( $product->get_sku( 'edit' ) ),
			'price'             => self::nullable( $product->get_price() ),
			'regular_price'     => self::nullable( $product->get_regular_price( 'edit' ) ),
			'sale_price'        => self::nullable( $product->get_sale_price( 'edit' ) ),
			'on_sale'           => (bool) $product->is_on_sale(),
			'manage_stock'      => (bool) $product->get_manage_stock( 'edit' ),
			'stock_quantity'    => self::quantity( $product->get_stock_quantity( 'edit' ) ),
			'backorders'        => (string) $product->get_backorders( 'edit' ),
			'stock_status'      => (string) $product->get_stock_status( 'edit' ),
			'weight'            => self::nullable( $product->get_weight( 'edit' ) ),
			'categories'        => self::categories( $product ),
			'tags'              => self::tags( $product ),
			'images'            => $this->images( $product, $images ),
			'attributes'        => self::attributes( $product ),
			'variations'        => $product->is_type( 'variable' ) ? self::variations( $product, $images ) : array(),
		);
	}

	/**
	 * Local warnings from the last serialize() of a product (IMAGES_TRUNCATED).
	 *
	 * @param int $product_id Product ID.
	 * @return array[] `{code, message}` items.
	 */
	public function warnings( int $product_id ): array {
		return $this->warnings[ $product_id ] ?? array();
	}

	/**
	 * Featured image first, then the gallery: de-duplicated, unusable ones dropped, at most 20.
	 * Resolving stops at 20, so upload mode never uploads an image that would be cut.
	 *
	 * @param \WC_Product    $product  Product.
	 * @param Image_Resolver $resolver Resolver.
	 * @return array[]
	 */
	private function images( \WC_Product $product, Image_Resolver $resolver ): array {
		$ids = array_merge( array( $product->get_image_id( 'edit' ) ), $product->get_gallery_image_ids( 'edit' ) );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		$images = array();
		foreach ( $ids as $i => $attachment_id ) {
			if ( count( $images ) === self::MAX_IMAGES ) {
				if ( self::any_image( array_slice( $ids, $i ) ) ) {
					$this->warnings[ $product->get_id() ][] = array(
						'code'    => 'IMAGES_TRUNCATED',
						'message' => '',
					);
				}
				break;
			}
			$image = $resolver->resolve( $attachment_id, $product->get_id() );
			if ( null !== $image ) {
				$images[] = $image;
			}
		}
		return $images;
	}

	/**
	 * Whether any of these attachments is an image (so cutting them loses something).
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return bool
	 */
	private static function any_image( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( wp_attachment_is_image( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Assigned categories, without the default "Uncategorized".
	 *
	 * @param \WC_Product $product Product.
	 * @return array[]
	 */
	private static function categories( \WC_Product $product ): array {
		$default    = (int) get_option( 'default_product_cat', 0 );
		$categories = array();
		foreach ( $product->get_category_ids( 'edit' ) as $term_id ) {
			if ( (int) $term_id === $default ) {
				continue;
			}
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$categories[] = array(
				'id'     => (int) $term->term_id,
				'name'   => self::decode( $term->name ),
				'slug'   => $term->slug,
				'parent' => (int) $term->parent,
			);
		}
		return $categories;
	}

	/**
	 * Assigned tags.
	 *
	 * @param \WC_Product $product Product.
	 * @return array[]
	 */
	private static function tags( \WC_Product $product ): array {
		$tags = array();
		foreach ( $product->get_tag_ids( 'edit' ) as $term_id ) {
			$term = get_term( (int) $term_id, 'product_tag' );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tags[] = array(
				'id'   => (int) $term->term_id,
				'name' => self::decode( $term->name ),
				'slug' => $term->slug,
			);
		}
		return $tags;
	}

	/**
	 * Attributes with display names and display values.
	 *
	 * @param \WC_Product $product Product.
	 * @return array[]
	 */
	private static function attributes( \WC_Product $product ): array {
		$attributes = array();
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$taxonomy = $attribute->is_taxonomy() ? $attribute->get_name() : null;
			$options  = null !== $taxonomy
				? wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) )
				: $attribute->get_options();

			$attributes[] = array(
				'name'      => self::decode( wc_attribute_label( $attribute->get_name(), $product ) ),
				'taxonomy'  => $taxonomy,
				'variation' => (bool) $attribute->get_variation(),
				'options'   => array_values( array_map( array( self::class, 'decode' ), is_array( $options ) ? $options : array() ) ),
			);
		}
		return $attributes;
	}

	/**
	 * Variations in menu order.
	 *
	 * @param \WC_Product    $product Variable product.
	 * @param Image_Resolver $images  Image resolver.
	 * @return array[]
	 */
	private static function variations( \WC_Product $product, Image_Resolver $images ): array {
		$axes = array();
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_variation() ) {
				$axes[] = array(
					'key'      => sanitize_title( $attribute->get_name() ),
					'taxonomy' => $attribute->is_taxonomy() ? $attribute->get_name() : null,
					'label'    => self::decode( wc_attribute_label( $attribute->get_name(), $product ) ),
				);
			}
		}

		$variations = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}
			$image_id = (int) $variation->get_image_id( 'edit' );

			$variations[] = array(
				'id'             => $variation->get_id(),
				'status'         => 'private' === $variation->get_status( 'edit' ) ? 'private' : 'publish',
				'sku'            => self::nullable( $variation->get_sku( 'edit' ) ),
				'price'          => self::nullable( $variation->get_price() ),
				'regular_price'  => self::nullable( $variation->get_regular_price( 'edit' ) ),
				'sale_price'     => self::nullable( $variation->get_sale_price( 'edit' ) ),
				'on_sale'        => (bool) $variation->is_on_sale(),
				'manage_stock'   => true === $variation->get_manage_stock( 'edit' ),
				'stock_quantity' => self::quantity( $variation->get_stock_quantity( 'edit' ) ),
				'backorders'     => (string) $variation->get_backorders( 'edit' ),
				'stock_status'   => (string) $variation->get_stock_status( 'edit' ),
				'weight'         => self::nullable( $variation->get_weight() ),
				'image'          => $image_id > 0 ? $images->resolve( $image_id, $product->get_id() ) : null,
				'attributes'     => self::variation_attributes( $variation, $axes ),
			);
		}
		return $variations;
	}

	/**
	 * One `{name, option}` per parent variation attribute; option null means "any".
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param array[]               $axes      Parent variation attributes: key, taxonomy, label.
	 * @return array[]
	 */
	private static function variation_attributes( \WC_Product_Variation $variation, array $axes ): array {
		$values     = $variation->get_attributes( 'edit' );
		$attributes = array();
		foreach ( $axes as $axis ) {
			$value  = isset( $values[ $axis['key'] ] ) ? (string) $values[ $axis['key'] ] : '';
			$option = null;
			if ( '' !== $value ) {
				$option = $value;
				if ( null !== $axis['taxonomy'] ) {
					$term   = get_term_by( 'slug', $value, $axis['taxonomy'] );
					$option = $term instanceof \WP_Term ? $term->name : $value;
				}
				$option = self::decode( $option );
			}
			$attributes[] = array(
				'name'   => $axis['label'],
				'option' => $option,
			);
		}
		return $attributes;
	}

	/**
	 * Contract status: future is sent as draft; anything unexpected as draft too.
	 *
	 * @param string $status Post status.
	 * @return string
	 */
	private static function status( string $status ): string {
		return in_array( $status, self::STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * Decodes HTML entities to UTF-8 text.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	private static function decode( $text ): string {
		return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * '' → null, otherwise the value as a string.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function nullable( $value ): ?string {
		$value = (string) $value;
		return '' === $value ? null : $value;
	}

	/**
	 * Stock quantity as int, or null when unset.
	 *
	 * @param mixed $quantity Quantity.
	 * @return int|null
	 */
	private static function quantity( $quantity ): ?int {
		return null === $quantity || '' === $quantity ? null : (int) $quantity;
	}
}
