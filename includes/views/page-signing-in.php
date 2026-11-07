<?php
/**
 * Signing-in panel. The script fills the code and link after the sign-in starts.
 *
 * After a reload the code is not known (the server keeps only the device code), so the code area
 * stays hidden and the script just keeps polling.
 *
 * Variable: $resume (bool) from Admin_Page::render().
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="ecomweb-signing-in" class="ecomweb-card ecomweb-signing<?php echo $resume ? ' is-busy' : ''; ?>" data-resume="<?php echo $resume ? '1' : '0'; ?>"<?php echo $resume ? '' : ' hidden'; ?>>
	<div id="ecomweb-code-box" hidden>
		<h2 class="ecomweb-card__title"><?php esc_html_e( 'Approve the sign-in in EcomWeb', 'ecomweb-for-woocommerce' ); ?></h2>
		<p><?php esc_html_e( 'Check that EcomWeb shows the same code, then approve.', 'ecomweb-for-woocommerce' ); ?></p>
		<div class="ecomweb-code">
			<span class="ecomweb-code__label"><?php esc_html_e( 'Your code', 'ecomweb-for-woocommerce' ); ?></span>
			<code id="ecomweb-user-code" class="ecomweb-code__value"></code>
		</div>
		<p><a id="ecomweb-open" class="button button-primary" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open EcomWeb', 'ecomweb-for-woocommerce' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>
	</div>
	<div class="ecomweb-status-row">
		<span class="spinner" aria-hidden="true"></span>
		<p id="ecomweb-status" role="status" aria-live="polite"></p>
	</div>
	<p class="ecomweb-muted"><?php esc_html_e( "EcomWeb will show your web host's location, not yours.", 'ecomweb-for-woocommerce' ); ?></p>
</div>
