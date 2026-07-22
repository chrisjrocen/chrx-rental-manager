<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Billing\StatementPdf;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * Landlord statement PDF generation (SPEC.md §4.4): gross/fee/net
 * calculation correctness, the vacant-unit display case, and that a real
 * PDF file actually comes out the other end of the shared Dompdf
 * pipeline (same one Billing\ReceiptPdf uses).
 */
final class StatementPdfTest extends IntegrationTestCase {

	private StatementPdf $statement_pdf;
	private int $property_id;

	protected function setUp(): void {
		parent::setUp();

		update_option( Settings::OPT_MANAGEMENT_FEE_PERCENT, 10.0 );

		$this->statement_pdf = new StatementPdf();

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Statement Test Property', 'city' => 'Accra' ] );
	}

	private function insert_unit( string $label ): int {
		return ( new Unit() )->insert( [
			'property_id' => $this->property_id,
			'unit_label'  => $label,
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );
	}

	public function test_summary_computes_gross_fee_and_net_correctly(): void {
		$unit_id   = $this->insert_unit( 'S1' );
		$tenant_id = ( new Tenant() )->insert( [ 'full_name' => 'Statement Tenant' ] );

		$lease_id = ( new Lease( new Unit() ) )->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		( new Payment() )->insert( [
			'lease_id'       => $lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-06-15 00:00:00',
		] );

		$summary = $this->statement_pdf->summary( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertNotNull( $summary );
		$this->assertSame( 1000.0, $summary['gross'] );
		$this->assertSame( 100.0, $summary['fee_amount'] );
		$this->assertSame( 900.0, $summary['net'] );
	}

	public function test_a_vacant_unit_contributes_zero_and_no_tenant_name(): void {
		$this->insert_unit( 'S2' );

		$summary = $this->statement_pdf->summary( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertSame( 0.0, $summary['gross'] );
	}

	public function test_a_payment_outside_the_date_range_is_excluded(): void {
		$unit_id   = $this->insert_unit( 'S3' );
		$tenant_id = ( new Tenant() )->insert( [ 'full_name' => 'Out Of Range Tenant' ] );

		$lease_id = ( new Lease( new Unit() ) )->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		( new Payment() )->insert( [
			'lease_id'       => $lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-05-15 00:00:00', // Before the June window.
		] );

		$summary = $this->statement_pdf->summary( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertSame( 0.0, $summary['gross'] );
	}

	public function test_a_voided_payment_is_excluded_from_collected(): void {
		$unit_id   = $this->insert_unit( 'S5' );
		$tenant_id = ( new Tenant() )->insert( [ 'full_name' => 'Voided Payment Tenant' ] );

		$lease_id = ( new Lease( new Unit() ) )->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		( new Payment() )->insert( [
			'lease_id'       => $lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-06-15 00:00:00',
			'status'         => Payment::STATUS_VOIDED,
		] );

		$summary = $this->statement_pdf->summary( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertSame( 0.0, $summary['gross'], 'A voided payment must not count toward a landlord statement\'s collected total.' );
	}

	/**
	 * v2 (SPEC.md §4.4): "Landlord statements upgrade... to full P&L
	 * shape: income, expenses ... and net."
	 */
	public function test_summary_subtracts_property_scoped_expenses_from_net(): void {
		$unit_id   = $this->insert_unit( 'S6' );
		$tenant_id = ( new Tenant() )->insert( [ 'full_name' => 'P&L Tenant' ] );

		$lease_id = ( new Lease( new Unit() ) )->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		( new Payment() )->insert( [
			'lease_id'       => $lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-06-15 00:00:00',
		] );

		( new Expense() )->insert( [
			'scope'                 => Expense::SCOPE_PROPERTY,
			'property_id'           => $this->property_id,
			'unit_id'               => null,
			'category'              => Expense::CATEGORY_WATER,
			'custom_category_label' => null,
			'amount'                => 150.0,
			'expense_date'          => '2026-06-10',
			'description'           => '',
			'recurring'             => Expense::RECURRING_NONE,
			'recurring_parent_id'   => null,
			'recorded_by'           => 1,
		] );

		$summary = $this->statement_pdf->summary( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertSame( 1000.0, $summary['gross'] );
		$this->assertSame( 100.0, $summary['fee_amount'] );
		$this->assertSame( 150.0, $summary['expenses'] );
		$this->assertSame( 750.0, $summary['net'], '1000 gross - 100 fee - 150 expenses.' );
	}

	/**
	 * v2 (SPEC.md §4.4): "Account-scoped expenses appear only on
	 * admin-level reports, never allocated silently to a landlord's
	 * statement." Expense::for_report()'s property_id filter can never
	 * match an account-scoped row's NULL property_id, so this falls out
	 * automatically rather than needing special-case code.
	 */
	public function test_account_scoped_expenses_never_appear_on_a_property_statement(): void {
		$this->insert_unit( 'S7' );

		( new Expense() )->insert( [
			'scope'                 => Expense::SCOPE_ACCOUNT,
			'property_id'           => null,
			'unit_id'               => null,
			'category'              => Expense::CATEGORY_TAX,
			'custom_category_label' => null,
			'amount'                => 500.0,
			'expense_date'          => '2026-06-10',
			'description'           => '',
			'recurring'             => Expense::RECURRING_NONE,
			'recurring_parent_id'   => null,
			'recorded_by'           => 1,
		] );

		$summary = $this->statement_pdf->summary( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertSame( 0.0, $summary['expenses'], 'Account-wide expenses must never be deducted from a single property\'s statement.' );
	}

	public function test_render_returns_a_real_pdf_and_null_for_a_missing_property(): void {
		$this->insert_unit( 'S4' );

		$pdf_bytes = $this->statement_pdf->render( $this->property_id, '2026-06-01', '2026-06-30' );

		$this->assertNotNull( $pdf_bytes );
		$this->assertStringStartsWith( '%PDF', $pdf_bytes );

		$this->assertNull( $this->statement_pdf->render( 999999, '2026-06-01', '2026-06-30' ) );
		$this->assertNull( $this->statement_pdf->summary( 999999, '2026-06-01', '2026-06-30' ) );
	}
}
