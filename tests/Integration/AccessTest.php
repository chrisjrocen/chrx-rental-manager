<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

/**
 * SPEC.md §2: Staff/Landlord-Owner are scoped to specific properties via
 * the join tables, never a blanket account-wide permission — this is the
 * server-side enforcement every later phase's controllers rely on.
 */
final class AccessTest extends IntegrationTestCase {

	private Access $access;
	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private int $property_a;
	private int $property_b;

	protected function setUp(): void {
		parent::setUp();

		( new RoleManager() )->register_roles();

		$this->access              = new Access();
		$this->property_staff      = new PropertyStaff();
		$this->property_landlords  = new PropertyLandlord();

		$properties       = new Property();
		$this->property_a = $properties->insert( [ 'name' => 'Property A', 'city' => 'Accra' ] );
		$this->property_b = $properties->insert( [ 'name' => 'Property B', 'city' => 'Accra' ] );
	}

	private function make_user( string $role ): int {
		$user_id = wp_insert_user( [
			'user_login' => 'test_' . $role . '_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'test_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
		] );

		$this->assertIsInt( $user_id );

		return $user_id;
	}

	public function test_administrator_can_access_every_property(): void {
		$admin_id = $this->make_user( 'administrator' );

		$this->assertTrue( $this->access->userCanAccessProperty( $admin_id, $this->property_a ) );
		$this->assertTrue( $this->access->userCanAccessProperty( $admin_id, $this->property_b ) );
		$this->assertNull( $this->access->accessiblePropertyIds( $admin_id ) );
	}

	public function test_staff_can_only_access_assigned_property(): void {
		$staff_id = $this->make_user( RoleManager::ROLE_STAFF );
		$this->property_staff->assign( $this->property_a, $staff_id );

		$this->assertTrue( $this->access->userCanAccessProperty( $staff_id, $this->property_a ) );
		$this->assertFalse( $this->access->userCanAccessProperty( $staff_id, $this->property_b ) );
		$this->assertSame( [ $this->property_a ], $this->access->accessiblePropertyIds( $staff_id ) );
	}

	public function test_landlord_can_only_access_owned_property(): void {
		$landlord_id = $this->make_user( RoleManager::ROLE_LANDLORD_OWNER );
		$this->property_landlords->assign( $this->property_b, $landlord_id );

		$this->assertFalse( $this->access->userCanAccessProperty( $landlord_id, $this->property_a ) );
		$this->assertTrue( $this->access->userCanAccessProperty( $landlord_id, $this->property_b ) );
		$this->assertSame( [ $this->property_b ], $this->access->accessiblePropertyIds( $landlord_id ) );
	}

	public function test_landlord_cannot_see_another_landlords_property_even_if_both_assigned_via_same_staff_property(): void {
		$landlord_1 = $this->make_user( RoleManager::ROLE_LANDLORD_OWNER );
		$landlord_2 = $this->make_user( RoleManager::ROLE_LANDLORD_OWNER );

		$this->property_landlords->assign( $this->property_a, $landlord_1 );
		$this->property_landlords->assign( $this->property_b, $landlord_2 );

		// Cross-owner access attempt must be blocked, not just hidden in the UI.
		$this->assertFalse( $this->access->userCanAccessProperty( $landlord_2, $this->property_a ) );
		$this->assertFalse( $this->access->userCanAccessProperty( $landlord_1, $this->property_b ) );
	}

	public function test_tenant_never_has_property_access(): void {
		$tenant_id = $this->make_user( RoleManager::ROLE_TENANT );

		$this->assertFalse( $this->access->userCanAccessProperty( $tenant_id, $this->property_a ) );
		$this->assertSame( [], $this->access->accessiblePropertyIds( $tenant_id ) );
	}

	public function test_unassigned_staff_sees_no_properties(): void {
		$staff_id = $this->make_user( RoleManager::ROLE_STAFF );

		$this->assertSame( [], $this->access->accessiblePropertyIds( $staff_id ) );
	}

	public function test_user_can_hold_both_landlord_and_tenant_roles(): void {
		$user_id = $this->make_user( RoleManager::ROLE_LANDLORD_OWNER );
		$user    = get_userdata( $user_id );
		$user->add_role( RoleManager::ROLE_TENANT );

		$this->assertTrue( $this->access->is_landlord( $user_id ) );
		$this->assertTrue( $this->access->is_tenant( $user_id ) );
		$this->assertTrue( $this->access->has_multiple_portal_contexts( $user_id ) );
	}

	public function test_pure_tenant_does_not_have_multiple_portal_contexts(): void {
		$tenant_id = $this->make_user( RoleManager::ROLE_TENANT );

		$this->assertFalse( $this->access->has_multiple_portal_contexts( $tenant_id ) );
	}
}
