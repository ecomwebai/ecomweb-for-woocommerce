<?php
/**
 * Signed-out view: the sign-in button, and the signing-in panel the script fills.
 *
 * Variables: $notice (string), $resume (bool) from Admin_Page::render().
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

if ( '' !== $notice ) {
	echo '<div class="notice notice-warning inline"><p>' . esc_html( $notice ) . '</p></div>';
}
?>
<div id="ecomweb-signed-out" class="ecomweb-card"<?php echo $resume ? ' hidden' : ''; ?>>
	<p><?php esc_html_e( 'Sign in with your ecomweb account to copy your WooCommerce products into an ecomweb store.', 'ecomweb-for-woocommerce' ); ?></p>
	<p><button type="button" class="button button-primary" id="ecomweb-sign-in"><?php esc_html_e( 'Sign in with ecomweb', 'ecomweb-for-woocommerce' ); ?></button></p>
	<p class="description"><?php esc_html_e( "ecomweb will show your web host's location, not yours.", 'ecomweb-for-woocommerce' ); ?></p>
</div>
<?php require ECOMWEB_WOO_DIR . 'includes/views/page-signing-in.php'; ?>
