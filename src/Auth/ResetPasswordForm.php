<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[rental_reset_password]` — generic "Set a new password" screen
 * (designs/03b-set-new-password.html), reached from the forgot-password
 * email link.
 */
final class ResetPasswordForm extends AbstractPasswordResetForm {

	public function register(): void {
		add_shortcode( 'rental_reset_password', array( $this, 'render' ) );
	}

	protected function template(): string {
		return 'reset-password.php';
	}

	protected function post_save_redirect_url( \WP_User $user ): string {
		return add_query_arg( 'rm_password_reset', '1', $this->pages->url( Pages::KEY_LOGIN ) );
	}
}
