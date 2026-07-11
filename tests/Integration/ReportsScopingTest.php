<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Reports;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

/**
 * SPEC.md §4.4's landlord-scoping edge case, enforced at the query layer
 * (Support\Reports — the shared read model behind the dashboard, reports,
 * and statements screens): a Landlord-Owner querying their own scope must
 * never receive another owner's rows, verified here by actually attempting
 * cross-owner access and asserting the result is blocked/empty — not just
 * that the UI happens to hide it.
 */
final class ReportsScopingTest extends IntegrationTestCase {

	private Reports $reports;
	private Access $access;
	private int $property_a;
	private int $property_b;
	private int $landlord_a;
	private int $landlord_b;
	private int $lease_a;
	private int $lease_b;

	protected function setUp(): void {
		parent::setUp();

		( new RoleManager() )->register_roles();

		$this->reports = new Reports();
		$this->access  = new Access();

		$properties       = new Property();
		$this->property_a = $properties->insert( [ 'name' => 'Owner A Property', 'city' => 'Accra' ] );
		$this->property_b = $properties->insert( [ 'name' => 'Owner B Property', 'city' => 'Accra' ] );

		$property_landlords = new PropertyLandlord();
		$this->landlord_a    = $this->make_landlord();
		$this->landlord_b    = $this->make_landlord();
		$property_landlords->assign( $this->property_a, $this->landlord_a );
		$property_landlords->assign( $this->property_b, $this->landlord_b );

		$units   = new Unit();
		$unit_a  = $units->insert( [ 'property_id' => $this->property_a, 'unit_label' => 'A1', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );
		$unit_b  = $units->insert( [ 'property_id' => $this->property_b, 'unit_label' => 'B1', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );

		$tenants   = new Tenant();
		$tenant_a  = $tenants->insert( [ 'full_name' => 'Tenant A' ] );
		$tenant_b  = $tenants->insert( [ 'full_name' => 'Tenant B' ] );

		$leases_repo   = new Lease( $units );
		$today         = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		$this->lease_a = $leases_repo->create( [
			'unit_id'        => $unit_a,
			'tenant_id'      => $tenant_a,
			'start_date'     => $today->modify( '-3 months' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+9 months' )->format( 'Y-m-d' ),
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
		$this->lease_b = $leases_repo->create( [
			'unit_id'        => $unit_b,
			'tenant_id'      => $tenant_b,
			'start_date'     => $today->modify( '-3 months' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+9 months' )->format( 'Y-m-d' ),
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$charges = new Charge();
		$charges->insert( [
			'lease_id'        => $this->lease_a,
			'period_start'    => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'period_due_date' => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );
		$charges->insert( [
			'lease_id'        => $this->lease_b,
			'period_start'    => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'period_due_date' => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );

		$payments = new Payment();
		$payments->insert( [
			'lease_id'       => $this->lease_a,
			'charge_id'      => null,
			'amount'         => 500,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => current_time( 'mysql' ),
		] );
		$payments->insert( [
			'lease_id'       => $this->lease_b,
			'charge_id'      => null,
			'amount'         => 700,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => current_time( 'mysql' ),
		] );
	}

	private function make_landlord(): int {
		return wp_insert_user( [
			'user_login' => 'landlord_' . wp_generate_password( 8, false ),
			'user_email' => uniqid( 'landlord_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_LANDLORD_OWNER,
		] );
	}

	private function scope_for( int $landlord_id ): ?array {
		return $this->access->accessiblePropertyIds( $landlord_id );
	}

	public function test_occupancy_never_counts_another_owners_units(): void {
		$scope = $this->scope_for( $this->landlord_a );

		$this->assertSame( [ $this->property_a ], $scope );

		$occupancy = $this->reports->occupancy( $scope );
		$this->assertSame( 1, $occupancy['total'], 'Only landlord A\'s single unit should be counted, not both.' );
	}

	public function test_outstanding_balances_never_leaks_another_owners_lease(): void {
		$scope_a = $this->scope_for( $this->landlord_a );
		$rows    = $this->reports->outstanding_balances( $scope_a, current_time( 'Y-m-d' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->lease_a, (int) $rows[0]['lease']['id'] );

		foreach ( $rows as $row ) {
			$this->assertNotSame( $this->lease_b, (int) $row['lease']['id'], "Landlord A's outstanding-balances report must never contain landlord B's lease." );
		}
	}

	public function test_payments_in_scope_never_leaks_another_owners_payment(): void {
		$scope_a = $this->scope_for( $this->landlord_a );
		$rows    = $this->reports->payments_in_scope( $scope_a );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->lease_a, (int) $rows[0]['lease_id'] );
		$this->assertSame( 500.0, (float) $rows[0]['amount'] );
	}

	public function test_recent_payments_never_leaks_another_owners_payment(): void {
		$scope_b = $this->scope_for( $this->landlord_b );
		$rows    = $this->reports->recent_payments( $scope_b, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->lease_b, (int) $rows[0]['lease_id'] );
	}

	public function test_collected_this_month_only_sums_owned_properties(): void {
		$scope_a   = $this->scope_for( $this->landlord_a );
		$collected = $this->reports->collected_this_month( $scope_a );

		$this->assertSame( 500.0, $collected['total'] );
		$this->assertSame( 1, $collected['count'] );
	}

	public function test_expiring_within_never_leaks_another_owners_lease(): void {
		$scope_a = $this->scope_for( $this->landlord_a );
		$rows    = $this->reports->expiring_within( $scope_a, 365 );

		foreach ( $rows as $row ) {
			$this->assertNotSame( $this->lease_b, (int) $row['id'] );
		}
	}

	public function test_a_landlord_with_no_property_ids_at_all_sees_nothing_rather_than_everything(): void {
		// accessiblePropertyIds() returns [] (not null) for an unassigned
		// landlord — Reports must treat [] as "scope to nothing", the same
		// distinction Access::accessiblePropertyIds()'s own docblock warns
		// every caller about.
		$unassigned_landlord = $this->make_landlord();
		$scope                = $this->scope_for( $unassigned_landlord );

		$this->assertSame( [], $scope );
		$this->assertSame( 0, $this->reports->occupancy( $scope )['total'] );
		$this->assertSame( [], $this->reports->outstanding_balances( $scope, current_time( 'Y-m-d' ) ) );
		$this->assertSame( [], $this->reports->payments_in_scope( $scope ) );
	}

	public function test_administrator_null_scope_sees_both_owners_data(): void {
		// Sanity check on the other end of the contract: null (Admin) must
		// still see everything, so the scoping logic isn't accidentally
		// over-restrictive for the one role meant to see all of it. Checks
		// both leases are present rather than an exact row count — the
		// dev DB this integration suite runs against may carry other
		// seeded payments unrelated to this test's own fixtures.
		$lease_ids = array_map( static fn( array $row ): int => (int) $row['lease_id'], $this->reports->payments_in_scope( null ) );

		$this->assertContains( $this->lease_a, $lease_ids );
		$this->assertContains( $this->lease_b, $lease_ids );
	}

	/**
	 * The statement-download endpoint (Admin\StatementsController) doesn't
	 * query through Reports at all — it authorizes a single property id
	 * directly via Access::userCanAccessProperty(), which is the exact
	 * cross-owner attempt SPEC.md §4.4's deliverable calls out: landlord B
	 * requesting a statement for landlord A's property must be blocked,
	 * not merely have the link hidden from their own "My statements" list.
	 */
	public function test_landlord_b_is_blocked_from_landlord_as_property_for_statement_downloads(): void {
		$this->assertFalse( $this->access->userCanAccessProperty( $this->landlord_b, $this->property_a ) );
		$this->assertTrue( $this->access->userCanAccessProperty( $this->landlord_a, $this->property_a ) );
	}
}
