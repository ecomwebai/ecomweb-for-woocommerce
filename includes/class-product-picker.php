<?php
/**
 * One page of WooCommerce products for the plugin page's product list.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Picker
 */
class Product_Picker {

	const PER_PAGE = 20;
	const FILTERS  = array( 'all', 'no', 'yes' );

	/**
	 * Reads the list's search, filter and page from the request.
	 *
	 * @return array{search: string, filter: string, paged: int}
	 */
	public static function request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters, they change nothing.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter = isset( $_GET['copied'] ) ? sanitize_key( wp_unslash( $_GET['copied'] ) ) : 'all';
		$paged  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable
		return array(
			'search' => $search,
			'filter' => in_array( $filter, self::FILTERS, true ) ? $filter : 'all',
			'paged'  => max( 1, $paged ),
		);
	}

	/**
	 * One page of products, newest first.
	 *
	 * @param string $connection_id Connection ID, for the copied state.
	 * @param array  $args          From request().
	 * @return array{rows: array[], total: int, pages: int, paged: int}
	 */
	public static function page( string $connection_id, array $args ): array {
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => Product_Query::STATUSES,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => (int) $args['paged'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( '' !== $args['search'] ) {
			$query_args['s'] = $args['search'];
		}
		if ( 'all' !== $args['filter'] ) {
			$query_args['meta_query'] = array( Products_List::copied_clause( $connection_id, $args['filter'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the same filter as the Products list.
		}
		$query = new \WP_Query( $query_args );
		$rows  = array();
		foreach ( $query->posts as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$rows[] = self::row( $product, $connection_id );
		}
		return array(
			'rows'  => $rows,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'paged' => (int) $args['paged'],
		);
	}

	/**
	 * What the list shows for one product.
	 *
	 * @param \WC_Product $product       Product.
	 * @param string      $connection_id Connection ID.
	 * @return array
	 */
	private static function row( \WC_Product $product, string $connection_id ): array {
		$image_id = (int) $product->get_image_id();
		$image    = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : false;
		return array(
			'id'        => $product->get_id(),
			'name'      => $product->get_name(),
			'sku'       => (string) $product->get_sku(),
			'image'     => is_string( $image ) ? $image : '',
			'price'     => (string) $product->get_price_html(),
			'status'    => (string) $product->get_status(),
			'variable'  => $product->is_type( 'variable' ),
			'supported' => Product_Serializer::supports( $product ),
			'copied'    => Product_Meta::copied( $product->get_id(), $connection_id ),
			'edit'      => (string) get_edit_post_link( $product->get_id(), 'raw' ),
		);
	}
}
