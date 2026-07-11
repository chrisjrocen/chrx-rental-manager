<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Roles;

use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Property-scoping authorization helper (SPEC.md §2's "Design decision").
 * Staff and Landlord-Owner are assigned to specific properties via
 * rm_property_staff/rm_property_landlords, not a blanket account-wide
 * permission — every controller in Phases 3+ that touches property-scoped
 * data must call userCanAccessProperty() (or accessiblePropertyIds() for
 * list queries) server-side. A capability check alone (e.g.
 * current_user_can('rm_manage_leases')) only proves the user's role can
 * manage leases in general, never that they may touch *this* property.
 */
final class Access {

	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;

	public function __construct( ?PropertyStaff $property_staff = null, ?PropertyLandlord $property_landlords = null ) {
		$this->property_staff     = $property_staff ?? new PropertyStaff();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
	}

	/**
	 * The single source of truth for "can this user touch this property's
	 * data" — Administrators always can; Staff/Landlord-Owner only for
	 * properties they're explicitly assigned to; everyone else (including
	 * Tenant) never, since tenants are scoped to their own lease, not a
	 * property.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase name is mandated verbatim by CLAUDE_CODE_BUILD_PROMPTS.md's Phase 2 spec ("Access::userCanAccessProperty(...)"); every later phase's controllers call it by this exact name.
	public function userCanAccessProperty( int $user_id, int $property_id ): bool {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( user_can( $user_id, RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			return $this->property_staff->is_assigned( $property_id, $user_id );
		}

		if ( user_can( $user_id, RoleManager::CAP_VIEW_DASHBOARD ) && $this->is_landlord( $user_id ) ) {
			return $this->property_landlords->is_assigned( $property_id, $user_id );
		}

		return false;
	}

	/**
	 * Every property id this user may see — used to scope list/dashboard
	 * queries (SPEC.md §4.4's landlord edge case: always join through
	 * rm_property_landlords, never rely on hiding UI elements alone).
	 * Administrators get null, meaning "no restriction" (all properties);
	 * callers must treat null and [] differently — [] means "sees nothing".
	 *
	 * @return array<int,int>|null
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase name kept consistent with the mandated userCanAccessProperty() naming on this same class.
	public function accessiblePropertyIds( int $user_id ): ?array {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return null;
		}

		if ( user_can( $user_id, RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			return $this->property_staff->property_ids_for_user( $user_id );
		}

		if ( $this->is_landlord( $user_id ) ) {
			return $this->property_landlords->property_ids_for_user( $user_id );
		}

		return array();
	}

	public function is_administrator( int $user_id ): bool {
		return user_can( $user_id, 'manage_options' );
	}

	public function is_staff( int $user_id ): bool {
		return in_array( RoleManager::ROLE_STAFF, $this->roles_for_user( $user_id ), true );
	}

	public function is_landlord( int $user_id ): bool {
		return in_array( RoleManager::ROLE_LANDLORD_OWNER, $this->roles_for_user( $user_id ), true );
	}

	public function is_tenant( int $user_id ): bool {
		return in_array( RoleManager::ROLE_TENANT, $this->roles_for_user( $user_id ), true );
	}

	/**
	 * SPEC.md §2 edge case: a user can hold both Landlord-Owner and Tenant
	 * roles simultaneously (an owner who also rents a unit elsewhere in
	 * the portfolio).
	 */
	public function has_multiple_portal_contexts( int $user_id ): bool {
		return $this->is_landlord( $user_id ) && $this->is_tenant( $user_id );
	}

	/**
	 * @return array<int,string>
	 */
	private function roles_for_user( int $user_id ): array {
		$user = get_userdata( $user_id );

		return false === $user ? array() : $user->roles;
	}
}
