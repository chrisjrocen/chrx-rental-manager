<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's custom WordPress roles.
 *
 * SPEC.md §2 defines four roles: Administrator (the existing WP role,
 * extended with plugin capabilities — not a new role), Property
 * Manager/Staff, Landlord-Owner, and Tenant. This class adds the three new
 * roles and, in the Roles & Permissions phase, extends the existing
 * `administrator` role with the plugin's capabilities.
 *
 * Capability sets are intentionally empty in Phase 0 — filled in during
 * the Roles & Permissions phase (SPEC.md §2) once the capability list is
 * finalized against the admin screens it gates.
 */
final class RoleManager {

	public const ROLE_STAFF          = 'rm_property_staff';
	public const ROLE_LANDLORD_OWNER = 'rm_landlord_owner';
	public const ROLE_TENANT         = 'rm_tenant';

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
	 * Registers (or re-registers) the three custom roles with empty
	 * capability sets. Safe to call repeatedly — `add_role()` is a no-op
	 * if the role already exists, and we don't want to clobber capabilities
	 * a later phase has already assigned by re-adding with empty caps here.
	 */
	public function register_roles(): void {
		foreach ( $this->custom_role_definitions() as $role_key => $display_name ) {
			if ( null === get_role( $role_key ) ) {
				add_role( $role_key, $display_name, array() );
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
