<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's custom WordPress roles and capabilities
 * (SPEC.md §2).
 *
 * Four roles: Administrator (the existing WP role, extended with every
 * plugin capability below — not a new role), Property Manager/Staff,
 * Landlord-Owner, and Tenant.
 *
 * Capability names are the *what* (can this user manage leases at all);
 * the *which properties* scoping for Staff/Landlord-Owner is a separate
 * concern handled by Access::userCanAccessProperty() — a capability check
 * alone never proves a staff/landlord user may touch a specific record.
 */
final class RoleManager {

	public const ROLE_STAFF          = 'rm_property_staff';
	public const ROLE_LANDLORD_OWNER = 'rm_landlord_owner';
	public const ROLE_TENANT         = 'rm_tenant';

	// Manage caps: create/edit/delete on properties assigned to the user
	// (Staff) or everywhere (Administrator).
	public const CAP_MANAGE_SETTINGS   = 'rm_manage_settings';
	public const CAP_MANAGE_STAFF      = 'rm_manage_staff';
	public const CAP_MANAGE_PROPERTIES = 'rm_manage_properties';
	public const CAP_MANAGE_UNITS      = 'rm_manage_units';
	public const CAP_MANAGE_TENANTS    = 'rm_manage_tenants';
	public const CAP_MANAGE_LEASES     = 'rm_manage_leases';
	public const CAP_MANAGE_PAYMENTS   = 'rm_manage_payments';

	// Read-only caps.
	public const CAP_VIEW_DASHBOARD  = 'rm_view_dashboard';
	public const CAP_VIEW_REPORTS    = 'rm_view_reports';
	public const CAP_VIEW_STATEMENTS = 'rm_view_statements';
	public const CAP_VIEW_PORTAL     = 'rm_view_portal';

	/**
	 * @return array<string,string> role key => display name
	 */
	public function custom_role_definitions(): array {
		return array(
			self::ROLE_STAFF          => __( 'Property Manager / Staff', 'chrx-rental-manager' ),
			self::ROLE_LANDLORD_OWNER => __( 'Landlord-Owner', 'chrx-rental-manager' ),
			self::ROLE_TENANT         => __( 'Tenant', 'chrx-rental-manager' ),
		);
	}

	/**
	 * @return array<string,array<int,string>> role key => list of capabilities
	 */
	public function capability_sets(): array {
		return array(
			// `read` is what lets a role into wp-admin at all; Staff and
			// Landlord-Owner both work there (SPEC.md §4.4's landlord
			// dashboard is a wp-admin screen). Tenant deliberately has no
			// `read` cap — the portal is front-end only.
			self::ROLE_STAFF          => array(
				'read',
				self::CAP_MANAGE_PROPERTIES,
				self::CAP_MANAGE_UNITS,
				self::CAP_MANAGE_TENANTS,
				self::CAP_MANAGE_LEASES,
				self::CAP_MANAGE_PAYMENTS,
				self::CAP_VIEW_DASHBOARD,
				self::CAP_VIEW_REPORTS,
			),
			self::ROLE_LANDLORD_OWNER => array(
				'read',
				self::CAP_VIEW_DASHBOARD,
				self::CAP_VIEW_REPORTS,
				self::CAP_VIEW_STATEMENTS,
			),
			self::ROLE_TENANT         => array(
				self::CAP_VIEW_PORTAL,
			),
		);
	}

	/**
	 * Every plugin capability the Administrator role is extended with —
	 * the full superset, since Administrators have "full account" scope
	 * per SPEC.md §2's role table.
	 *
	 * @return array<int,string>
	 */
	public function administrator_capabilities(): array {
		return array(
			self::CAP_MANAGE_SETTINGS,
			self::CAP_MANAGE_STAFF,
			self::CAP_MANAGE_PROPERTIES,
			self::CAP_MANAGE_UNITS,
			self::CAP_MANAGE_TENANTS,
			self::CAP_MANAGE_LEASES,
			self::CAP_MANAGE_PAYMENTS,
			self::CAP_VIEW_DASHBOARD,
			self::CAP_VIEW_REPORTS,
			self::CAP_VIEW_STATEMENTS,
		);
	}

	/**
	 * Registers the three custom roles (creating them if missing) and
	 * syncs their capability sets. Safe to call repeatedly — on an
	 * existing install this keeps capabilities current after a plugin
	 * update without requiring deactivate/reactivate (see
	 * Plugin::maybe_upgrade()).
	 */
	public function register_roles(): void {
		foreach ( $this->capability_sets() as $role_key => $caps ) {
			$display_name = $this->custom_role_definitions()[ $role_key ];
			$role         = get_role( $role_key );

			if ( null === $role ) {
				add_role( $role_key, $display_name, array_fill_keys( $caps, true ) );
				continue;
			}

			$this->sync_capabilities( $role, $caps );
		}

		$this->extend_administrator_role();
	}

	private function extend_administrator_role(): void {
		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		$this->sync_capabilities( $administrator, $this->administrator_capabilities() );
	}

	/**
	 * Adds any missing capability from $expected_caps and removes any
	 * rm_* capability that's no longer expected for this role (e.g. after
	 * a plugin update narrows a role's permissions) — but only rm_* caps,
	 * never touching `read`/other WP-core capabilities that may already
	 * be on the role for unrelated reasons.
	 *
	 * @param array<int,string> $expected_caps
	 */
	private function sync_capabilities( \WP_Role $role, array $expected_caps ): void {
		foreach ( $expected_caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}

		foreach ( array_keys( $role->capabilities ) as $existing_cap ) {
			$is_plugin_cap = str_starts_with( $existing_cap, 'rm_' );

			if ( $is_plugin_cap && ! in_array( $existing_cap, $expected_caps, true ) ) {
				$role->remove_cap( $existing_cap );
			}
		}
	}

	/**
	 * Not called on deactivation (deactivation leaves data/roles intact by
	 * design — see chrx-rental-manager.php on_deactivate()). Provided for
	 * a future, deliberate uninstall.php.
	 */
	public function remove_roles(): void {
		foreach ( array_keys( $this->custom_role_definitions() ) as $role_key ) {
			remove_role( $role_key );
		}
	}
}
