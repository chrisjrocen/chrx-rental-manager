<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Billing\ExpenseReportPdf;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Property;

/**
 * v2 (SPEC.md §4.4): Expense Report PDF — "totals by category and by
 * property/unit over a date range... reusing the existing PDF pipeline."
 * Mirrors StatementPdfTest's "assert real PDF bytes come out" coverage.
 */
final class ExpenseReportPdfTest extends IntegrationTestCase {

	private ExpenseReportPdf $expense_report_pdf;
	private int $property_id;

	protected function setUp(): void {
		parent::setUp();

		$this->expense_report_pdf = new ExpenseReportPdf();

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Expense Report Test Property', 'city' => 'Accra' ] );

		( new Expense() )->insert( [
			'scope'                 => Expense::SCOPE_PROPERTY,
			'property_id'           => $this->property_id,
			'unit_id'               => null,
			'category'              => Expense::CATEGORY_WATER,
			'custom_category_label' => null,
			'amount'                => 200.0,
			'expense_date'          => '2026-06-10',
			'description'           => '',
			'recurring'             => Expense::RECURRING_NONE,
			'recurring_parent_id'   => null,
			'recorded_by'           => 1,
		] );
	}

	public function test_render_returns_a_real_pdf(): void {
		$pdf_bytes = $this->expense_report_pdf->render( array( $this->property_id ), '2026-06-01', '2026-06-30' );

		$this->assertStringStartsWith( '%PDF', $pdf_bytes );
	}

	public function test_render_with_null_scope_still_produces_a_pdf(): void {
		$pdf_bytes = $this->expense_report_pdf->render( null, '2026-06-01', '2026-06-30' );

		$this->assertStringStartsWith( '%PDF', $pdf_bytes );
	}
}
