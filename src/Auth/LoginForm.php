<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[rental_login]` — single login form for all four roles (SPEC.md §4,
 * Phase 2). No role selector: the role is whatever the authenticated
 * WP user already holds, and Redirector sends them to the right place.
 * Implemented as a shortcode/template (not a wp-login.php-only flow) so
 * it can sit on any page in any active theme per SPEC.md §4.6's "any
 * theme" requirement — the visual design in designs/01-login.html shows
 * a wp-login.php-styled card, so the shortcode's markup/inline styles
 * intentionally mirror that look rather than diverging into a different
 * visual language.
 */
final class LoginForm {

	private const NONCE_ACTION = 'rm_login';

	private Redirector $redirector;

	public function __construct( ?Redirector $redirector = null ) {
		$this->redirector = $redirector ?? new Redirector();
	}

	public function register(): void {
		add_shortcode( 'rental_login', array( $this, 'render' ) );
	}

	public function render(): string {
		if ( is_user_logged_in() ) {
			$redirect_url = $this->redirector->redirect_url_for_user( get_current_user_id() );

			return $this->notice_with_link(
				__( "You're already logged in.", 'chrx-rental-manager' ),
				$redirect_url,
				__( 'Continue', 'chrx-rental-manager' )
			);
		}

		$error = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified below via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_login_submit'] ) ) {
			$error = $this->handle_submit();
		}

		return $this->render_form( $error );
	}

	private function handle_submit(): ?string {
		check_admin_referer( self::NONCE_ACTION, 'rm_login_nonce' );

		$creds = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
			'user_login'    => isset( $_POST['rm_login'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_login'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
			'user_password' => isset( $_POST['rm_password'] ) ? (string) wp_unslash( $_POST['rm_password'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
			'remember'      => ! empty( $_POST['rm_remember'] ),
		);

		if ( '' === $creds['user_login'] || '' === $creds['user_password'] ) {
			return __( 'Please enter your email/username and password.', 'chrx-rental-manager' );
		}

		$user = wp_signon( $creds );

		if ( is_wp_error( $user ) ) {
			return __( 'Incorrect username/email or password.', 'chrx-rental-manager' );
		}

		wp_safe_redirect( $this->redirector->redirect_url_for_user( $user->ID ) );
		exit;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $error is used by the included template, which shares this method's local scope.
	private function render_form( ?string $error ): string {
		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/auth/login.php';

		return (string) ob_get_clean();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $message/$url/$link_text are used by the included template, which shares this method's local scope.
	private function notice_with_link( string $message, string $url, string $link_text ): string {
		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/auth/already-logged-in.php';

		return (string) ob_get_clean();
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
