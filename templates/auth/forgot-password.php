<?php
/**
 * [rental_forgot_password] form. Mirrors designs/02-3a-forgot-password-request.html.
 * Variables in scope: $error (?string), $success (bool).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="chrx-rm-auth chrx-rm-auth--forgot-password">
	<div class="chrx-rm-auth__card">
		<?php if ( $success ) : ?>
			<p class="chrx-rm-auth__success">
				<?php esc_html_e( "If an account exists for that email, we've sent a link to reset your password.", 'chrx-rental-manager' ); ?>
			</p>
		<?php else : ?>
			<p class="chrx-rm-auth__intro">
				<?php esc_html_e( "Enter your email and we'll send you a link to reset your password.", 'chrx-rental-manager' ); ?>
			</p>

			<?php if ( null !== $error ) : ?>
				<p class="chrx-rm-auth__error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<form method="post" class="chrx-rm-auth__form">
				<?php wp_nonce_field( 'rm_forgot_password', 'rm_forgot_password_nonce' ); ?>

				<label for="rm_email"><?php esc_html_e( 'Email address', 'chrx-rental-manager' ); ?></label>
				<input type="email" id="rm_email" name="rm_email" autocomplete="email" required>

				<button type="submit" name="rm_forgot_password_submit" value="1">
					<?php esc_html_e( 'Send reset link', 'chrx-rental-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<p class="chrx-rm-auth__links">
		<a href="<?php echo esc_url( ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_LOGIN ) ); ?>">
			<?php esc_html_e( '← Back to log in', 'chrx-rental-manager' ); ?>
		</a>
	</p>
</div>
