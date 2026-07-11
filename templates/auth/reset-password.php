<?php
/**
 * [rental_reset_password] form. Mirrors designs/03b-set-new-password.html.
 * Variables in scope: $user (WP_User), $key (string), $login (string),
 * $error (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Auth\AbstractPasswordResetForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="chrx-rm-auth chrx-rm-auth--reset-password">
	<div class="chrx-rm-auth__card">
		<h2><?php esc_html_e( 'Set a new password', 'chrx-rental-manager' ); ?></h2>

		<?php if ( null !== $error ) : ?>
			<p class="chrx-rm-auth__error"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" class="chrx-rm-auth__form">
			<?php wp_nonce_field( AbstractPasswordResetForm::nonce_action(), 'rm_set_password_nonce' ); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

			<label for="rm_password"><?php esc_html_e( 'New password', 'chrx-rental-manager' ); ?></label>
			<input type="password" id="rm_password" name="rm_password" autocomplete="new-password" required>

			<label for="rm_password_confirm"><?php esc_html_e( 'Confirm new password', 'chrx-rental-manager' ); ?></label>
			<input type="password" id="rm_password_confirm" name="rm_password_confirm" autocomplete="new-password" required>

			<button type="submit" name="rm_set_password_submit" value="1">
				<?php esc_html_e( 'Save password', 'chrx-rental-manager' ); ?>
			</button>
		</form>
	</div>
</div>
