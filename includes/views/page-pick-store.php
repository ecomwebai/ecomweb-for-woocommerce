<?php
/**
 * Store picker, shown when signed in and no store is chosen.
 *
 * Variables: $stores (array|WP_Error), $email (string), $changed (bool) from Admin_Page::render().
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

$ecomweb_post_url = admin_url( 'admin-post.php' );
$ecomweb_refresh  = Ecomweb\Woo\Admin_Page::url();
require ECOMWEB_WOO_DIR . 'includes/views/partial-account.php';
?>
<div class="ecomweb-card ecomweb-pick">
	<?php if ( is_wp_error( $stores ) ) : ?>
		<p><?php echo esc_html( $stores->get_error_message() ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $ecomweb_refresh ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a></p>
	<?php elseif ( empty( $stores ) ) : ?>
		<h2 class="ecomweb-card__title"><?php esc_html_e( "You don't have an EcomWeb store yet.", 'ecomweb-for-woocommerce' ); ?></h2>
		<div class="ecomweb-actions">
			<a class="button button-primary" href="<?php echo esc_url( Ecomweb\Woo\Config::dashboard_url() . '/store/signup' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a store', 'ecomweb-for-woocommerce' ); ?></a>
			<a class="button" href="<?php echo esc_url( $ecomweb_refresh ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a>
		</div>
	<?php else : ?>
		<?php
		$ecomweb_copyable = array_values(
			array_filter(
				$stores,
				static function ( $s ) {
					return ! empty( $s['can_copy'] );
				}
			)
		);
		$ecomweb_only     = 1 === count( $ecomweb_copyable ) ? (string) $ecomweb_copyable[0]['ref'] : '';
		?>
		<?php if ( $changed ) : ?>
			<div class="notice notice-warning inline ecomweb-notice"><p><?php esc_html_e( 'Products copied to your previous store will be copied again to the new one.', 'ecomweb-for-woocommerce' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>">
			<input type="hidden" name="action" value="ecomweb_select_store">
			<?php wp_nonce_field( 'ecomweb_select_store' ); ?>
			<h2 class="ecomweb-card__title"><label for="ecomweb-store"><?php esc_html_e( 'Choose the store to copy your products to', 'ecomweb-for-woocommerce' ); ?></label></h2>
			<div class="ecomweb-field">
				<select id="ecomweb-store" name="store_ref" class="ecomweb-select" required>
					<option value=""><?php esc_html_e( 'Select a store', 'ecomweb-for-woocommerce' ); ?></option>
					<?php foreach ( $stores as $ecomweb_store ) : ?>
						<?php
						$ecomweb_label = (string) $ecomweb_store['name'];
						if ( ! $ecomweb_store['can_copy'] ) {
							/* translators: 1: store name, 2: why it can't be chosen. */
							$ecomweb_label = sprintf( __( '%1$s (%2$s)', 'ecomweb-for-woocommerce' ), $ecomweb_label, (string) $ecomweb_store['reason'] );
						}
						?>
						<option value="<?php echo esc_attr( $ecomweb_store['ref'] ); ?>"<?php selected( $ecomweb_only, (string) $ecomweb_store['ref'] ); ?><?php disabled( ! $ecomweb_store['can_copy'] ); ?>><?php echo esc_html( $ecomweb_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ecomweb-actions">
				<?php submit_button( __( 'Use this store', 'ecomweb-for-woocommerce' ), 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( $ecomweb_refresh ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a>
			</div>
		</form>
	<?php endif; ?>
</div>
