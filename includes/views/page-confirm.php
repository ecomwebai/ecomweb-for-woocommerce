<?php
/**
 * Confirm screen: what a copy would do, before it starts.
 *
 * Variables from Admin_Page::render_confirm(): $selection (array|null), $preflight (array|WP_Error|null),
 * $plan (array|null: the plan without overwrite), $all (array|null: the plan with overwrite).
 *
 * @package ecomweb-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

$ecomweb_back = Ecomweb\Woo\Admin_Page::url();
?>
<div class="ecomweb-card ecomweb-confirm">
	<h2 class="ecomweb-card__title"><?php esc_html_e( 'Review the copy', 'ecomweb-for-woocommerce' ); ?></h2>
<?php if ( null === $selection ) : ?>
	<p><?php esc_html_e( 'Choose the products to copy first.', 'ecomweb-for-woocommerce' ); ?></p>
	<div class="ecomweb-actions"><a class="button" href="<?php echo esc_url( $ecomweb_back ); ?>"><?php esc_html_e( 'Cancel', 'ecomweb-for-woocommerce' ); ?></a></div>
<?php elseif ( is_wp_error( $preflight ) ) : ?>
	<p><?php echo esc_html( $preflight->get_error_message() ); ?></p>
	<div class="ecomweb-actions">
		<a class="button" href="<?php echo esc_url( Ecomweb\Woo\Admin_Page::url( array( 'view' => 'confirm' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a>
		<a class="button" href="<?php echo esc_url( $ecomweb_back ); ?>"><?php esc_html_e( 'Cancel', 'ecomweb-for-woocommerce' ); ?></a>
	</div>
<?php else : ?>
	<?php
	$ecomweb_new      = count( $preflight['products'] ) - $plan['already_copied'] - $plan['deleted'];
	$ecomweb_currency = (array) ( $preflight['currency'] ?? array() );
	$ecomweb_no_room  = $plan['over_cap'] && 0 === $plan['fits'];
	$ecomweb_upgrade  = $plan['over_cap'] ? esc_url( (string) ( $preflight['upgrade_url'] ?? '' ), array( 'http', 'https' ) ) : '';
	?>
	<p class="ecomweb-lead">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: products selected, 2: new, 3: already in ecomweb, 4: deleted in ecomweb. */
				_n(
					'%1$d product selected: %2$d new, %3$d already in EcomWeb, %4$d deleted in EcomWeb.',
					'%1$d products selected: %2$d new, %3$d already in EcomWeb, %4$d deleted in EcomWeb.',
					count( $preflight['products'] ),
					'ecomweb-for-woocommerce'
				),
				count( $preflight['products'] ),
				$ecomweb_new,
				$plan['already_copied'],
				$plan['deleted']
			)
		);
		if ( ! $selection['explicit'] ) {
			echo ' ' . esc_html__( 'Products deleted in EcomWeb are left out.', 'ecomweb-for-woocommerce' );
		}
		?>
	</p>
	<?php if ( $selection['not_supported'] > 0 ) : ?>
		<p>
			<?php
			/* translators: %d: number of products. */
			echo esc_html( sprintf( __( "%d products can't be copied (grouped or external).", 'ecomweb-for-woocommerce' ), $selection['not_supported'] ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $plan['currency_blocked'] ) : ?>
		<div class="notice notice-error inline ecomweb-notice"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: ecomweb store currency, 2: WooCommerce currency. */
					__( "Your EcomWeb store uses %1\$s and WooCommerce uses %2\$s. EcomWeb doesn't convert prices: make the currencies match, then try again.", 'ecomweb-for-woocommerce' ),
					(string) ( $ecomweb_currency['store'] ?? '' ),
					(string) ( $ecomweb_currency['woocommerce'] ?? '' )
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( $plan['over_cap'] ) : ?>
		<div class="notice notice-warning inline ecomweb-notice"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: plan product limit, 2: products in the store now, 3: products that fit, 4: products to create. */
					__( 'Your plan allows %1$d products and the store has %2$d, so %3$d of these %4$d fit.', 'ecomweb-for-woocommerce' ),
					(int) $preflight['plan']['limit'],
					(int) $preflight['plan']['current'],
					$plan['fits'],
					$plan['creates']
				)
			);
			if ( $ecomweb_no_room ) {
				echo ' ' . esc_html__( 'Your plan has no room for more products. Upgrade your plan, or delete products in EcomWeb, then copy again.', 'ecomweb-for-woocommerce' );
			}
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( ! empty( $preflight['storage'] ) ) : ?>
		<div class="notice notice-info inline ecomweb-notice"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: image storage used, in MB. 2: image storage limit, in MB. */
					__( 'Your store has used %1$s MB of %2$s MB of image storage. Images beyond that will fail to copy.', 'ecomweb-for-woocommerce' ),
					number_format_i18n( (float) ( $preflight['storage']['used_mb'] ?? 0 ) ),
					number_format_i18n( (float) ( $preflight['storage']['limit_mb'] ?? 0 ) )
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( $ecomweb_no_room && 0 === $all['already_copied'] && ! $plan['currency_blocked'] ) : ?>
		<div class="ecomweb-actions">
			<?php if ( '' !== $ecomweb_upgrade ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $ecomweb_upgrade, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade your plan', 'ecomweb-for-woocommerce' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $ecomweb_back ); ?>"><?php esc_html_e( 'Cancel', 'ecomweb-for-woocommerce' ); ?></a>
		</div>
	<?php elseif ( ! $plan['currency_blocked'] ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ecomweb-confirm-form">
		<input type="hidden" name="action" value="ecomweb_start_copy">
		<?php wp_nonce_field( 'ecomweb_start_copy' ); ?>
		<?php if ( $all['already_copied'] > 0 ) : ?>
			<p class="ecomweb-check">
				<label>
					<input type="checkbox" name="overwrite" value="1" id="ecomweb-overwrite">
					<?php esc_html_e( 'Overwrite products already copied', 'ecomweb-for-woocommerce' ); ?>
				</label>
			</p>
			<p id="ecomweb-overwrite-confirm" class="ecomweb-check is-danger">
				<label>
					<input type="checkbox" name="confirm_overwrite" value="1">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of products. */
							__( 'Yes, overwrite %d products in EcomWeb. Their EcomWeb status stays as it is.', 'ecomweb-for-woocommerce' ),
							$all['already_copied']
						)
					);
					?>
				</label>
			</p>
		<?php endif; ?>
		<?php if ( $ecomweb_no_room ) : ?>
			<p>
				<?php
				/* translators: %d: number of products already copied. */
				echo esc_html( sprintf( __( 'Your plan has no room for new products. You can still overwrite the %d products already copied.', 'ecomweb-for-woocommerce' ), $all['already_copied'] ) );
				?>
			</p>
		<?php endif; ?>
		<div class="ecomweb-actions">
			<?php if ( $ecomweb_no_room ) : ?>
				<?php // Overwrite only: shown while Overwrite is ticked (admin.js); the handler refuses it unticked. ?>
				<input type="hidden" name="limit_to_fits" value="1">
				<span id="ecomweb-overwrite-start"><?php submit_button( __( 'Start copying', 'ecomweb-for-woocommerce' ), 'primary', 'submit', false ); ?></span>
			<?php elseif ( $plan['over_cap'] && $plan['fits'] > 0 ) : ?>
				<button type="submit" name="limit_to_fits" value="1" class="button button-primary">
					<?php
					/* translators: %d: number of products. */
					echo esc_html( sprintf( __( 'Copy the first %d', 'ecomweb-for-woocommerce' ), $plan['fits'] ) );
					?>
				</button>
			<?php else : ?>
				<?php submit_button( __( 'Start copying', 'ecomweb-for-woocommerce' ), 'primary', 'submit', false ); ?>
			<?php endif; ?>
			<?php if ( '' !== $ecomweb_upgrade ) : ?>
				<a class="button" href="<?php echo esc_url( $ecomweb_upgrade, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade your plan', 'ecomweb-for-woocommerce' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $ecomweb_back ); ?>"><?php esc_html_e( 'Cancel', 'ecomweb-for-woocommerce' ); ?></a>
		</div>
	</form>
	<?php else : ?>
		<div class="ecomweb-actions">
			<a class="button" href="<?php echo esc_url( Ecomweb\Woo\Admin_Page::url( array( 'view' => 'confirm' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'ecomweb-for-woocommerce' ); ?></a>
			<a class="button" href="<?php echo esc_url( $ecomweb_back ); ?>"><?php esc_html_e( 'Cancel', 'ecomweb-for-woocommerce' ); ?></a>
		</div>
	<?php endif; ?>
<?php endif; ?>
</div>
