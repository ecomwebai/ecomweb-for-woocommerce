<?php
/**
 * Signed-out view: what the plugin does, the sign-in button, and the signing-in panel the script fills.
 *
 * Variables: $notice (string), $resume (bool) from Admin_Page::render().
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

if ( '' !== $notice ) {
	echo '<div class="notice notice-warning inline ecomweb-notice"><p>' . esc_html( $notice ) . '</p></div>';
}
?>
<div id="ecomweb-signed-out" class="ecomweb-card ecomweb-hero"<?php echo $resume ? ' hidden' : ''; ?>>
	<h2 class="ecomweb-hero__title"><?php esc_html_e( 'Copy your WooCommerce products to EcomWeb', 'ecomweb-for-woocommerce' ); ?></h2>
	<p class="ecomweb-hero__lead"><?php esc_html_e( 'Sign in with your EcomWeb account to copy your WooCommerce products into an EcomWeb store.', 'ecomweb-for-woocommerce' ); ?></p>
	<ul class="ecomweb-features">
		<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Products arrive in EcomWeb as drafts, with their images, prices, stock and variations.', 'ecomweb-for-woocommerce' ); ?></li>
		<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Copy all your products at once, or pick them in the Products list.', 'ecomweb-for-woocommerce' ); ?></li>
		<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Copy again any time: products already copied are skipped.', 'ecomweb-for-woocommerce' ); ?></li>
	</ul>
	<p class="ecomweb-hero__cta"><button type="button" class="button button-primary button-hero" id="ecomweb-sign-in"><?php esc_html_e( 'Sign in with EcomWeb', 'ecomweb-for-woocommerce' ); ?></button></p>
	<p class="ecomweb-muted"><?php esc_html_e( "EcomWeb will show your web host's location, not yours.", 'ecomweb-for-woocommerce' ); ?></p>
</div>
<?php require ECOMWEB_WOO_DIR . 'includes/views/page-signing-in.php'; ?>
