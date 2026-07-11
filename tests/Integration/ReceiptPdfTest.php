<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Billing\ReceiptMailer;
use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * PDF receipt generation (SPEC.md §4.3) end-to-end: a real payment row
 * produces a real receipt row + a real PDF file on disk, and the mailer
 * correctly skips (not fails) a tenant with no email on file, logging the
 * skip per SPEC.md §5's auditability requirement.
 */
final class ReceiptPdfTest extends IntegrationTestCase {

	private Payment $payments;
	private Charge $charges;
	private Receipt $receipts;
	private NotificationLog $notifications;
	private ReceiptPdf $receipt_pdf;
	private int $lease_id;

	protected function setUp(): void {
		parent::setUp();

		$this->payments      = new Payment();
		$this->charges       = new Charge();
		$this->receipts      = new Receipt();
		$this->notifications = new NotificationLog();
		$this->receipt_pdf   = new ReceiptPdf();

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Receipt Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'RC1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Receipt Tenant', 'email' => '' ] );

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

	public function test_generating_a_receipt_creates_a_numbered_row_and_a_real_pdf_file_on_disk(): void {
		$payment_id = $this->payments->insert( [
			'lease_id'       => $this->lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-03-01 00:00:00',
		] );

		$receipt = $this->receipt_pdf->generate_for_payment( $payment_id );

		$this->assertNotNull( $receipt );
		$this->assertMatchesRegularExpression( '/^RC-\d{4}-\d{4}$/', $receipt['receipt_number'] );
		$this->assertFileExists( $this->receipt_pdf->absolute_path( $receipt ) );

		$payment = $this->payments->find( $payment_id );
		$this->assertSame( (int) $receipt['id'], (int) $payment['receipt_id'] );

		unlink( $this->receipt_pdf->absolute_path( $receipt ) );
	}

	public function test_emailing_a_receipt_for_a_tenant_with_no_email_is_skipped_and_logged_not_failed(): void {
		$payment_id = $this->payments->insert( [
			'lease_id'       => $this->lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-03-01 00:00:00',
		] );

		$receipt = $this->receipt_pdf->generate_for_payment( $payment_id );
		$mailer  = new ReceiptMailer( $this->payments, new Lease(), new Tenant(), $this->receipt_pdf, $this->receipts, $this->notifications );

		$sent = $mailer->send( $receipt );

		$this->assertFalse( $sent );

		global $wpdb;
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}rm_notifications_log WHERE type = %s AND entity_id = %d",
				'receipt_emailed',
				$receipt['id']
			)
		);
		$this->assertSame( NotificationLog::STATUS_SKIPPED, $status );

		unlink( $this->receipt_pdf->absolute_path( $receipt ) );
	}

	public function test_a_successful_email_marks_the_receipt_as_emailed(): void {
		// pre_wp_mail short-circuits wp_mail() with a deterministic result
		// instead of depending on this environment's real mail transport —
		// the point under test is ReceiptMailer's own bookkeeping
		// (Receipt::mark_emailed()), not mail delivery itself.
		add_filter( 'pre_wp_mail', fn () => true );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Emailed Tenant', 'email' => 'emailed-tenant@example.com' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => ( new Property() )->insert( [ 'name' => 'Email Test Property', 'city' => 'Accra' ] ),
			'unit_label'  => 'EM1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$lease_id = ( new Lease( $units ) )->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$payment_id = $this->payments->insert( [
			'lease_id'       => $lease_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => '2026-03-01 00:00:00',
		] );

		$receipt = $this->receipt_pdf->generate_for_payment( $payment_id );
		$mailer  = new ReceiptMailer( $this->payments, new Lease(), $tenants, $this->receipt_pdf, $this->receipts, $this->notifications );

		$sent = $mailer->send( $receipt );

		$this->assertTrue( $sent );

		$updated_receipt = $this->receipts->find( (int) $receipt['id'] );
		$this->assertNotNull( $updated_receipt['emailed_at'] );

		remove_all_filters( 'pre_wp_mail' );
		unlink( $this->receipt_pdf->absolute_path( $receipt ) );
	}
}
