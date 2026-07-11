<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Billing\PaymentAllocator;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * PaymentAllocator (SPEC.md §4.3): partial-payment status transitions and
 * the overpayment → unallocated-credit → auto-applied-to-next-charge
 * flow, against real DB rows since the charge-status sync and the
 * credit-sweep both depend on genuine payment/charge row state.
 */
final class PaymentAllocatorTest extends IntegrationTestCase {

	private Charge $charges;
	private Payment $payments;
	private Lease $leases;
	private PaymentAllocator $allocator;
	private int $lease_id;

	protected function setUp(): void {
		parent::setUp();

		$this->charges  = new Charge();
		$this->payments = new Payment();
		$this->leases   = new Lease();
		$this->allocator = new PaymentAllocator( $this->payments, $this->charges );

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Payment Allocator Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PA1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Payment Allocator Tenant' ] );

		$leases_repo    = new Lease( $units );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	private function insert_charge( float $amount_due ): int {
		return $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-03-01',
			'period_due_date' => '2026-03-01',
			'amount_due'      => $amount_due,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );
	}

	public function test_a_full_payment_marks_the_charge_paid(): void {
		$charge_id = $this->insert_charge( 1000.0 );

		$this->allocator->record_payment( $this->lease_id, $charge_id, 1000.0, Payment::METHOD_CASH, '', 1, '2026-03-01 00:00:00' );

		$charge = $this->charges->find( $charge_id );
		$this->assertSame( Charge::STATUS_PAID, $charge['status'] );
	}

	public function test_a_partial_payment_marks_the_charge_partial_and_leaves_a_remainder(): void {
		$charge_id = $this->insert_charge( 1000.0 );

		$this->allocator->record_payment( $this->lease_id, $charge_id, 400.0, Payment::METHOD_CASH, '', 1, '2026-03-01 00:00:00' );

		$charge = $this->charges->find( $charge_id );
		$this->assertSame( Charge::STATUS_PARTIAL, $charge['status'] );
	}

	public function test_an_overpayment_pays_the_charge_and_holds_the_excess_as_an_unallocated_credit(): void {
		$charge_id = $this->insert_charge( 1000.0 );

		$allocation = $this->allocator->record_payment( $this->lease_id, $charge_id, 1300.0, Payment::METHOD_CASH, '', 1, '2026-03-01 00:00:00' );

		$charge = $this->charges->find( $charge_id );
		$this->assertSame( Charge::STATUS_PAID, $charge['status'] );
		$this->assertSame( 300.0, $allocation['credit_applied'] );

		$credit = $this->payments->find( $allocation['credit_payment_id'] );
		$this->assertNull( $credit['charge_id'] );
		$this->assertSame( 300.0, (float) $credit['amount'] );
	}

	public function test_an_advance_payment_with_no_charge_selected_is_recorded_fully_unallocated(): void {
		$allocation = $this->allocator->record_payment( $this->lease_id, null, 500.0, Payment::METHOD_CASH, '', 1, '2026-03-01 00:00:00' );

		$primary = $this->payments->find( $allocation['primary_payment_id'] );
		$this->assertNull( $primary['charge_id'] );
		$this->assertSame( 500.0, (float) $primary['amount'] );
		$this->assertNull( $allocation['credit_payment_id'] );
	}

	public function test_apply_credits_to_charge_sweeps_an_existing_unallocated_credit_onto_a_new_charge(): void {
		// Simulates the SPEC.md §4.3 overpayment edge case end-to-end: an
		// earlier overpayment left a credit, and a later cron-generated
		// charge should have it auto-applied (Cron\ChargeGenerator calls
		// this same method right after inserting each new charge).
		$first_charge_id = $this->insert_charge( 1000.0 );
		$this->allocator->record_payment( $this->lease_id, $first_charge_id, 1300.0, Payment::METHOD_CASH, '', 1, '2026-03-01 00:00:00' );

		$second_charge_id = $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-04-01',
			'period_due_date' => '2026-04-01',
			'amount_due'      => 1000.0,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );

		$this->allocator->apply_credits_to_charge( $this->lease_id, $second_charge_id );

		$second_charge = $this->charges->find( $second_charge_id );
		$this->assertSame( Charge::STATUS_PARTIAL, $second_charge['status'], 'Only 300 of the 1000 due was covered by the credit.' );
		$this->assertSame( [], $this->payments->unallocated_for_lease( $this->lease_id ), 'The credit should be fully consumed, not left partially unallocated.' );
	}

	public function test_apply_credits_to_charge_splits_a_credit_larger_than_the_charge_it_covers(): void {
		$this->allocator->record_payment( $this->lease_id, null, 1500.0, Payment::METHOD_CASH, '', 1, '2026-02-01 00:00:00' );

		$charge_id = $this->insert_charge( 1000.0 );
		$this->allocator->apply_credits_to_charge( $this->lease_id, $charge_id );

		$charge = $this->charges->find( $charge_id );
		$this->assertSame( Charge::STATUS_PAID, $charge['status'] );

		$remaining_credit = $this->payments->unallocated_for_lease( $this->lease_id );
		$this->assertCount( 1, $remaining_credit );
		$this->assertSame( 500.0, (float) $remaining_credit[0]['amount'] );
	}

	public function test_a_waived_charge_is_never_reopened_by_charge_status_sync(): void {
		$charge_id = $this->insert_charge( 1000.0 );
		$this->charges->mark_waived( $charge_id );

		$this->allocator->record_payment( $this->lease_id, $charge_id, 1000.0, Payment::METHOD_CASH, '', 1, '2026-03-01 00:00:00' );

		$charge = $this->charges->find( $charge_id );
		$this->assertSame( Charge::STATUS_WAIVED, $charge['status'] );
	}
}
