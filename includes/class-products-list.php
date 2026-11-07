<?php
/**
 * The WooCommerce products list: an ecomweb column, a copied filter and a bulk action.
 *
 * Reads local meta only. Nothing here calls the ecomweb API.
 *
 * @package ecomweb-for-woocommerce
 */

namespace Ecomweb\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Class Products_List
 */
class Products_List {

	const COLUMN = 'ecomweb';
	const FILTER = 'ecomweb_copied';
	const ACTION = 'ecomweb_copy';

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection Connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_filter( 'bulk_actions-edit-product', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Adds the column after the name.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function add_column( array $cols ): array {
		$label  = __( 'ecomweb', 'ecomweb-for-woocommerce' );
		$result = array();
		$added  = false;
		foreach ( $cols as $key => $title ) {
			$result[ $key ] = $title;
			if ( 'name' === $key ) {
				$result[ self::COLUMN ] = $label;
				$added                  = true;
			}
		}
		if ( ! $added ) {
			$result[ self::COLUMN ] = $label;
		}
		return $result;
	}

	/**
	 * Prints "Copied" (linked when the copy has a link) or a dash.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public function render_column( $col, $post_id ): void {
		if ( self::COLUMN !== $col ) {
			return;
		}
		$id     = $this->connection_id();
		$copied = null !== $id ? Product_Meta::copied( (int) $post_id, $id ) : null;
		if ( null === $copied ) {
			echo '&ndash;';
			return;
		}
		$url = null !== $copied['url'] ? esc_url( $copied['url'], array( 'http', 'https' ) ) : '';
		if ( '' === $url ) {
			echo esc_html__( 'Copied', 'ecomweb-for-woocommerce' );
			return;
		}
		printf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url, array( 'http', 'https' ) ),
			esc_html__( 'Copied', 'ecomweb-for-woocommerce' )
		);
	}

	/**
	 * Prints the copied / not copied filter.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function render_filter( $post_type ): void {
		if ( 'product' !== $post_type || null === $this->connection_id() ) {
			return;
		}
		$current = $this->requested_filter();
		$options = array(
			''    => __( 'ecomweb: all', 'ecomweb-for-woocommerce' ),
			'yes' => __( 'Copied', 'ecomweb-for-woocommerce' ),
			'no'  => __( 'Not copied', 'ecomweb-for-woocommerce' ),
		);
		echo '<select name="' . esc_attr( self::FILTER ) . '">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Applies the filter to the main product list query.
	 *
	 * @param \WP_Query $q Query.
	 * @return void
	 */
	public function filter_query( $q ): void {
		global $pagenow;
		if ( ! $q instanceof \WP_Query || ! is_admin() || ! $q->is_main_query() || 'edit.php' !== $pagenow || 'product' !== $q->get( 'post_type' ) ) {
			return;
		}
		$id     = $this->connection_id();
		$filter = $this->requested_filter();
		if ( null === $id || '' === $filter ) {
			return;
		}
		$clause = 'yes' === $filter
			? array(
				'key'   => Product_Meta::CONNECTION_ID,
				'value' => $id,
			)
			: array(
				'relation' => 'OR',
				array(
					'key'     => Product_Meta::CONNECTION_ID,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Product_Meta::CONNECTION_ID,
					'value'   => $id,
					'compare' => '!=',
				),
			);
		$meta   = $q->get( 'meta_query' );
		$meta   = is_array( $meta ) ? $meta : array();
		$meta[] = $clause;
		$q->set( 'meta_query', $meta );
	}

	/**
	 * Adds the bulk action, for users who can manage WooCommerce only.
	 *
	 * @param array $a Actions.
	 * @return array
	 */
	public function add_bulk_action( array $a ): array {
		if ( ! current_user_can( Admin_Page::CAPABILITY ) ) {
			return $a;
		}
		$a[ self::ACTION ] = __( 'Copy to ecomweb', 'ecomweb-for-woocommerce' );
		return $a;
	}

	/**
	 * Saves the chosen products and goes to the confirm screen.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param array  $ids      Selected IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $ids ): string {
		if ( self::ACTION !== $action ) {
			return (string) $redirect;
		}
		if ( ! current_user_can( Admin_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ecomweb-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		if ( null === $this->connection_id() ) {
			return Admin_Page::url( array( 'notice' => 'not_connected' ) );
		}
		$split = Product_Query::split( array_map( 'absint', (array) $ids ) );
		Selection::save( get_current_user_id(), $split['supported'], true, count( $split['not_supported'] ) );
		return Admin_Page::url( array( 'view' => 'confirm' ) );
	}

	/**
	 * The current connection ID, or null.
	 *
	 * @return string|null
	 */
	private function connection_id(): ?string {
		$connection = $this->connection->current();
		return null === $connection ? null : (string) $connection['connection_id'];
	}

	/**
	 * The requested filter value: yes, no or empty.
	 *
	 * @return string
	 */
	private function requested_filter(): string {
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, changes nothing.
		return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
	}
}
