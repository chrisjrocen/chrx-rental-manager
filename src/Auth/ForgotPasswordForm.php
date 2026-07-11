<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[rental_forgot_password]` — built on WP's native retrieve_password()
 * (SPEC.md §2/Phase 2: "on top of WP's native retrieve_password/
 * reset_password mechanisms rather than a parallel custom auth system").
 * The only customization is the emailed link's destination, redirected
 * via the `retrieve_password_message` filter to our own reset-password
 * page instead of wp-login.php?action=rp — the reset key itself is still
 * generated and validated entirely by WP core.
 */
final class ForgotPasswordForm {

	private const NONCE_ACTION = 'rm_forgot_password';

	private Pages $pages;

	public function __construct( ?Pages $pages = null ) {
		$this->pages = $pages ?? new Pages();
	}

	public function register(): void {
		add_shortcode( 'rental_forgot_password', array( $this, 'render' ) );
		add_filter( 'retrieve_password_message', array( $this, 'filter_reset_message' ), 10, 4 );
	}

	public function render(): string {
		$error   = null;
		$success = false;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified below via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_forgot_password_submit'] ) ) {
			$result  = $this->handle_submit();
			$error   = is_wp_error( $result ) ? $result->get_error_message() : null;
			$success = ( true === $result );
		}

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/auth/forgot-password.php';

		return (string) ob_get_clean();
	}

	/**
	 * @return true|\WP_Error
	 */
	private function handle_submit() {
		check_admin_referer( self::NONCE_ACTION, 'rm_forgot_password_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$email = isset( $_POST['rm_email'] ) ? sanitize_email( wp_unslash( $_POST['rm_email'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'rm_invalid_email', __( 'Please enter a valid email address.', 'chrx-rental-manager' ) );
		}

		$result = retrieve_password( $email );

		if ( is_wp_error( $result ) ) {
			// Deliberately vague — do not reveal whether the email exists.
			return true;
		}

		return true;
	}

	/**
	 * @param \WP_User $user_data Unused — required by the `retrieve_password_message` filter signature.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $user_data is part of the WP filter signature, not needed here.
	public function filter_reset_message( string $message, string $key, string $user_login, $user_data ): string {
		$reset_url = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $user_login ),
			),
			$this->pages->url( Pages::KEY_RESET_PASSWORD )
		);

		return sprintf(
			/* translators: 1: site name, 2: reset link */
			__( "Someone requested a password reset for your account on %1\$s.\n\nIf this wasn't you, you can ignore this email.\n\nTo set a new password, visit:\n%2\$s", 'chrx-rental-manager' ),
			get_bloginfo( 'name' ),
			$reset_url
		);
	}
}
