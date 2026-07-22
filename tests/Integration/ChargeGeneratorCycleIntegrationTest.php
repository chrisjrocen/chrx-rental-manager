<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Cron\ChargeGenerator;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * v2 (SPEC.md §4.2): end-to-end coverage that a real, DB-backed lease with
 * a non-monthly billing_cycle/cycle_months produces rm_charges rows spaced
 * by the cycle, not every calendar month — the gap ChargeGeneratorTest's
 * pure unit tests can't close since they never touch generate()/$wpdb.
 */
final class ChargeGeneratorCycleIntegrationTest extends IntegrationTestCase {

	private Lease $leases;
	private Charge $charges;
	private ChargeGenerator $generator;
	private Unit $units;

	protected function setUp(): void {
		parent::setUp();

		$this->charges   = new Charge();
		$this->units      = new Unit();
		$this->leases     = new Lease( $this->units );
		$this->generator = new ChargeGenerator( $this->leases, $this->charges );
	}

	private function create_lease( string $billing_cycle, int $cycle_months ): int {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Cycle Test Property', 'city' => 'Accra' ] );

		$unit_id = $this->units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'CY1',
			'rent_amount' => 1500,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Cycle Test Tenant' ] );

		$today = new \DateTimeImmutable( current_time( 'Y-m-d' ) );

		return $this->leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+3 years' )->format( 'Y-m-d' ),
			'rent_amount'    => 1500,
			'billing_day'    => (int) $today->format( 'j' ),
			'billing_cycle'  => $billing_cycle,
			'cycle_months'   => $cycle_months,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	public function test_quarterly_lease_persists_cycle_fields_and_generates_a_charge(): void {
		$lease_id = $this->create_lease( Lease::CYCLE_QUARTERLY, 3 );

		$lease = $this->leases->find( $lease_id );
		$this->assertSame( Lease::CYCLE_QUARTERLY, $lease['billing_cycle'] );
		$this->assertSame( '3', (string) $lease['cycle_months'] );

		$this->generator->generate();

		$this->assertNotEmpty( $this->charges->for_lease( $lease_id ) );
	}

	public function test_second_charge_for_a_quarterly_lease_is_three_months_after_the_first(): void {
		$lease_id = $this->create_lease( Lease::CYCLE_QUARTERLY, 3 );

		// First run bills the period that started last month.
		$this->generator->generate();
		$charges_after_first_run = $this->charges->for_lease( $lease_id );
		$this->assertCount( 1, $charges_after_first_run );

		$first_period_start = new \DateTimeImmutable( $charges_after_first_run[0]['period_start'] );

		// Nothing new is due yet (next period is 3 months out, well beyond
		// the default lead window) — a second run must not create anything.
		$this->generator->generate();
		$this->assertCount( 1, $this->charges->for_lease( $lease_id ), 'A quarterly lease must not get a second charge before its next period is within the lead window.' );

		$expected_next_period_start = $first_period_start->modify( 'first day of +3 months' );
		$this->assertGreaterThan(
			new \DateTimeImmutable( current_time( 'Y-m-d' ) ),
			$expected_next_period_start,
			'Sanity check: the fixture must actually be testing a not-yet-due second period.'
		);
	}
}
