<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * Receipt printing (SPEC.md §4.11): ReceiptPdf::data_for_receipt() — the
 * data source both Admin\PaymentsController::handle_print_receipt() and
 * Portal\PortalReceiptPrint::handle() feed into templates/print/receipt.php
 * — and the print template itself, rendered for real (not mocked) against
 * every Settings::RECEIPT_PRINT_FORMAT_* value.
 */
final class ReceiptPrintTest extends IntegrationTestCase {

	private Payment $payments;
	private ReceiptPdf $receipt_pdf;
	private int $lease_id;

	protected function setUp(): void {
		parent::setUp();

		$this->payments   = new Payment();
		$this->receipt_pdf = new ReceiptPdf();

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Print Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PR1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Print Tenant', 'email' => 'print-tenant@example.com' ] );

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

	private function generate_receipt(): array {
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

		return $this->receipt_pdf->generate_for_payment( $payment_id );
	}

	public function test_data_for_receipt_matches_the_generated_receipt(): void {
		$receipt = $this->generate_receipt();

		$data = $this->receipt_pdf->data_for_receipt( $receipt );

		$this->assertNotNull( $data );
		$this->assertSame( $receipt['receipt_number'], $data['receipt_number'] );
		$this->assertSame( 'Print Tenant', $data['tenant_name'] );
		$this->assertSame( 'PR1', $data['unit_label'] );
		$this->assertNull( $data['credit_applied'] );

		unlink( $this->receipt_pdf->absolute_path( $receipt ) );
	}

	public function test_data_for_receipt_returns_null_when_the_payment_no_longer_exists(): void {
		$receipts = new Receipt();
		$receipt_id = $receipts->insert( [
			'payment_id'     => 999999,
			'receipt_number' => 'RC-0000-0000',
			'pdf_path'       => '',
			'emailed_at'     => null,
		] );

		$data = $this->receipt_pdf->data_for_receipt( $receipts->find( $receipt_id ) );

		$this->assertNull( $data );
	}

	/**
	 * @dataProvider paper_format_provider
	 */
	public function test_print_template_renders_the_correct_paper_class_and_page_rule( string $paper_format, string $expected_body_class, string $expected_page_rule_fragment ): void {
		$receipt = $this->generate_receipt();
		$data    = $this->receipt_pdf->data_for_receipt( $receipt );
		$back_url = null;

		extract( $data, EXTR_SKIP );

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/print/receipt.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="' . $expected_body_class . '"', $html );
		$this->assertStringContainsString( $expected_page_rule_fragment, $html );
		$this->assertStringContainsString( $receipt['receipt_number'], $html );
		$this->assertStringContainsString( 'Print Tenant', $html );

		unlink( $this->receipt_pdf->absolute_path( $receipt ) );
	}

	public function paper_format_provider(): array {
		return [
			'A4'          => [ Settings::RECEIPT_PRINT_FORMAT_A4, 'chrx-rm-paper-a4', 'size: A4' ],
			'Letter'      => [ Settings::RECEIPT_PRINT_FORMAT_LETTER, 'chrx-rm-paper-letter', 'size: Letter' ],
			'Thermal 58'  => [ Settings::RECEIPT_PRINT_FORMAT_THERMAL_58, 'chrx-rm-paper-thermal-58', 'size: 58mm auto' ],
			'Thermal 80'  => [ Settings::RECEIPT_PRINT_FORMAT_THERMAL_80, 'chrx-rm-paper-thermal-80', 'size: 80mm auto' ],
		];
	}

	public function test_receipt_print_format_setting_defaults_to_a4_and_rejects_invalid_values(): void {
		$this->assertSame( Settings::RECEIPT_PRINT_FORMAT_A4, Settings::receipt_print_format() );

		update_option( Settings::OPT_RECEIPT_PRINT_FORMAT, Settings::RECEIPT_PRINT_FORMAT_THERMAL_80 );
		$this->assertSame( Settings::RECEIPT_PRINT_FORMAT_THERMAL_80, Settings::receipt_print_format() );

		update_option( Settings::OPT_RECEIPT_PRINT_FORMAT, 'not-a-real-format' );
		$this->assertSame( Settings::RECEIPT_PRINT_FORMAT_A4, Settings::receipt_print_format() );
	}
}
