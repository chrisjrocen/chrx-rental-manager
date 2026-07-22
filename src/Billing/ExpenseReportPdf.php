<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Billing;

use ChrxRentalManager\Admin\Support\ExpenseCategory;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Admin\Support\Reports;
use ChrxRentalManager\Data\Property;
use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expense Report PDF (SPEC.md §4.4): "totals by category and by property/
 * unit over a date range... reusing the existing PDF pipeline" — same
 * Dompdf invocation as StatementPdf/ReceiptPdf, no new dependency. Like
 * StatementPdf, there is no persisted "report" row in SPEC.md §3's schema
 * (only receipts get one); this generates live, on demand, from
 * Reports::expenses_in_scope() — the same role-scoped query every other
 * admin report reuses, so account-scoped expenses only ever appear here
 * when the caller's $property_ids is null (Administrator), never for a
 * restricted Staff/Landlord view.
 */
final class ExpenseReportPdf {

	private Reports $reports;
	private Property $properties;

	public function __construct( ?Reports $reports = null, ?Property $properties = null ) {
		$this->reports    = $reports ?? new Reports();
		$this->properties = $properties ?? new Property();
	}

	/**
	 * @param array<int,int>|null $property_ids
	 */
	public function render( ?array $property_ids, string $from, string $to ): string {
		$rows = $this->reports->expenses_in_scope( $property_ids, $from, $to );

		$html = $this->report_html(
			array(
				'period_label'    => gmdate( 'j M Y', strtotime( $from ) ) . ' – ' . gmdate( 'j M Y', strtotime( $to ) ),
				'prepared_date'   => gmdate( 'j F Y', strtotime( current_time( 'Y-m-d' ) ) ),
				'category_totals' => $this->category_totals( $rows ),
				'property_totals' => $this->property_totals( $rows ),
				'total'           => Money::format( array_sum( array_column( $rows, 'amount' ) ) ),
			)
		);

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		return (string) $dompdf->output();
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 *
	 * @return array<int,array{label:string,amount:string}>
	 */
	private function category_totals( array $rows ): array {
		$totals = array();

		foreach ( $rows as $row ) {
			$label = ExpenseCategory::label_for( $row['category'], $row['custom_category_label'] );

			$totals[ $label ] = ( $totals[ $label ] ?? 0.0 ) + (float) $row['amount'];
		}

		arsort( $totals );

		$formatted = array();

		foreach ( $totals as $label => $amount ) {
			$formatted[] = array(
				'label'  => $label,
				'amount' => Money::format( $amount ),
			);
		}

		return $formatted;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 *
	 * @return array<int,array{label:string,amount:string}>
	 */
	private function property_totals( array $rows ): array {
		$totals = array();

		foreach ( $rows as $row ) {
			$label = __( 'Account-wide', 'chrx-rental-manager' );

			if ( null !== $row['property_id'] ) {
				$property = $this->properties->find( (int) $row['property_id'] );
				$label    = null === $property ? sprintf( '#%d', (int) $row['property_id'] ) : $property['name'];
			}

			$totals[ $label ] = ( $totals[ $label ] ?? 0.0 ) + (float) $row['amount'];
		}

		arsort( $totals );

		$formatted = array();

		foreach ( $totals as $label => $amount ) {
			$formatted[] = array(
				'label'  => $label,
				'amount' => Money::format( $amount ),
			);
		}

		return $formatted;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $data's keys are extracted into local variables the included template reads directly (same pattern as ReceiptPdf::receipt_html()).
	private function report_html( array $data ): string {
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- see ReceiptPdf::receipt_html()'s identical, more fully documented use of this pattern.

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/pdf/expense-report.php';

		return (string) ob_get_clean();
	}
}
