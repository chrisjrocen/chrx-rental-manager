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
 * DB-facing coverage for ChargeGenerator::generate() — dedup behavior
 * that the pure unit tests (ChargeGeneratorTest) can't exercise since it
 * needs real inserted rows to check against.
 */
final class ChargeGeneratorIntegrationTest extends IntegrationTestCase {

	private Lease $leases;
	private Charge $charges;
	private ChargeGenerator $generator;
	private int $lease_id;

	protected function setUp(): void {
		parent::setUp();

		$this->charges   = new Charge();
		$this->leases     = new Lease();
		$this->generator = new ChargeGenerator( $this->leases, $this->charges );

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Cron Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'CG1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Charge Gen Tenant' ] );

		$leases_repo    = new Lease( $units );
		$today          = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+11 months' )->format( 'Y-m-d' ),
			'rent_amount'    => 1000,
			'billing_day'    => (int) $today->format( 'j' ), // due today, well inside any lead window
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	public function test_generate_creates_a_charge_for_a_due_period(): void {
		$created = $this->generator->generate();

		$this->assertGreaterThanOrEqual( 1, $created );
		$this->assertNotEmpty( $this->charges->for_lease( $this->lease_id ) );
	}

	public function test_generate_does_not_create_a_duplicate_charge_once_all_due_periods_are_covered(): void {
		// The test lease spans two simultaneously-due periods (it started
		// a month ago), so the first two runs each legitimately create a
		// *different* period's charge; a third run must not create a
		// third, since nothing new has become due.
		$this->generator->generate();
		$this->generator->generate();
		$stable_count = count( $this->charges->for_lease( $this->lease_id ) );

		$this->generator->generate();
		$third_run_count = count( $this->charges->for_lease( $this->lease_id ) );

		$this->assertSame( $stable_count, $third_run_count, 'Once every currently-due period is covered, another run must not create a duplicate.' );
	}

	public function test_generate_skips_leases_that_are_not_active(): void {
		$this->leases->change_status( $this->lease_id, Lease::STATUS_ENDED );

		$this->generator->generate();

		$this->assertSame( [], $this->charges->for_lease( $this->lease_id ) );
	}
}
