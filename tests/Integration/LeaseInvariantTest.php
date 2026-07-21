<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\DuplicateActiveLeaseException;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * SPEC.md §4.1: a unit can never have two simultaneously active leases.
 * Enforced at the data layer, not just the UI.
 */
final class LeaseInvariantTest extends IntegrationTestCase {

	private Lease $leases;
	private Unit $units;
	private int $unit_id;
	private int $tenant_a_id;
	private int $tenant_b_id;

	protected function setUp(): void {
		parent::setUp();

		$this->units  = new Unit();
		$this->leases = new Lease( $this->units );

		$properties = new Property();
		$property_id = $properties->insert( [ 'name' => 'Invariant Test Property', 'city' => 'Accra' ] );

		$this->unit_id = $this->units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'Unit Z1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants           = new Tenant();
		$this->tenant_a_id = $tenants->insert( [ 'full_name' => 'Tenant A' ] );
		$this->tenant_b_id = $tenants->insert( [ 'full_name' => 'Tenant B' ] );
	}

	private function lease_data( int $tenant_id ): array {
		return [
			'unit_id'        => $this->unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2027-01-01',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 2000,
			'deposit_status' => 'paid',
		];
	}

	public function test_second_active_lease_on_same_unit_is_rejected(): void {
		$this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$this->expectException( DuplicateActiveLeaseException::class );

		$this->leases->create( $this->lease_data( $this->tenant_b_id ) );
	}

	public function test_ending_first_lease_allows_a_new_active_lease(): void {
		$first_id = $this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$this->leases->change_status( $first_id, Lease::STATUS_ENDED );

		$second_id = $this->leases->create( $this->lease_data( $this->tenant_b_id ) );

		$this->assertIsInt( $second_id );
		$this->assertNotSame( $first_id, $second_id );
	}

	public function test_active_lease_creation_marks_unit_occupied(): void {
		$this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$unit = $this->units->find( $this->unit_id );
		$this->assertSame( Unit::STATUS_OCCUPIED, $unit['status'] );
	}

	public function test_ending_lease_marks_unit_vacant(): void {
		$lease_id = $this->leases->create( $this->lease_data( $this->tenant_a_id ) );
		$this->leases->change_status( $lease_id, Lease::STATUS_ENDED );

		$unit = $this->units->find( $this->unit_id );
		$this->assertSame( Unit::STATUS_VACANT, $unit['status'] );
	}

	public function test_manual_maintenance_override_is_not_cleared_by_lease_end(): void {
		$lease_id = $this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$this->units->set_manual_status( $this->unit_id, Unit::STATUS_MAINTENANCE );

		$this->leases->change_status( $lease_id, Lease::STATUS_ENDED );

		$unit = $this->units->find( $this->unit_id );
		$this->assertSame(
			Unit::STATUS_MAINTENANCE,
			$unit['status'],
			'Ending a lease must not silently clear a manual maintenance/reserved override.'
		);
	}

	public function test_changing_status_to_active_also_respects_the_invariant(): void {
		$this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$second_id = $this->leases->create( array_merge(
			$this->lease_data( $this->tenant_b_id ),
			[ 'status' => Lease::STATUS_ENDED ]
		) );

		$this->expectException( DuplicateActiveLeaseException::class );

		$this->leases->change_status( $second_id, Lease::STATUS_ACTIVE );
	}

	/**
	 * Regression test: soft-deleting (trashing) a lease must resync the
	 * unit's derived status the same way change_status() does, or the unit
	 * is left stuck at 'occupied' and its own trash/delete action is wrongly
	 * blocked afterward.
	 */
	public function test_soft_deleting_lease_marks_unit_vacant(): void {
		$lease_id = $this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$this->leases->soft_delete( $lease_id );

		$unit = $this->units->find( $this->unit_id );
		$this->assertSame( Unit::STATUS_VACANT, $unit['status'] );
		$this->assertNull( $this->leases->active_lease_for_unit( $this->unit_id ) );
	}

	public function test_soft_deleting_lease_respects_manual_maintenance_override(): void {
		$lease_id = $this->leases->create( $this->lease_data( $this->tenant_a_id ) );

		$this->units->set_manual_status( $this->unit_id, Unit::STATUS_MAINTENANCE );

		$this->leases->soft_delete( $lease_id );

		$unit = $this->units->find( $this->unit_id );
		$this->assertSame( Unit::STATUS_MAINTENANCE, $unit['status'] );
	}
}
