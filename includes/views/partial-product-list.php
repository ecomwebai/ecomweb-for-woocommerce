<?php
/**
 * The product list on the connected page: tick products, then Copy selected.
 *
 * Variables from page-connected.php: $products (Product_Picker::page()), $list_args
 * (Product_Picker::request()), $ecomweb_running (bool), $ecomweb_post_url (string).
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Admin_Page;

defined( 'ABSPATH' ) || exit;

$ecomweb_list_url = static function ( array $args ) use ( $list_args ): string {
	$base = array(
		's'      => $list_args['search'],
		'copied' => $list_args['filter'],
	);
	$args = array_merge( $base, $args );
	if ( '' === $args['s'] ) {
		unset( $args['s'] );
	}
	if ( 'all' === $args['copied'] ) {
		unset( $args['copied'] );
	}
	if ( isset( $args['paged'] ) && 1 === (int) $args['paged'] ) {
		unset( $args['paged'] );
	}
	return Admin_Page::url( $args );
};
$ecomweb_tabs     = array(
	'all' => __( 'All', 'ecomweb-for-woocommerce' ),
	'no'  => __( 'Not copied', 'ecomweb-for-woocommerce' ),
	'yes' => __( 'Copied', 'ecomweb-for-woocommerce' ),
);
?>
<div class="ecomweb-list-tools">
	<ul class="ecomweb-tabs">
		<?php foreach ( $ecomweb_tabs as $ecomweb_key => $ecomweb_label ) : ?>
			<li>
				<a href="<?php echo esc_url( $ecomweb_list_url( array( 'copied' => $ecomweb_key ) ) ); ?>"<?php echo $ecomweb_key === $list_args['filter'] ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html( $ecomweb_label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ecomweb-search" role="search">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Page::SLUG ); ?>">
		<?php if ( 'all' !== $list_args['filter'] ) : ?>
			<input type="hidden" name="copied" value="<?php echo esc_attr( $list_args['filter'] ); ?>">
		<?php endif; ?>
		<label for="ecomweb-search" class="screen-reader-text"><?php esc_html_e( 'Search products', 'ecomweb-for-woocommerce' ); ?></label>
		<input type="search" id="ecomweb-search" name="s" value="<?php echo esc_attr( $list_args['search'] ); ?>" placeholder="<?php echo esc_attr__( 'Search products', 'ecomweb-for-woocommerce' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'ecomweb-for-woocommerce' ); ?></button>
	</form>
</div>

<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>" id="ecomweb-pick-form">
	<input type="hidden" name="action" value="ecomweb_prepare_copy_selected">
	<?php wp_nonce_field( 'ecomweb_prepare_copy_selected' ); ?>
	<table class="widefat ecomweb-table ecomweb-products">
		<thead>
			<tr>
				<td class="ecomweb-products__check">
					<input type="checkbox" id="ecomweb-check-all" aria-label="<?php echo esc_attr__( 'Select all products on this page', 'ecomweb-for-woocommerce' ); ?>"<?php disabled( $ecomweb_running ); ?>>
				</td>
				<th scope="col"><?php esc_html_e( 'Product', 'ecomweb-for-woocommerce' ); ?></th>
				<th scope="col" class="ecomweb-products__price"><?php esc_html_e( 'Price', 'ecomweb-for-woocommerce' ); ?></th>
				<th scope="col" class="ecomweb-products__state"><?php esc_html_e( 'EcomWeb', 'ecomweb-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $products['rows'] ) ) : ?>
			<tr><td colspan="4" class="ecomweb-products__empty"><?php esc_html_e( 'No products found.', 'ecomweb-for-woocommerce' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $products['rows'] as $ecomweb_row ) : ?>
			<?php
			$ecomweb_meta = array();
			if ( '' !== $ecomweb_row['sku'] ) {
				/* translators: %s: product SKU. */
				$ecomweb_meta[] = sprintf( __( 'SKU: %s', 'ecomweb-for-woocommerce' ), $ecomweb_row['sku'] );
			}
			if ( $ecomweb_row['variable'] ) {
				$ecomweb_meta[] = __( 'Variable product', 'ecomweb-for-woocommerce' );
			}
			if ( 'publish' !== $ecomweb_row['status'] ) {
				$ecomweb_status_obj = get_post_status_object( $ecomweb_row['status'] );
				$ecomweb_meta[]     = $ecomweb_status_obj ? (string) $ecomweb_status_obj->label : $ecomweb_row['status'];
			}
			$ecomweb_copied_url = null !== $ecomweb_row['copied'] && ! empty( $ecomweb_row['copied']['url'] ) ? esc_url( (string) $ecomweb_row['copied']['url'], array( 'http', 'https' ) ) : '';
			$ecomweb_check_id   = 'ecomweb-product-' . (int) $ecomweb_row['id'];
			?>
			<tr<?php echo $ecomweb_row['supported'] ? '' : ' class="is-unsupported"'; ?>>
				<th scope="row" class="ecomweb-products__check">
					<input type="checkbox" name="product_ids[]" value="<?php echo esc_attr( (string) $ecomweb_row['id'] ); ?>" id="<?php echo esc_attr( $ecomweb_check_id ); ?>"<?php disabled( ! $ecomweb_row['supported'] || $ecomweb_running ); ?>>
				</th>
				<td>
					<div class="ecomweb-product">
						<?php if ( '' !== $ecomweb_row['image'] ) : ?>
							<img src="<?php echo esc_url( $ecomweb_row['image'] ); ?>" alt="" width="40" height="40" loading="lazy">
						<?php else : ?>
							<span class="ecomweb-product__noimg dashicons dashicons-format-image" aria-hidden="true"></span>
						<?php endif; ?>
						<div class="ecomweb-product__text">
							<label for="<?php echo esc_attr( $ecomweb_check_id ); ?>" class="ecomweb-product__name"><?php echo esc_html( '' !== $ecomweb_row['name'] ? $ecomweb_row['name'] : __( '(no name)', 'ecomweb-for-woocommerce' ) ); ?></label>
							<?php if ( ! empty( $ecomweb_meta ) ) : ?>
								<span class="ecomweb-product__meta"><?php echo esc_html( implode( ' · ', $ecomweb_meta ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</td>
				<td class="ecomweb-products__price"><?php echo wp_kses_post( $ecomweb_row['price'] ); ?></td>
				<td class="ecomweb-products__state">
					<?php if ( ! $ecomweb_row['supported'] ) : ?>
						<span class="ecomweb-badge"><?php esc_html_e( "Can't be copied", 'ecomweb-for-woocommerce' ); ?></span>
					<?php elseif ( null !== $ecomweb_row['copied'] ) : ?>
						<?php if ( '' !== $ecomweb_copied_url ) : ?>
							<a class="ecomweb-badge is-created" href="<?php echo esc_url( $ecomweb_copied_url, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Copied', 'ecomweb-for-woocommerce' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
						<?php else : ?>
							<span class="ecomweb-badge is-created"><?php esc_html_e( 'Copied', 'ecomweb-for-woocommerce' ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span class="ecomweb-badge is-muted"><?php esc_html_e( 'Not copied', 'ecomweb-for-woocommerce' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<div class="ecomweb-list-foot">
		<button type="submit" class="button button-primary" id="ecomweb-copy-selected" disabled><?php esc_html_e( 'Copy selected', 'ecomweb-for-woocommerce' ); ?></button>
		<div class="ecomweb-pager">
			<span class="ecomweb-muted">
				<?php
				/* translators: %d: number of products in the list. */
				echo esc_html( sprintf( _n( '%d product', '%d products', $products['total'], 'ecomweb-for-woocommerce' ), $products['total'] ) );
				?>
			</span>
			<?php if ( $products['pages'] > 1 ) : ?>
				<?php if ( $products['paged'] > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( $ecomweb_list_url( array( 'paged' => $products['paged'] - 1 ) ) ); ?>" aria-label="<?php echo esc_attr__( 'Previous page', 'ecomweb-for-woocommerce' ); ?>">&lsaquo;</a>
				<?php endif; ?>
				<span>
					<?php
					/* translators: 1: current page, 2: number of pages. */
					echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'ecomweb-for-woocommerce' ), $products['paged'], $products['pages'] ) );
					?>
				</span>
				<?php if ( $products['paged'] < $products['pages'] ) : ?>
					<a class="button" href="<?php echo esc_url( $ecomweb_list_url( array( 'paged' => $products['paged'] + 1 ) ) ); ?>" aria-label="<?php echo esc_attr__( 'Next page', 'ecomweb-for-woocommerce' ); ?>">&rsaquo;</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</form>
