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
<div id="ecomweb-signing-in" class="ecomweb-card" data-resume="<?php echo $resume ? '1' : '0'; ?>"<?php echo $resume ? '' : ' hidden'; ?>>
	<div id="ecomweb-code-box" hidden>
		<p class="ecomweb-code-line">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: the sign-in code, in bold. */
					__( 'Your code: %s', 'ecomweb-for-woocommerce' ),
					'<strong><code id="ecomweb-user-code"></code></strong>'
				),
				array(
					'strong' => array(),
					'code'   => array( 'id' => array() ),
				)
			);
			?>
		</p>
		<p><?php esc_html_e( 'Check that ecomweb shows the same code, then approve.', 'ecomweb-for-woocommerce' ); ?></p>
		<p><a id="ecomweb-open" class="button" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open ecomweb', 'ecomweb-for-woocommerce' ); ?></a></p>
	</div>
	<p class="description"><?php esc_html_e( "ecomweb will show your web host's location, not yours.", 'ecomweb-for-woocommerce' ); ?></p>
	<p id="ecomweb-status" role="status" aria-live="polite"></p>
</div>
