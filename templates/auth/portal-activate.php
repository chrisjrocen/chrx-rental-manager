<?php
/**
 * [rental_portal_activate] form. Mirrors
 * designs/03-tenant-invite-set-password.html's "Welcome, {name}!" framing.
 * Variables in scope: $user (WP_User), $key (string), $login (string),
 * $error (?string). $this is the PortalActivateForm instance.
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Auth\AbstractPasswordResetForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$context = $this->welcome_context( $user );
?>
<div class="chrx-rm-auth chrx-rm-auth--portal-activate">
	<div class="chrx-rm-auth__card chrx-rm-auth__card--branded">
		<h2>
			<?php
			printf(
				/* translators: %s: tenant first name */
				esc_html__( 'Welcome, %s!', 'chrx-rental-manager' ),
				esc_html( $context['first_name'] )
			);
			?>
		</h2>

		<p class="chrx-rm-auth__intro">
			<?php if ( '' !== $context['unit_line'] ) : ?>
				<?php
				printf(
					/* translators: %s: unit and property, e.g. "Unit B4, Acacia Court" */
					esc_html__( "You've been invited to the tenant portal for %s. Set a password to view your balance, lease and receipts.", 'chrx-rental-manager' ),
					'<strong>' . esc_html( $context['unit_line'] ) . '</strong>'
				);
				?>
			<?php else : ?>
				<?php esc_html_e( "You've been invited to the tenant portal. Set a password to get started — your lease details will appear here once you've moved in.", 'chrx-rental-manager' ); ?>
			<?php endif; ?>
		</p>

		<?php if ( null !== $error ) : ?>
			<p class="chrx-rm-auth__error"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" class="chrx-rm-auth__form">
			<?php wp_nonce_field( AbstractPasswordResetForm::nonce_action(), 'rm_set_password_nonce' ); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

			<label for="rm_password"><?php esc_html_e( 'Create password', 'chrx-rental-manager' ); ?></label>
			<input type="password" id="rm_password" name="rm_password" autocomplete="new-password" required>

			<label for="rm_password_confirm"><?php esc_html_e( 'Confirm password', 'chrx-rental-manager' ); ?></label>
			<input type="password" id="rm_password_confirm" name="rm_password_confirm" autocomplete="new-password" required>

			<button type="submit" name="rm_set_password_submit" value="1">
				<?php esc_html_e( 'Activate my portal', 'chrx-rental-manager' ); ?>
			</button>
		</form>
	</div>
</div>
