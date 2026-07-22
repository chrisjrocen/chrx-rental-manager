<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Cron\AlertDispatcher;
use ChrxRentalManager\Data\Alert;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

/**
 * v2 (SPEC.md §2/§4.8): "landlords can create, edit, and delete alerts
 * attached to their own properties/units... enforced with a dedicated
 * capability (rm_manage_own_alerts) and the same property-scoping helper
 * as everything else" — the build prompt's own explicit instruction to
 * "cover it with an explicit cross-owner denial test," modeled directly on
 * ReportsScopingTest's cross-owner pattern from V2-4.
 */
final class AlertsScopingTest extends IntegrationTestCase {

	private Access $access;
	private Alert $alerts;
	private AlertDispatcher $dispatcher;
	private int $property_a;
	private int $property_b;
	private int $landlord_a;
	private int $landlord_b;
	private int $unit_a;

	protected function setUp(): void {
		parent::setUp();

		( new RoleManager() )->register_roles();

		$this->access     = new Access();
		$this->alerts     = new Alert();
		$this->dispatcher = new AlertDispatcher();

		$properties       = new Property();
		$this->property_a = $properties->insert( [ 'name' => 'Owner A Property', 'city' => 'Accra' ] );
		$this->property_b = $properties->insert( [ 'name' => 'Owner B Property', 'city' => 'Accra' ] );

		$property_landlords = new PropertyLandlord();
		$this->landlord_a    = $this->make_landlord();
		$this->landlord_b    = $this->make_landlord();
		$property_landlords->assign( $this->property_a, $this->landlord_a );
		$property_landlords->assign( $this->property_b, $this->landlord_b );

		$units        = new Unit();
		$this->unit_a = $units->insert( [ 'property_id' => $this->property_a, 'unit_label' => 'SA1', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );
	}

	private function make_landlord(): int {
		return wp_insert_user( [
			'user_login' => 'alert_landlord_' . wp_generate_password( 8, false ),
			'user_email' => uniqid( 'alert_landlord_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_LANDLORD_OWNER,
		] );
	}

	public function test_landlord_owner_role_has_the_own_alerts_capability_but_not_the_staff_wide_one(): void {
		$this->assertTrue( user_can( $this->landlord_a, RoleManager::CAP_MANAGE_OWN_ALERTS ) );
		$this->assertFalse( user_can( $this->landlord_a, RoleManager::CAP_MANAGE_ALERTS ), 'Landlord-Owner must never hold the Staff-wide alerts capability -- SPEC.md §2\'s "single write capability" would otherwise be meaningless.' );
	}

	public function test_landlord_b_is_blocked_from_landlord_as_property_for_alert_management(): void {
		$this->assertFalse( $this->access->userCanAccessProperty( $this->landlord_b, $this->property_a ) );
		$this->assertTrue( $this->access->userCanAccessProperty( $this->landlord_a, $this->property_a ) );
	}

	public function test_landlord_bs_accessible_property_ids_never_include_landlord_as_property(): void {
		$scope_b = $this->access->accessiblePropertyIds( $this->landlord_b );

		$this->assertNotContains( $this->property_a, $scope_b );
		$this->assertSame( [ $this->property_b ], $scope_b );
	}

	/**
	 * SPEC.md §4.8 edge case: "Recipient resolution must respect scoping:
	 * a staff-created alert can't address users outside the creator's
	 * property scope." Landlord B's own recipient-resolution query (via
	 * AlertDispatcher, the same class the cron and banner renderers use)
	 * must never surface landlord A as a recipient candidate for an alert
	 * scoped to property B, and property_id_of() must correctly report
	 * which property an alert belongs to so AlertsListTable/authorize_entity
	 * can enforce the boundary.
	 */
	public function test_an_alert_scoped_to_property_a_resolves_to_property_a_not_property_b(): void {
		$alert_id = $this->alerts->insert( [
			'title'         => 'Owner A only',
			'message'       => 'Test',
			'entity_type'   => Alert::ENTITY_UNIT,
			'entity_id'     => $this->unit_a,
			'schedule_type' => Alert::SCHEDULE_ONCE,
			'scheduled_at'  => current_time( 'mysql' ),
			'recipients'    => [ 'selectors' => [ Alert::RECIPIENT_LANDLORD_OF_ENTITY ], 'user_ids' => [] ],
			'channels'      => [ 'email' ],
			'created_by'    => $this->landlord_a,
			'active'        => 1,
		] );

		$alert = $this->alerts->find( $alert_id );

		$this->assertSame( $this->property_a, $this->dispatcher->property_id_of( $alert ) );
		$this->assertNotSame( $this->property_b, $this->dispatcher->property_id_of( $alert ) );

		// The 'landlord_of_entity' selector must resolve to landlord A
		// (property A's own owner), never landlord B.
		$this->assertTrue( $this->dispatcher->is_recipient_of( $alert, null, $this->landlord_a ) );
		$this->assertFalse( $this->dispatcher->is_recipient_of( $alert, null, $this->landlord_b ) );
	}
}
