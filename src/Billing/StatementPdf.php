<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Billing;

use ChrxRentalManager\Admin\Support\ExpenseCategory;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Landlord statement PDF generator (SPEC.md §4.4, designs/22-landlord-statement-generator.html):
 * per-property income summary for a date range, reusing the exact same
 * Dompdf pipeline as Billing\ReceiptPdf rather than adding a second PDF
 * dependency (SPEC.md §4.4's explicit instruction).
 *
 * Deviation from schema: SPEC.md §3's data model has no table for a
 * persisted "statement" record — receipts get one (rm_receipts) because
 * SPEC.md §4.3 explicitly requires storing/emailing them, but §4.4 only
 * asks for "a formatted summary... suitable for sending to an owner",
 * not a saved history of every statement ever generated. This class
 * therefore generates the PDF live, on demand, every time — both the
 * staff-side generator (designs/22) and the landlord's read-only "My
 * statements" list (designs/28) call the same render() for a computed
 * set of recent periods rather than reading back a stored file.
 */
final class StatementPdf {

	private Property $properties;
	private Unit $units;
	private Lease $leases;
	private Tenant $tenants;
	private Payment $payments;
	private Expense $expenses;

	public function __construct(
		?Property $properties = null,
		?Unit $units = null,
		?Lease $leases = null,
		?Tenant $tenants = null,
		?Payment $payments = null,
		?Expense $expenses = null
	) {
		$this->properties = $properties ?? new Property();
		$this->units      = $units ?? new Unit();
		$this->leases     = $leases ?? new Lease();
		$this->tenants    = $tenants ?? new Tenant();
		$this->payments   = $payments ?? new Payment();
		$this->expenses   = $expenses ?? new Expense();
	}

	/**
	 * @param string $landlord_name Display name only — the actual data
	 *                               scoping is entirely by $property_id;
	 *                               this is never used to query anything.
	 *
	 * @return string|null raw PDF bytes, or null if the property doesn't exist.
	 */
	public function render( int $property_id, string $from, string $to, string $landlord_name = '' ): ?string {
		$property = $this->properties->find( $property_id );

		if ( null === $property ) {
			return null;
		}

		$line_items = $this->collect_line_items( $property_id, $from, $to );
		$totals     = $this->totals_for( $property_id, $line_items, $from, $to );
		$gross      = $totals['gross'];
		$fee_amount = $totals['fee_amount'];
		$expenses   = $totals['expenses'];
		$net        = $totals['net'];

		$expense_lines = array_map(
			static fn( array $row ): array => array(
				'label'  => ExpenseCategory::label_for( $row['category'], $row['custom_category_label'] ),
				'amount' => Money::format( (float) $row['amount'] ),
			),
			$totals['expense_rows']
		);

		$html = $this->statement_html(
			array(
				'company_name'  => Settings::company_name(),
				'property_name' => $property['name'],
				'landlord_name' => $landlord_name,
				'period_label'  => gmdate( 'F Y', strtotime( $from ) ),
				'prepared_date' => gmdate( 'j F Y', strtotime( current_time( 'Y-m-d' ) ) ),
				'line_items'    => $line_items,
				'gross'         => Money::format( $gross ),
				'fee_label'     => sprintf( /* translators: %s: fee percentage */ __( 'Management fee (%s%%)', 'chrx-rental-manager' ), rtrim( rtrim( number_format( Settings::management_fee_percent(), 1 ), '0' ), '.' ) ),
				'fee_amount'    => Money::format( $fee_amount ),
				'expense_lines' => $expense_lines,
				'expenses'      => Money::format( $expenses ),
				'net'           => Money::format( $net ),
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
	 * Data-only summary (no PDF rendering) for the landlord's "My
	 * statements" list (designs/28), which shows Gross/Net columns per
	 * row without needing a full PDF render just to display two numbers.
	 *
	 * @return array{gross:float,fee_amount:float,expenses:float,net:float}|null null if the property doesn't exist.
	 */
	public function summary( int $property_id, string $from, string $to ): ?array {
		if ( null === $this->properties->find( $property_id ) ) {
			return null;
		}

		$totals = $this->totals_for( $property_id, $this->collect_line_items( $property_id, $from, $to ), $from, $to );
		unset( $totals['expense_rows'] );

		return $totals;
	}

	/**
	 * v2 (SPEC.md §4.4): landlord statements upgrade from income-only to a
	 * full P&L — gross, management fee, this property's own expenses, net.
	 * Expenses come from Expense::for_report( $from, $to, $property_id ),
	 * which filters on `property_id = %d` — a SQL condition that can never
	 * match an account-scoped row's NULL property_id, so account-level
	 * expenses are excluded automatically, never silently allocated to a
	 * single owner's statement (SPEC.md §4.4's explicit instruction).
	 *
	 * @param array<int,array{unit_label:string,tenant_name:?string,collected:float}> $line_items
	 *
	 * @return array{gross:float,fee_amount:float,expenses:float,net:float,expense_rows:array<int,array<string,mixed>>}
	 */
	private function totals_for( int $property_id, array $line_items, string $from, string $to ): array {
		$gross        = array_sum( array_column( $line_items, 'collected' ) );
		$fee_amount   = round( $gross * ( Settings::management_fee_percent() / 100 ), 2 );
		$expense_rows = $this->expenses->for_report( $from, $to, $property_id );
		$expenses     = (float) array_sum( array_column( $expense_rows, 'amount' ) );

		return array(
			'gross'        => $gross,
			'fee_amount'   => $fee_amount,
			'expenses'     => $expenses,
			'net'          => $gross - $fee_amount - $expenses,
			'expense_rows' => $expense_rows,
		);
	}

	/**
	 * One row per unit in the property: the tenant whose lease overlaps
	 * the period (or "Vacant" if none), and the sum of payments recorded
	 * against any lease on that unit within [from, to] — summing across
	 * every lease on the unit, not just the currently active one, so a
	 * mid-period move-out/renewal doesn't silently drop payments made
	 * against the lease that preceded it.
	 *
	 * @return array<int,array{unit_label:string,tenant_name:?string,collected:float}>
	 */
	private function collect_line_items( int $property_id, string $from, string $to ): array {
		$rows = array();

		foreach ( $this->units->for_property( $property_id ) as $unit ) {
			$collected         = 0.0;
			$overlapping_lease = null;

			foreach ( $this->leases->for_unit( (int) $unit['id'] ) as $lease ) {
				foreach ( $this->payments->for_lease( (int) $lease['id'] ) as $payment ) {
					if ( Payment::STATUS_VOIDED === $payment['status'] ) {
						continue;
					}

					$paid_date = gmdate( 'Y-m-d', strtotime( $payment['paid_at'] ) );

					if ( $paid_date >= $from && $paid_date <= $to ) {
						$collected += (float) $payment['amount'];
					}
				}

				if ( $lease['start_date'] <= $to && $lease['end_date'] >= $from ) {
					// Prefer the lease active as of the period's end over
					// an earlier one that also overlapped, so a renewal
					// mid-period shows the current tenant.
					if ( null === $overlapping_lease || $lease['start_date'] > $overlapping_lease['start_date'] ) {
						$overlapping_lease = $lease;
					}
				}
			}

			$tenant_name = null;

			if ( null !== $overlapping_lease ) {
				$tenant      = $this->tenants->find( (int) $overlapping_lease['tenant_id'] );
				$tenant_name = null === $tenant ? null : $tenant['full_name'];
			}

			$rows[] = array(
				'unit_label'  => $unit['unit_label'],
				'tenant_name' => $tenant_name,
				'collected'   => $collected,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $data's keys are extracted into local variables the included template reads directly (same pattern as Billing\ReceiptPdf::receipt_html()).
	private function statement_html( array $data ): string {
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- see ReceiptPdf::receipt_html()'s identical, more fully documented use of this pattern.

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/pdf/statement.php';

		return (string) ob_get_clean();
	}
}
