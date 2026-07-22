<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\CapacityExceededException;
use ChrxRentalManager\Data\DuplicateActiveLeaseException;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * SPEC.md §3.3: the capacity invariant generalizes v1's single-active-lease
 * rule. Default capacity 1 must reproduce v1's exact behavior (see
 * LeaseInvariantTest, which stays green unchanged); this suite covers
 * capacity > 1 (hostel-style per-bed units).
 */
final class LeaseCapacityGuardTest extends IntegrationTestCase {

	private Lease $leases;
	private Unit $units;
	private int $property_id;
	private array $tenant_ids;

	protected function setUp(): void {
		parent::setUp();

		$this->units  = new Unit();
		$this->leases = new Lease( $this->units );

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Capacity Test Property', 'city' => 'Kampala' ] );

		$tenants          = new Tenant();
		$this->tenant_ids = [];

		for ( $i = 0; $i < 5; $i++ ) {
			$this->tenant_ids[] = $tenants->insert( [ 'full_name' => "Capacity Tenant {$i}" ] );
		}
	}

	private function make_unit( int $capacity ): int {
		return $this->units->insert(
			[
				'property_id' => $this->property_id,
				'unit_label'  => 'Cap Unit ' . $capacity,
				'rent_amount' => 500,
				'status'      => Unit::STATUS_VACANT,
				'capacity'    => $capacity,
			]
		);
	}

	private function lease_data( int $unit_id, int $tenant_id ): array {
		return [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2027-01-01',
			'rent_amount'    => 500,
			'billing_day'    => 1,
			'deposit_amount' => 1000,
			'deposit_status' => 'paid',
		];
	}

	public function test_default_capacity_one_still_blocks_second_active_lease(): void {
		$unit_id = $this->make_unit( 1 );

		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[0] ) );

		$this->expectException( DuplicateActiveLeaseException::class );

		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[1] ) );
	}

	public function test_capacity_three_allows_three_active_leases(): void {
		$unit_id = $this->make_unit( 3 );

		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[0] ) );
		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[1] ) );
		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[2] ) );

		$this->assertSame( 3, $this->leases->count_active_for_unit( $unit_id ) );
	}

	public function test_capacity_three_blocks_a_fourth_active_lease(): void {
		$unit_id = $this->make_unit( 3 );

		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[0] ) );
		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[1] ) );
		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[2] ) );

		$this->expectException( CapacityExceededException::class );

		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[3] ) );
	}

	public function test_capacity_exceeded_exception_names_conflicting_leases(): void {
		$unit_id = $this->make_unit( 1 );

		$first_id = $this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[0] ) );

		try {
			$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[1] ) );
			$this->fail( 'Expected CapacityExceededException.' );
		} catch ( CapacityExceededException $e ) {
			$this->assertSame( [ $first_id ], $e->conflicting_lease_ids );
			$this->assertSame( 1, $e->capacity );
		}
	}

	public function test_ending_one_lease_on_a_full_unit_allows_a_new_one(): void {
		$unit_id = $this->make_unit( 2 );

		$first_id = $this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[0] ) );
		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[1] ) );

		$this->leases->change_status( $first_id, Lease::STATUS_ENDED );

		$third_id = $this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[2] ) );

		$this->assertIsInt( $third_id );
		$this->assertSame( 2, $this->leases->count_active_for_unit( $unit_id ) );
	}

	public function test_capacity_at_or_above_active_count_marks_unit_occupied_not_vacant(): void {
		$unit_id = $this->make_unit( 3 );

		$this->leases->create( $this->lease_data( $unit_id, $this->tenant_ids[0] ) );

		$unit = $this->units->find( $unit_id );
		$this->assertSame( Unit::STATUS_OCCUPIED, $unit['status'], 'A unit with capacity > 1 should still show occupied once any bed is leased.' );
	}
}
