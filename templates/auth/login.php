<?php
/**
 * [rental_login] form. Mirrors designs/01-login.html's wp-login.php-styled
 * card. Variables in scope: $error (?string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="chrx-rm-auth chrx-rm-auth--login">
	<div class="chrx-rm-auth__brand">
		<span class="chrx-rm-auth__logo" aria-hidden="true"></span>
		<span class="chrx-rm-auth__brand-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
	</div>

	<div class="chrx-rm-auth__card">
		<?php if ( null !== $error ) : ?>
			<p class="chrx-rm-auth__error"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" class="chrx-rm-auth__form">
			<?php wp_nonce_field( \ChrxRentalManager\Auth\LoginForm::nonce_action(), 'rm_login_nonce' ); ?>

			<label for="rm_login"><?php esc_html_e( 'Email or Username', 'chrx-rental-manager' ); ?></label>
			<input type="text" id="rm_login" name="rm_login" autocomplete="username" required>

			<label for="rm_password"><?php esc_html_e( 'Password', 'chrx-rental-manager' ); ?></label>
			<input type="password" id="rm_password" name="rm_password" autocomplete="current-password" required>

			<label class="chrx-rm-auth__checkbox">
				<input type="checkbox" name="rm_remember" value="1" checked>
				<?php esc_html_e( 'Remember me', 'chrx-rental-manager' ); ?>
			</label>

			<button type="submit" name="rm_login_submit" value="1"><?php esc_html_e( 'Log In', 'chrx-rental-manager' ); ?></button>
		</form>
	</div>

	<p class="chrx-rm-auth__links">
		<a href="<?php echo esc_url( ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_FORGOT_PASSWORD ) ); ?>">
			<?php esc_html_e( 'Lost your password?', 'chrx-rental-manager' ); ?>
		</a>
	</p>
</div>
