<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Reports;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * SPEC.md §4.4: "occupancy rate = active leases ÷ total capacity (sum of
 * unit capacities), with the unit-count view still available."
 */
final class OccupancyBedsTest extends IntegrationTestCase {

	private Reports $reports;
	private Unit $units;
	private Lease $leases;
	private Tenant $tenants;
	private int $property_id;

	protected function setUp(): void {
		parent::setUp();

		$this->reports = new Reports();
		$this->units    = new Unit();
		$this->leases   = new Lease( $this->units );
		$this->tenants  = new Tenant();

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Occupancy Beds Test Property', 'city' => 'Kampala' ] );
	}

	private function make_unit( int $capacity ): int {
		return $this->units->insert(
			[
				'property_id' => $this->property_id,
				'unit_label'  => 'Beds Unit ' . $capacity . '-' . wp_generate_password( 6, false ),
				'rent_amount' => 500,
				'status'      => Unit::STATUS_VACANT,
				'capacity'    => $capacity,
			]
		);
	}

	private function make_active_lease( int $unit_id ): int {
		$tenant_id = $this->tenants->insert( [ 'full_name' => 'Beds Tenant ' . wp_generate_password( 6, false ) ] );

		return $this->leases->create(
			[
				'unit_id'        => $unit_id,
				'tenant_id'      => $tenant_id,
				'start_date'     => '2026-01-01',
				'end_date'       => '2027-01-01',
				'rent_amount'    => 500,
				'billing_day'    => 1,
				'deposit_amount' => 0,
				'deposit_status' => 'unpaid',
			]
		);
	}

	public function test_total_beds_is_the_sum_of_unit_capacities(): void {
		$this->make_unit( 1 );
		$this->make_unit( 4 );
		$this->make_unit( 2 );

		$result = $this->reports->occupancy_beds( [ $this->property_id ] );

		$this->assertSame( 7, $result['total'] );
	}

	public function test_filled_beds_counts_active_leases_not_units(): void {
		$hostel_unit_id = $this->make_unit( 3 );
		$this->make_active_lease( $hostel_unit_id );
		$this->make_active_lease( $hostel_unit_id );

		$result = $this->reports->occupancy_beds( [ $this->property_id ] );

		$this->assertSame( 2, $result['filled'] );
		$this->assertSame( 3, $result['total'] );
	}

	public function test_rate_is_filled_over_total_capacity(): void {
		$unit_id = $this->make_unit( 4 );
		$this->make_active_lease( $unit_id );

		$result = $this->reports->occupancy_beds( [ $this->property_id ] );

		$this->assertSame( 25, $result['rate'] );
	}

	public function test_zero_total_capacity_does_not_divide_by_zero(): void {
		$result = $this->reports->occupancy_beds( [ $this->property_id ] );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( 0, $result['rate'] );
	}
}
