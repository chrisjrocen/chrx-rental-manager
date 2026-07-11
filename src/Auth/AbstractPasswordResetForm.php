<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared key-validation/set-password logic for both the generic
 * "Set new password" screen (designs/03b-set-new-password.html) and the
 * tenant-branded first-time portal activation screen
 * (designs/03-tenant-invite-set-password.html). Both are the same
 * underlying WP core reset-password-key mechanism
 * (check_password_reset_key()/reset_password()) per SPEC.md's "on top of
 * WP's native mechanisms" instruction — the two subclasses only differ in
 * template/copy and post-save redirect target.
 */
abstract class AbstractPasswordResetForm {

	private const NONCE_ACTION = 'rm_set_password';

	protected Pages $pages;

	public function __construct( ?Pages $pages = null ) {
		$this->pages = $pages ?? new Pages();
	}

	abstract public function register(): void;

	abstract protected function template(): string;

	/**
	 * @return string|\WP_Error the WP_Error is only used internally to
	 *                          carry the "post-save redirect" concept when
	 *                          overridden — subclasses call render_result().
	 */
	public function render(): string {
		// The form posts back to the same page with key/login carried as
		// hidden fields (a bare <form> without an explicit action does not
		// reliably preserve the query string on submit across browsers),
		// so both the initial GET and the POST re-render need to read them.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only lookup; the key itself is the secret, not a state-changing action.
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ( isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only lookup; the key itself is the secret, not a state-changing action.
		$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : ( isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '' );

		if ( '' === $key || '' === $login ) {
			return $this->render_invalid_link();
		}

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			return $this->render_invalid_link();
		}

		$error = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified below via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_set_password_submit'] ) ) {
			$result = $this->handle_submit( $user );

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			}
			// Success redirects and exits inside handle_submit().
		}

		return $this->render_form( $user, $key, $login, $error );
	}

	/**
	 * @return true|\WP_Error
	 */
	private function handle_submit( \WP_User $user ) {
		check_admin_referer( self::NONCE_ACTION, 'rm_set_password_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$password = isset( $_POST['rm_password'] ) ? (string) wp_unslash( $_POST['rm_password'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$confirm = isset( $_POST['rm_password_confirm'] ) ? (string) wp_unslash( $_POST['rm_password_confirm'] ) : '';

		if ( '' === $password ) {
			return new \WP_Error( 'rm_empty_password', __( 'Please enter a password.', 'chrx-rental-manager' ) );
		}

		if ( $password !== $confirm ) {
			return new \WP_Error( 'rm_password_mismatch', __( 'Passwords do not match.', 'chrx-rental-manager' ) );
		}

		reset_password( $user, $password );
		$this->after_password_saved( $user );

		wp_safe_redirect( $this->post_save_redirect_url( $user ) );
		exit;
	}

	abstract protected function post_save_redirect_url( \WP_User $user ): string;

	/**
	 * Override for post-save side effects (e.g. auto-login for the tenant
	 * portal activation flow). No-op by default.
	 */
	protected function after_password_saved( \WP_User $user ): void {}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $user/$key/$login/$error are used by the included template, which shares this method's local scope.
	private function render_form( \WP_User $user, string $key, string $login, ?string $error ): string {
		$template = $this->template();

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/auth/' . $template;

		return (string) ob_get_clean();
	}

	private function render_invalid_link(): string {
		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/auth/invalid-reset-link.php';

		return (string) ob_get_clean();
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
