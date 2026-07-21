<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * Balance calculations feeding the Tenants/Leases list "Balance" column
 * and the Lease detail stat cards (Phase 3's Admin CRUD).
 */
final class LedgerTest extends IntegrationTestCase {

	private Ledger $ledger;
	private Charge $charges;
	private Payment $payments;
	private int $lease_id;
	private int $tenant_id;

	protected function setUp(): void {
		parent::setUp();

		$this->ledger   = new Ledger();
		$this->charges  = new Charge();
		$this->payments = new Payment();

		$properties = new Property();
		$property_id = $properties->insert( [ 'name' => 'Ledger Test Property', 'city' => 'Accra' ] );

		$units = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'L1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants = new Tenant();
		$this->tenant_id = $tenants->insert( [ 'full_name' => 'Ledger Tenant' ] );

		$leases = new Lease( $units );
		$this->lease_id = $leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 2000,
			'deposit_status' => 'paid',
		] );
	}

	public function test_unpaid_charge_is_fully_outstanding(): void {
		$this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-01-01',
			'period_due_date' => '2026-01-01',
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );

		$this->assertSame( 1000.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
		$this->assertSame( 1000.0, $this->ledger->outstanding_balance_for_tenant( $this->tenant_id ) );
	}

	public function test_partial_payment_reduces_outstanding_balance(): void {
		$charge_id = $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-01-01',
			'period_due_date' => '2026-01-01',
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_PARTIAL,
		] );

		$this->payments->insert( [
			'lease_id'    => $this->lease_id,
			'charge_id'   => $charge_id,
			'amount'      => 400,
			'method'      => Payment::METHOD_CASH,
			'recorded_by' => 1,
			'paid_at'     => current_time( 'mysql' ),
		] );

		$this->assertSame( 600.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
		$this->assertSame( 400.0, $this->ledger->paid_to_date_for_lease( $this->lease_id ) );
	}

	public function test_fully_paid_charge_has_zero_outstanding(): void {
		$charge_id = $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-01-01',
			'period_due_date' => '2026-01-01',
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_PAID,
		] );

		$this->payments->insert( [
			'lease_id'    => $this->lease_id,
			'charge_id'   => $charge_id,
			'amount'      => 1000,
			'method'      => Payment::METHOD_CASH,
			'recorded_by' => 1,
			'paid_at'     => current_time( 'mysql' ),
		] );

		$this->assertSame( 0.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
	}

	public function test_waived_charge_is_excluded_from_balance(): void {
		$this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-02-01',
			'period_due_date' => '2026-02-06',
			'amount_due'      => 50,
			'type'            => Charge::TYPE_LATE_FEE,
			'status'          => Charge::STATUS_WAIVED,
		] );

		$this->assertSame( 0.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
	}

	public function test_overpayment_does_not_go_negative(): void {
		$charge_id = $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-01-01',
			'period_due_date' => '2026-01-01',
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_PAID,
		] );

		$this->payments->insert( [
			'lease_id'    => $this->lease_id,
			'charge_id'   => $charge_id,
			'amount'      => 1200,
			'method'      => Payment::METHOD_CASH,
			'recorded_by' => 1,
			'paid_at'     => current_time( 'mysql' ),
		] );

		$this->assertSame( 0.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
	}

	public function test_voided_payment_is_excluded_from_balance(): void {
		$charge_id = $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-01-01',
			'period_due_date' => '2026-01-01',
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_PAID,
		] );

		$payment_id = $this->payments->insert( [
			'lease_id'    => $this->lease_id,
			'charge_id'   => $charge_id,
			'amount'      => 1000,
			'method'      => Payment::METHOD_CASH,
			'recorded_by' => 1,
			'paid_at'     => current_time( 'mysql' ),
		] );

		$this->assertSame( 0.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
		$this->assertSame( 1000.0, $this->ledger->paid_to_date_for_lease( $this->lease_id ) );

		$this->payments->void( $payment_id, 'recorded in error', 1 );

		$this->assertSame( 1000.0, $this->ledger->outstanding_balance_for_lease( $this->lease_id ) );
		$this->assertSame( 0.0, $this->ledger->paid_to_date_for_lease( $this->lease_id ) );
	}
}
