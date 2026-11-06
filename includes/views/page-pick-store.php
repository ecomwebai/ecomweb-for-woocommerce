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
?>
<div class="ecomweb-card ecomweb-header">
	<p>
		<?php
		/* translators: %s: the merchant's ecomweb email. */
		echo esc_html( sprintf( __( 'Signed in as %s', 'ecomweb-for-woocommerce' ), $email ) );
		?>
	</p>
	<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>">
		<input type="hidden" name="action" value="ecomweb_disconnect">
		<?php wp_nonce_field( 'ecomweb_disconnect' ); ?>
		<?php submit_button( __( 'Disconnect', 'ecomweb-for-woocommerce' ), 'secondary', 'submit', false ); ?>
	</form>
</div>

<div class="ecomweb-card">
	<?php if ( is_wp_error( $stores ) ) : ?>
		<p><?php echo esc_html( $stores->get_error_message() ); ?></p>
		<p><a class="button" href="<?php echo esc_url( Ecomweb\Woo\Admin_Page::url() ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a></p>
	<?php elseif ( empty( $stores ) ) : ?>
		<p><?php esc_html_e( "You don't have an ecomweb store yet.", 'ecomweb-for-woocommerce' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( Ecomweb\Woo\Config::dashboard_url() . '/store/signup' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a store', 'ecomweb-for-woocommerce' ); ?></a>
			<a class="button" href="<?php echo esc_url( Ecomweb\Woo\Admin_Page::url() ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a>
		</p>
	<?php else : ?>
		<?php if ( $changed ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Products copied to your previous store will be copied again to the new one.', 'ecomweb-for-woocommerce' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $ecomweb_post_url ); ?>">
			<input type="hidden" name="action" value="ecomweb_select_store">
			<?php wp_nonce_field( 'ecomweb_select_store' ); ?>
			<p><strong><?php esc_html_e( 'Choose the store to copy your products to', 'ecomweb-for-woocommerce' ); ?></strong></p>
			<ul class="ecomweb-stores">
				<?php foreach ( $stores as $ecomweb_store ) : ?>
					<li>
						<label>
							<input type="radio" name="store_ref" value="<?php echo esc_attr( $ecomweb_store['ref'] ); ?>"<?php echo $ecomweb_store['can_copy'] ? ' required' : ' disabled'; ?>>
							<?php echo esc_html( $ecomweb_store['name'] ); ?>
						</label>
						<?php if ( ! $ecomweb_store['can_copy'] ) : ?>
							<span class="description"> &mdash; <?php echo esc_html( (string) $ecomweb_store['reason'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php submit_button( __( 'Use this store', 'ecomweb-for-woocommerce' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( Ecomweb\Woo\Admin_Page::url() ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a>
		</form>
	<?php endif; ?>
</div>
