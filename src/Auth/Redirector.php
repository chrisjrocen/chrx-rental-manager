<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

use ChrxRentalManager\Roles\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect-by-role after authentication (SPEC.md §4, Phase 2 deliverable):
 * Admin/Staff/Landlord-Owner → wp-admin, Tenant → the portal page. Wired
 * both to the `[rental_login]` shortcode's own submit handler and to core
 * WordPress's `login_redirect` filter, so the same rule applies no matter
 * which login form a user reaches (native wp-login.php included) — a
 * belt-and-suspenders choice since nothing in SPEC.md forbids a site
 * owner or the tenant's own bookmark from hitting wp-login.php directly.
 */
final class Redirector {

	private Access $access;
	private Pages $pages;

	public function __construct( ?Access $access = null, ?Pages $pages = null ) {
		$this->access = $access ?? new Access();
		$this->pages  = $pages ?? new Pages();
	}

	public function register(): void {
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'bounce_tenants_out_of_wp_admin' ) );
	}

	/**
	 * @param \WP_User|\WP_Error $user
	 */
	public function filter_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		return $this->redirect_url_for_user( $user->ID );
	}

	public function redirect_url_for_user( int $user_id ): string {
		if ( $this->access->is_tenant( $user_id ) && ! $this->access->has_multiple_portal_contexts( $user_id ) ) {
			return $this->pages->url( Pages::KEY_PORTAL );
		}

		// Administrator, Staff, Landlord-Owner, and the dual
		// Landlord-Owner+Tenant edge case all land in wp-admin — the
		// portal UI itself surfaces a context switch for the dual-role
		// case (SPEC.md §2) rather than the login redirect guessing which
		// context the user wants first.
		return admin_url();
	}

	/**
	 * Defense in depth: a Tenant has no `read` capability so WP core
	 * already redirects them out of wp-admin, but this makes the "cannot
	 * use wp-admin" rule explicit and independent of that capability
	 * detail ever changing.
	 *
	 * admin-post.php is exempt: it lives under wp-admin/ and fires
	 * `admin_init` like any other admin page, but it's also the standard
	 * WP mechanism for a logged-in front-end user to reach a form-handler
	 * action (TenantInviteController's own portal-activation flow relies
	 * on it, and so does Portal\PortalReceiptDownload for the tenant
	 * portal's receipt PDFs) — bouncing every admin-post.php hit would
	 * make a tenant's own "Download PDF" button redirect to the portal
	 * home instead of downloading anything. Each individual admin-post
	 * handler still does its own capability/ownership check.
	 */
	public function bounce_tenants_out_of_wp_admin(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		global $pagenow;

		if ( 'admin-post.php' === $pagenow ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		if ( $this->access->is_tenant( $user_id ) && ! $this->access->has_multiple_portal_contexts( $user_id ) ) {
			wp_safe_redirect( $this->pages->url( Pages::KEY_PORTAL ) );
			exit;
		}
	}
}
