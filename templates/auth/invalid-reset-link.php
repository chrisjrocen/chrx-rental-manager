<?php
/**
 * Shown by ResetPasswordForm/PortalActivateForm when the key/login pair
 * is missing or expired.
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="chrx-rm-auth chrx-rm-auth--invalid-link">
	<div class="chrx-rm-auth__card">
		<p class="chrx-rm-auth__error">
			<?php esc_html_e( 'This link is invalid or has expired. Please request a new one.', 'chrx-rental-manager' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_FORGOT_PASSWORD ) ); ?>">
				<?php esc_html_e( 'Request a new reset link', 'chrx-rental-manager' ); ?>
			</a>
		</p>
	</div>
</div>
