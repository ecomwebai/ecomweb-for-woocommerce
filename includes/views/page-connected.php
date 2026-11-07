<?php
/**
 * Connected view: who is signed in, the store, the copy button, progress and results.
 *
 * Variables from Admin_Page::render_connected(): $email (string|null), $connection (array),
 * $state (array|null, the saved copy state), $products and $list_args (the product list).
 *
 * @package ecomweb-for-woocommerce
 */

use Ecomweb\Woo\Copy_State;
use Ecomweb\Woo\Messages;

defined( 'ABSPATH' ) || exit;

$ecomweb_post_url = admin_url( 'admin-post.php' );
$ecomweb_status   = null === $state ? '' : (string) ( $state['status'] ?? '' );
$ecomweb_running  = 'running' === $ecomweb_status;
$ecomweb_counts   = null === $state ? array() : (array) ( $state['counts'] ?? array() );
$ecomweb_count    = static function ( string $key ) use ( $ecomweb_counts ): int {
	return (int) ( $ecomweb_counts[ $key ] ?? 0 );
};
$ecomweb_store    = (array) ( $connection['store'] ?? array() );
$ecomweb_dash     = esc_url( (string) ( $ecomweb_store['dashboard_url'] ?? '' ), array( 'http', 'https' ) );
$ecomweb_issues   = null === $state ? array() : (array) ( $state['issues'] ?? array() );
$ecomweb_on_sale  = array();
foreach ( $ecomweb_issues as $ecomweb_id => $ecomweb_issue ) {
	foreach ( (array) ( $ecomweb_issue['warnings'] ?? array() ) as $ecomweb_warning ) {
		if ( is_array( $ecomweb_warning ) && 'ON_SALE_REGULAR_PRICE_NOT_COPIED' === ( $ecomweb_warning['code'] ?? '' ) ) {
			$ecomweb_on_sale[ $ecomweb_id ] = $ecomweb_issue;
			break;
		}
	}
}
$ecomweb_results      = array(
	'created' => __( 'Created', 'ecomweb-for-woocommerce' ),
	'updated' => __( 'Updated', 'ecomweb-for-woocommerce' ),
	'skipped' => __( 'Skipped', 'ecomweb-for-woocommerce' ),
	'failed'  => __( 'Failed', 'ecomweb-for-woocommerce' ),
);
$ecomweb_account_note = __( 'Everyone who can manage WooCommerce on this site can copy products as this EcomWeb account.', 'ecomweb-for-woocommerce' );
require ECOMWEB_WOO_DIR . 'includes/views/partial-account.php';
?>

<div class="ecomweb-card ecomweb-connection">
	<div class="ecomweb-connection__store">
		<span class="ecomweb-connection__icon dashicons dashicons-store" aria-hidden="true"></span>
		<div>
			<p class="ecomweb-connection__name">
				<?php
				$ecomweb_name = (string) ( $ecomweb_store['name'] ?? '' );
				if ( '' !== $ecomweb_dash ) {
					/* translators: %s: store name. */
					$ecomweb_label = sprintf( esc_html__( 'Store: %s', 'ecomweb-for-woocommerce' ), '<a href="' . esc_url( $ecomweb_dash ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $ecomweb_name ) . '</a>' );
					echo wp_kses(
						$ecomweb_label,
						array(
							'a' => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
						)
					);
				} else {
					/* translators: %s: store name. */
					echo esc_html( sprintf( __( 'Store: %s', 'ecomweb-for-woocommerce' ), $ecomweb_name ) );
				}
				?>
			</p>
			<p class="ecomweb-muted">
				<?php
				if ( 'upload' === ( $connection['images_mode'] ?? '' ) ) {
					esc_html_e( "Images: this site uploads them to EcomWeb (EcomWeb can't reach this site).", 'ecomweb-for-woocommerce' );
				} else {
					esc_html_e( 'Images: EcomWeb downloads them from this site.', 'ecomweb-for-woocommerce' );
				}
				?>
			</p>
		</div>
	</div>
	<div class="ecomweb-actions">
		<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>">
			<input type="hidden" name="action" value="ecomweb_recheck_images">
			<?php wp_nonce_field( 'ecomweb_recheck_images' ); ?>
			<?php submit_button( __( 'Recheck images', 'ecomweb-for-woocommerce' ), 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>">
			<input type="hidden" name="action" value="ecomweb_change_store">
			<?php wp_nonce_field( 'ecomweb_change_store' ); ?>
			<?php submit_button( __( 'Change store', 'ecomweb-for-woocommerce' ), 'secondary', 'submit', false, $ecomweb_running ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
	</div>
</div>

<div class="ecomweb-card ecomweb-copy" id="ecomweb-copy" data-status="<?php echo esc_attr( $ecomweb_status ); ?>">
	<div class="ecomweb-card__head">
		<div>
			<h2 class="ecomweb-card__title"><?php esc_html_e( 'Copy products', 'ecomweb-for-woocommerce' ); ?></h2>
			<p class="ecomweb-muted"><?php esc_html_e( 'Tick the products to copy, or copy every product not copied yet.', 'ecomweb-for-woocommerce' ); ?></p>
		</div>
		<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>">
			<input type="hidden" name="action" value="ecomweb_prepare_copy_all">
			<?php wp_nonce_field( 'ecomweb_prepare_copy_all' ); ?>
			<?php submit_button( __( 'Copy all products not copied yet', 'ecomweb-for-woocommerce' ), 'secondary', 'submit', false, $ecomweb_running ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
	</div>

	<?php if ( $ecomweb_running ) : ?>
		<?php
		$ecomweb_done  = Copy_State::done( (array) $state );
		$ecomweb_total = (int) ( $state['total'] ?? 0 );
		$ecomweb_pct   = $ecomweb_total > 0 ? min( 100, (int) round( 100 * $ecomweb_done / $ecomweb_total ) ) : 0;
		?>
		<div class="ecomweb-run">
			<div class="ecomweb-run__head">
				<span class="spinner is-active" aria-hidden="true"></span>
				<p id="ecomweb-progress">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: products handled so far, 2: products in this copy. */
							__( 'Copying %1$d of %2$d…', 'ecomweb-for-woocommerce' ),
							$ecomweb_done,
							$ecomweb_total
						)
					);
					?>
				</p>
			</div>
			<div class="ecomweb-bar" aria-hidden="true"><span id="ecomweb-bar" style="width: <?php echo esc_attr( (string) $ecomweb_pct ); ?>%"></span></div>
			<p id="ecomweb-counts">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: created, 2: updated, 3: skipped (already copied), 4: failed. */
						__( '%1$d created, %2$d updated, %3$d skipped (already copied), %4$d failed.', 'ecomweb-for-woocommerce' ),
						$ecomweb_count( 'created' ),
						$ecomweb_count( 'updated' ),
						$ecomweb_count( 'skipped' ),
						$ecomweb_count( 'failed' )
					)
				);
				?>
			</p>
			<p id="ecomweb-poll-note" class="ecomweb-muted" hidden></p>
			<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>" id="ecomweb-stop-form">
				<input type="hidden" name="action" value="ecomweb_stop_copy">
				<?php wp_nonce_field( 'ecomweb_stop_copy' ); ?>
				<?php submit_button( __( 'Stop copy', 'ecomweb-for-woocommerce' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
	<?php elseif ( 'completed' === $ecomweb_status ) : ?>
		<div class="ecomweb-result is-success">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p id="ecomweb-progress">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: when the copy finished, 2: created, 3: updated, 4: skipped (already copied), 5: failed, 6: not supported. */
						__( 'Last copy finished %1$s: %2$d created, %3$d updated, %4$d skipped (already copied), %5$d failed, %6$d not supported.', 'ecomweb-for-woocommerce' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $state['finished_at'] ?? time() ) ),
						$ecomweb_count( 'created' ),
						$ecomweb_count( 'updated' ),
						$ecomweb_count( 'skipped' ),
						$ecomweb_count( 'failed' ),
						$ecomweb_count( 'not_supported' )
					)
				);
				?>
			</p>
		</div>
	<?php elseif ( 'failed' === $ecomweb_status ) : ?>
		<div class="notice notice-error inline ecomweb-notice" id="ecomweb-progress"><p><?php echo esc_html( Messages::error( (array) ( $state['error'] ?? array() ) ) ); ?></p></div>
	<?php elseif ( 'cancelled' === $ecomweb_status ) : ?>
		<div class="ecomweb-result">
			<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
			<p id="ecomweb-progress"><?php esc_html_e( 'The last copy was cancelled.', 'ecomweb-for-woocommerce' ); ?></p>
		</div>
	<?php endif; ?>

	<?php require ECOMWEB_WOO_DIR . 'includes/views/partial-product-list.php'; ?>
</div>

<?php if ( ! empty( $ecomweb_issues ) ) : ?>
<div class="ecomweb-card ecomweb-issues">
	<h2 class="ecomweb-card__title"><?php esc_html_e( 'Products to review', 'ecomweb-for-woocommerce' ); ?></h2>
	<table class="widefat striped ecomweb-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'ecomweb-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Result', 'ecomweb-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Details', 'ecomweb-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $ecomweb_issues as $ecomweb_id => $ecomweb_issue ) : ?>
			<?php
			$ecomweb_issue = (array) $ecomweb_issue;
			$ecomweb_edit  = get_post( (int) $ecomweb_id ) ? get_edit_post_link( (int) $ecomweb_id, 'raw' ) : null;
			$ecomweb_url   = esc_url( (string) ( $ecomweb_issue['url'] ?? '' ), array( 'http', 'https' ) );
			$ecomweb_res   = (string) ( $ecomweb_issue['result'] ?? '' );
			?>
			<tr>
				<td>
					<?php if ( $ecomweb_edit ) : ?>
						<a href="<?php echo esc_url( $ecomweb_edit ); ?>"><?php echo esc_html( (string) ( $ecomweb_issue['name'] ?? '' ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( (string) ( $ecomweb_issue['name'] ?? '' ) ); ?>
					<?php endif; ?>
				</td>
				<td><span class="ecomweb-badge is-<?php echo esc_attr( $ecomweb_res ); ?>"><?php echo esc_html( $ecomweb_results[ $ecomweb_res ] ?? $ecomweb_res ); ?></span></td>
				<td>
					<?php if ( ! empty( $ecomweb_issue['error'] ) ) : ?>
						<div><?php echo esc_html( Messages::error( (array) $ecomweb_issue['error'] ) ); ?></div>
					<?php endif; ?>
					<?php foreach ( Messages::warnings( $ecomweb_issue ) as $ecomweb_text ) : ?>
						<div><?php echo esc_html( $ecomweb_text ); ?></div>
					<?php endforeach; ?>
					<?php if ( '' !== $ecomweb_url ) : ?>
						<div><a href="<?php echo esc_url( $ecomweb_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in EcomWeb', 'ecomweb-for-woocommerce' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<?php if ( ! empty( $ecomweb_on_sale ) ) : ?>
<div class="ecomweb-card ecomweb-on-sale">
	<h2 class="ecomweb-card__title"><?php esc_html_e( 'Recreate these sales as promotions in EcomWeb', 'ecomweb-for-woocommerce' ); ?></h2>
	<ul class="ecomweb-list">
		<?php foreach ( $ecomweb_on_sale as $ecomweb_id => $ecomweb_issue ) : ?>
			<?php
			$ecomweb_sale_warning = array( 'code' => 'ON_SALE_REGULAR_PRICE_NOT_COPIED' );
			$ecomweb_edit         = get_post( (int) $ecomweb_id ) ? get_edit_post_link( (int) $ecomweb_id, 'raw' ) : null;
			?>
			<li>
				<?php if ( $ecomweb_edit ) : ?>
					<a href="<?php echo esc_url( $ecomweb_edit ); ?>"><?php echo esc_html( (string) ( $ecomweb_issue['name'] ?? '' ) ); ?></a>
				<?php else : ?>
					<?php echo esc_html( (string) ( $ecomweb_issue['name'] ?? '' ) ); ?>
				<?php endif; ?>
				&ndash; <?php echo esc_html( Messages::warning( $ecomweb_sale_warning, (array) $ecomweb_issue ) ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>
