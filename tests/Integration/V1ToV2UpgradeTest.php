<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Migrator;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * Simulates a v1 site upgrading in place: seed data via the normal
 * repositories (which, on a real v1 site, would have been written before
 * any v2 code existed), then re-run Migrator::migrate() and assert the
 * existing financial/lease rows are byte-identical apart from the
 * documented v2 default backfills (SPEC.md §10's "all v1 sites upgrading
 * in place must keep working").
 */
final class V1ToV2UpgradeTest extends IntegrationTestCase {

	public function test_upgrade_does_not_alter_existing_leases_charges_or_payments(): void {
		global $wpdb;

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Upgrade Test Property', 'city' => 'Kampala' ] );

		$units   = new Unit();
		$unit_id = $units->insert(
			[
				'property_id' => $property_id,
				'unit_label'  => 'Unit U1',
				'rent_amount' => 800,
				'status'      => Unit::STATUS_VACANT,
			]
		);

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Upgrade Test Tenant' ] );

		$leases   = new Lease( $units );
		$lease_id = $leases->create(
			[
				'unit_id'        => $unit_id,
				'tenant_id'      => $tenant_id,
				'start_date'     => '2026-01-01',
				'end_date'       => '2027-01-01',
				'rent_amount'    => 800,
				'billing_day'    => 1,
				'deposit_amount' => 1600,
				'deposit_status' => 'paid',
			]
		);

		$charges   = new Charge();
		$charge_id = $charges->insert(
			[
				'lease_id'        => $lease_id,
				'period_start'    => '2026-01-01',
				'period_due_date' => '2026-01-01',
				'amount_due'      => 800,
				'type'            => Charge::TYPE_RENT,
				'status'          => Charge::STATUS_UNPAID,
			]
		);

		$payments   = new Payment();
		$payment_id = $payments->insert(
			[
				'lease_id'       => $lease_id,
				'charge_id'      => $charge_id,
				'amount'         => 800,
				'method'         => Payment::METHOD_CASH,
				'reference_note' => 'Upgrade test',
				'recorded_by'    => 1,
				'receipt_id'     => null,
				'paid_at'        => '2026-01-02 10:00:00',
			]
		);

		$lease_before   = $leases->find( $lease_id );
		$charge_before  = $charges->find( $charge_id );
		$payment_before = $payments->find( $payment_id );

		Migrator::migrate();

		$lease_after   = $leases->find( $lease_id );
		$charge_after  = $charges->find( $charge_id );
		$payment_after = $payments->find( $payment_id );

		// Original columns must be untouched.
		foreach ( $lease_before as $key => $value ) {
			$this->assertSame( $value, $lease_after[ $key ], "rm_leases.{$key} changed on upgrade." );
		}

		foreach ( $charge_before as $key => $value ) {
			$this->assertSame( $value, $charge_after[ $key ], "rm_charges.{$key} changed on upgrade." );
		}

		foreach ( $payment_before as $key => $value ) {
			$this->assertSame( $value, $payment_after[ $key ], "rm_payments.{$key} changed on upgrade." );
		}

		// New columns get the documented defaults, not arbitrary values.
		$this->assertSame( 'monthly', $lease_after['billing_cycle'] );
		$this->assertSame( '1', (string) $lease_after['cycle_months'] );
		$this->assertNull( $payment_after['gateway_transaction_id'] );

		// The v1 double-lease invariant (capacity default 1) still holds.
		$tenant_b_id = $tenants->insert( [ 'full_name' => 'Upgrade Test Tenant B' ] );

		$this->expectException( \ChrxRentalManager\Data\DuplicateActiveLeaseException::class );

		$leases->create(
			[
				'unit_id'        => $unit_id,
				'tenant_id'      => $tenant_b_id,
				'start_date'     => '2026-02-01',
				'end_date'       => '2027-02-01',
				'rent_amount'    => 800,
				'billing_day'    => 1,
				'deposit_amount' => 1600,
				'deposit_status' => 'paid',
			]
		);
	}

	public function test_upgrade_is_safe_to_run_twice(): void {
		Migrator::migrate();
		Migrator::migrate();

		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( \ChrxRentalManager\DB_SCHEMA_OPTION ) );
	}
}
