<?php
/**
 * Account bar: who is signed in, and Disconnect.
 *
 * Variables: $email (string|null), and optionally $ecomweb_account_note (string) under the name.
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

$ecomweb_account_note = isset( $ecomweb_account_note ) ? (string) $ecomweb_account_note : '';
?>
<div class="ecomweb-account">
	<span class="ecomweb-account__avatar dashicons dashicons-admin-users" aria-hidden="true"></span>
	<div class="ecomweb-account__who">
		<p class="ecomweb-account__name">
			<?php
			if ( null === $email || '' === $email ) {
				esc_html_e( 'Signed in to EcomWeb', 'ecomweb-for-woocommerce' );
			} else {
				/* translators: %s: the merchant's ecomweb email. */
				echo esc_html( sprintf( __( 'Signed in as %s', 'ecomweb-for-woocommerce' ), $email ) );
			}
			?>
		</p>
		<?php if ( '' !== $ecomweb_account_note ) : ?>
			<p class="ecomweb-account__note"><?php echo esc_html( $ecomweb_account_note ); ?></p>
		<?php endif; ?>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ecomweb-disconnect-form" data-confirm="<?php echo esc_attr__( 'Disconnect from EcomWeb? Copied products stay in EcomWeb.', 'ecomweb-for-woocommerce' ); ?>">
		<input type="hidden" name="action" value="ecomweb_disconnect">
		<?php wp_nonce_field( 'ecomweb_disconnect' ); ?>
		<?php submit_button( __( 'Disconnect', 'ecomweb-for-woocommerce' ), 'secondary ecomweb-button-quiet', 'submit', false ); ?>
	</form>
</div>
