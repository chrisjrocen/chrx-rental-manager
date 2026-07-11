<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[rental_portal_activate]` — first-time tenant portal setup
 * (designs/03-tenant-invite-set-password.html), reached from the "Invite
 * to Portal" email. Same underlying WP core reset-key mechanism as
 * ResetPasswordForm, framed as "Welcome, {name}!" rather than a generic
 * password reset, and auto-logs the tenant straight into the portal on
 * success instead of sending them back to a login form they've never
 * used before.
 */
final class PortalActivateForm extends AbstractPasswordResetForm {

	private Tenant $tenants;
	private Lease $leases;
	private Unit $units;
	private Property $properties;

	public function __construct(
		?Pages $pages = null,
		?Tenant $tenants = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Property $properties = null
	) {
		parent::__construct( $pages );

		$this->tenants    = $tenants ?? new Tenant();
		$this->leases     = $leases ?? new Lease();
		$this->units      = $units ?? new Unit();
		$this->properties = $properties ?? new Property();
	}

	public function register(): void {
		add_shortcode( 'rental_portal_activate', array( $this, 'render' ) );
	}

	protected function template(): string {
		return 'portal-activate.php';
	}

	protected function post_save_redirect_url( \WP_User $user ): string {
		return $this->pages->url( Pages::KEY_PORTAL );
	}

	protected function after_password_saved( \WP_User $user ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
	}

	/**
	 * Welcome-copy context for the template: tenant's first name and,
	 * if they already have an active lease, the unit/property line shown
	 * in the design. A tenant invited before move-in simply won't have
	 * one yet (SPEC.md §4.5) — the template omits that line gracefully.
	 *
	 * @return array{first_name:string,unit_line:string}
	 */
	protected function welcome_context( \WP_User $user ): array {
		$tenant = $this->tenants->find_by_wp_user_id( $user->ID );

		$first_name = '' !== $user->first_name ? $user->first_name : $user->display_name;
		$unit_line  = '';

		if ( null !== $tenant ) {
			$first_name = explode( ' ', trim( (string) $tenant['full_name'] ) )[0] ?? $first_name;

			$leases = $this->leases->for_tenant( (int) $tenant['id'] );
			$active = null;

			foreach ( $leases as $lease ) {
				if ( Lease::STATUS_ACTIVE === $lease['status'] ) {
					$active = $lease;
					break;
				}
			}

			if ( null !== $active ) {
				$unit = $this->units->find( (int) $active['unit_id'] );

				if ( null !== $unit ) {
					$property = $this->properties->find( (int) $unit['property_id'] );

					$unit_line = null !== $property
						? sprintf( '%s, %s', $unit['unit_label'], $property['name'] )
						: $unit['unit_label'];
				}
			}
		}

		return array(
			'first_name' => $first_name,
			'unit_line'  => $unit_line,
		);
	}
}
