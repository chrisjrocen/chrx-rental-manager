<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\PortalStatus;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Auth\Pages;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Invite to Portal" (SPEC.md §4.5): creates a WP user with the Tenant
 * role, links rm_tenants.wp_user_id, and emails a set-password link built
 * on WP's native retrieve-password key mechanism (not a plaintext
 * password) pointing at the branded portal-activation page rather than
 * wp-login.php.
 *
 * The Tenant list/detail screens that surface the "Invite to Portal"
 * button belong to Phase 3 (Admin CRUD); this controller is the
 * self-contained handler those screens will POST to — it doesn't depend
 * on anything Phase 3 adds, only on Data\Tenant already existing (Phase 1).
 */
final class TenantInviteController {

	private const NONCE_ACTION = 'rm_invite_tenant';

	private Tenant $tenants;
	private Lease $leases;
	private Unit $units;
	private Access $access;
	private Pages $pages;
	private NotificationLog $notifications;
	private Notifier $notifier;

	public function __construct(
		?Tenant $tenants = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Access $access = null,
		?Pages $pages = null,
		?NotificationLog $notifications = null,
		?Notifier $notifier = null
	) {
		$this->tenants       = $tenants ?? new Tenant();
		$this->leases        = $leases ?? new Lease();
		$this->units         = $units ?? new Unit();
		$this->access        = $access ?? new Access();
		$this->pages         = $pages ?? new Pages();
		$this->notifications = $notifications ?? new NotificationLog();
		$this->notifier      = $notifier ?? new Notifier( $this->notifications );
	}

	public function register(): void {
		add_action( 'admin_post_rm_invite_tenant', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to do that.', 'chrx-rental-manager' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
			wp_die( esc_html__( 'You do not have permission to invite tenants.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$tenant_id = isset( $_POST['tenant_id'] ) ? absint( $_POST['tenant_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$redirect_back = isset( $_POST['_wp_http_referer'] ) ? sanitize_text_field( wp_unslash( $_POST['_wp_http_referer'] ) ) : admin_url();

		$result = $this->invite( $tenant_id, get_current_user_id() );

		$redirect = add_query_arg(
			array(
				'rm_invite_result' => is_wp_error( $result ) ? $result->get_error_code() : 'sent',
			),
			$redirect_back
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function invite( int $tenant_id, int $inviting_user_id ) {
		$tenant = $this->tenants->find( $tenant_id );

		if ( null === $tenant ) {
			return new \WP_Error( 'rm_tenant_not_found', __( 'Tenant not found.', 'chrx-rental-manager' ) );
		}

		if ( ! $this->can_invite( $tenant, $inviting_user_id ) ) {
			return new \WP_Error( 'rm_forbidden', __( 'You are not allowed to invite this tenant.', 'chrx-rental-manager' ) );
		}

		if ( '' === (string) $tenant['email'] || ! is_email( $tenant['email'] ) ) {
			return new \WP_Error( 'rm_no_email', __( 'This tenant has no valid email on file. Add one before inviting them to the portal.', 'chrx-rental-manager' ) );
		}

		$already_linked = null !== $tenant['wp_user_id'] && '' !== (string) $tenant['wp_user_id'];

		if ( $already_linked ) {
			$existing_user = get_userdata( (int) $tenant['wp_user_id'] );

			if ( false === $existing_user ) {
				return new \WP_Error( 'rm_already_invited', __( 'This tenant already has portal access.', 'chrx-rental-manager' ) );
			}

			if ( PortalStatus::ACTIVE === PortalStatus::for_tenant( $tenant ) ) {
				return new \WP_Error( 'rm_already_invited', __( 'This tenant already has portal access.', 'chrx-rental-manager' ) );
			}

			// Invited but never completed setup — this is a resend, not a
			// fresh invite: regenerate the key and email it again rather
			// than erroring.
			return $this->resend( $existing_user, $tenant );
		}

		$user = get_user_by( 'email', $tenant['email'] );

		if ( false === $user ) {
			$user_id = $this->create_tenant_user( $tenant );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_user_by( 'id', $user_id );
		} else {
			// An existing WP account with this email is granted portal
			// access rather than erroring on a duplicate-email collision.
			$user->add_role( RoleManager::ROLE_TENANT );
		}

		$this->tenants->link_wp_user( $tenant_id, $user->ID );

		$sent = $this->send_invite_email( $user, $tenant, 'portal_invite' );

		if ( ! $sent ) {
			return new \WP_Error( 'rm_email_failed', __( 'Portal account created, but the invite email could not be sent.', 'chrx-rental-manager' ) );
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $tenant
	 * @return true|\WP_Error
	 */
	private function resend( \WP_User $user, array $tenant ) {
		$sent = $this->send_invite_email( $user, $tenant, 'portal_invite_resend' );

		if ( ! $sent ) {
			return new \WP_Error( 'rm_email_failed', __( 'The invite email could not be sent.', 'chrx-rental-manager' ) );
		}

		return true;
	}

	/**
	 * Staff may only invite tenants tied to a property they're assigned
	 * to; a tenant with no lease yet has no property to scope by, so any
	 * staff member with the manage-tenants capability may invite them.
	 * Administrators always may.
	 *
	 * @param array<string,mixed> $tenant
	 */
	private function can_invite( array $tenant, int $user_id ): bool {
		if ( $this->access->is_administrator( $user_id ) ) {
			return true;
		}

		$leases = $this->leases->for_tenant( (int) $tenant['id'] );

		if ( array() === $leases ) {
			return current_user_can( RoleManager::CAP_MANAGE_TENANTS );
		}

		foreach ( $leases as $lease ) {
			$unit = $this->units->find( (int) $lease['unit_id'] );

			if ( null !== $unit && $this->access->userCanAccessProperty( $user_id, (int) $unit['property_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $tenant
	 * @return int|\WP_Error
	 */
	private function create_tenant_user( array $tenant ) {
		$username = $this->unique_username_from_email( $tenant['email'] );

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $tenant['email'],
				'user_pass'  => wp_generate_password( 24 ),
				'first_name' => $tenant['full_name'],
				'role'       => RoleManager::ROLE_TENANT,
			)
		);

		return $user_id;
	}

	private function unique_username_from_email( string $email ): string {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		$base = '' !== $base ? $base : 'tenant';

		$username = $base;
		$suffix   = 1;

		while ( username_exists( $username ) ) {
			++$suffix;
			$username = $base . $suffix;
		}

		return $username;
	}

	/**
	 * @param array<string,mixed> $tenant
	 */
	private function send_invite_email( \WP_User $user, array $tenant, string $type ): bool {
		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return false;
		}

		$activate_url = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $user->user_login ),
			),
			$this->pages->url( Pages::KEY_PORTAL_ACTIVATE )
		);

		$tenant_name = explode( ' ', trim( $tenant['full_name'] ) )[0] ?? $tenant['full_name'];

		$subject = sprintf(
			/* translators: %s: site name */
			__( "You're invited to the %s tenant portal", 'chrx-rental-manager' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: tenant first name, 2: site name, 3: activation link */
			__( "Hi %1\$s,\n\nYou've been invited to the %2\$s tenant portal, where you can view your balance, lease details, and payment receipts.\n\nSet up your account:\n%3\$s\n\nIf you weren't expecting this, you can ignore this email.", 'chrx-rental-manager' ),
			$tenant_name,
			get_bloginfo( 'name' ),
			$activate_url
		);

		return $this->notifier->notify(
			$type,
			(int) $tenant['id'],
			array(
				'email'           => $tenant['email'],
				'whatsapp_number' => $tenant['whatsapp_number'] ?? '',
			),
			$subject,
			$message,
			Settings::TEMPLATE_KEY_INVITE,
			array( $tenant_name, get_bloginfo( 'name' ), $activate_url )
		);
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
