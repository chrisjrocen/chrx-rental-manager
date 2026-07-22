<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Csv;
use ChrxRentalManager\Admin\Support\ExpenseCategory;
use ChrxRentalManager\Admin\Support\Reports;
use ChrxRentalManager\Billing\ExpenseReportPdf;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports (SPEC.md §4.4, designs/21-reports.html): occupancy, outstanding
 * balances, and payment history tabs, each CSV-exportable.
 *
 * Gated on rm_manage_properties (Staff/Admin), not rm_view_reports:
 * SPEC.md §2's role table does list "reports" under what a Landlord-Owner
 * may see, so RoleManager grants them rm_view_reports too — but
 * designs/21's multi-tab screen has no landlord-facing variant at all.
 * A Landlord-Owner's "reports" experience is designs/28's simpler
 * read-only list, served by StatementsController instead (which is
 * exactly why that controller doesn't use rm_view_reports as its
 * staff-vs-landlord branch signal either — see its own docblock).
 *
 * Still scoped to Staff's assigned properties via the same Access helper
 * every other controller uses — a Staff member is not automatically
 * "all properties" just because they can see this screen at all.
 */
final class ReportsController {

	private const PAGE_SLUG         = 'chrx-rm-reports';
	private const EXPORT_ACTION     = 'rm_reports_export_csv';
	private const EXPORT_PDF_ACTION = 'rm_reports_export_expense_pdf';

	private const TAB_OCCUPANCY   = 'occupancy';
	private const TAB_OUTSTANDING = 'outstanding';
	private const TAB_PAYMENTS    = 'payments';
	private const TAB_EXPENSES    = 'expenses';

	private Reports $reports;
	private Property $properties;
	private ExpenseReportPdf $expense_report_pdf;
	private Access $access;

	public function __construct( ?Reports $reports = null, ?Property $properties = null, ?ExpenseReportPdf $expense_report_pdf = null, ?Access $access = null ) {
		$this->reports            = $reports ?? new Reports();
		$this->properties         = $properties ?? new Property();
		$this->expense_report_pdf = $expense_report_pdf ?? new ExpenseReportPdf( $this->reports, $this->properties );
		$this->access             = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_' . self::EXPORT_PDF_ACTION, array( $this, 'handle_export_expense_pdf' ) );
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to view reports.', 'chrx-rental-manager' ), 403 );
		}

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );

		$available_properties = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_values(
				array_filter(
					$this->properties->all_active(),
					fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
				)
			);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_OUTSTANDING;

		if ( ! in_array( $tab, array( self::TAB_OCCUPANCY, self::TAB_OUTSTANDING, self::TAB_PAYMENTS, self::TAB_EXPENSES ), true ) ) {
			$tab = self::TAB_OUTSTANDING;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$selected_property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;

		if ( $selected_property_id > 0 && null !== $restrict_to_property_ids && ! in_array( $selected_property_id, $restrict_to_property_ids, true ) ) {
			$selected_property_id = 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$as_of = isset( $_GET['as_of'] ) ? sanitize_text_field( wp_unslash( $_GET['as_of'] ) ) : '';
		$as_of = false !== strtotime( $as_of ) ? $as_of : current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$from = false !== strtotime( $from ) ? $from : current_time( 'Y-m-01' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$to = false !== strtotime( $to ) ? $to : gmdate( 'Y-m-t', strtotime( $from ) );

		$effective_property_ids = $selected_property_id > 0 ? array( $selected_property_id ) : $restrict_to_property_ids;

		$units   = new Unit();
		$leases  = new Lease();
		$tenants = new Tenant();

		if ( self::TAB_OCCUPANCY === $tab ) {
			$occupancy_rows = $this->reports->occupancy_by_property( $effective_property_ids );
			$occupancy      = $this->reports->occupancy( $effective_property_ids );
			$occupancy_beds = $this->reports->occupancy_beds( $effective_property_ids );
		} elseif ( self::TAB_OUTSTANDING === $tab ) {
			$outstanding_rows = $this->reports->outstanding_balances( $effective_property_ids, $as_of );
			$outstanding      = $this->reports->outstanding_summary( $effective_property_ids );
			$avg_days_overdue = array() === $outstanding_rows
				? 0
				: (int) round( array_sum( array_column( $outstanding_rows, 'days_overdue' ) ) / count( $outstanding_rows ) );
		} elseif ( self::TAB_EXPENSES === $tab ) {
			$expense_rows            = $this->reports->expenses_in_scope( $effective_property_ids, $from, $to );
			$expense_total           = array_sum( array_column( $expense_rows, 'amount' ) );
			$expense_category_totals = array();

			foreach ( $expense_rows as $expense_row ) {
				$label                             = ExpenseCategory::label_for( $expense_row['category'], $expense_row['custom_category_label'] );
				$expense_category_totals[ $label ] = ( $expense_category_totals[ $label ] ?? 0.0 ) + (float) $expense_row['amount'];
			}

			arsort( $expense_category_totals );
		} else {
			$payment_rows = $this->reports->payments_in_scope( $effective_property_ids, '', $as_of );
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::EXPORT_ACTION,
					'tab'         => $tab,
					'property_id' => $selected_property_id,
					'as_of'       => $as_of,
					'from'        => $from,
					'to'          => $to,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);

		$export_pdf_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::EXPORT_PDF_ACTION,
					'property_id' => $selected_property_id,
					'from'        => $from,
					'to'          => $to,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_PDF_ACTION
		);

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/reports.php';
	}

	public function handle_export_csv(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_OUTSTANDING;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$as_of = isset( $_GET['as_of'] ) ? sanitize_text_field( wp_unslash( $_GET['as_of'] ) ) : current_time( 'Y-m-d' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : current_time( 'Y-m-01' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-t', strtotime( $from ) );

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );

		if ( $property_id > 0 && null !== $restrict_to_property_ids && ! in_array( $property_id, $restrict_to_property_ids, true ) ) {
			$property_id = 0;
		}

		$effective_property_ids = $property_id > 0 ? array( $property_id ) : $restrict_to_property_ids;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $tab ) . '-report-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		if ( self::TAB_OCCUPANCY === $tab ) {
			$this->write_occupancy_csv( $out, $effective_property_ids );
		} elseif ( self::TAB_OUTSTANDING === $tab ) {
			$this->write_outstanding_csv( $out, $effective_property_ids, $as_of );
		} elseif ( self::TAB_EXPENSES === $tab ) {
			$this->write_expenses_csv( $out, $effective_property_ids, $from, $to );
		} else {
			$this->write_payments_csv( $out, $effective_property_ids, $as_of );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a generated CSV directly to the HTTP response body (php://output).
		fclose( $out );
		exit;
	}

	public function handle_export_expense_pdf(): void {
		check_admin_referer( self::EXPORT_PDF_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to export reports.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : current_time( 'Y-m-01' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-t', strtotime( $from ) );

		if ( false === strtotime( $from ) || false === strtotime( $to ) ) {
			wp_die( esc_html__( 'Invalid date range.', 'chrx-rental-manager' ), 400 );
		}

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );

		if ( $property_id > 0 && null !== $restrict_to_property_ids && ! in_array( $property_id, $restrict_to_property_ids, true ) ) {
			$property_id = 0;
		}

		$effective_property_ids = $property_id > 0 ? array( $property_id ) : $restrict_to_property_ids;

		$pdf_bytes = $this->expense_report_pdf->render( $effective_property_ids, $from, $to );
		$filename  = 'expense-report-' . gmdate( 'Y-m-d', strtotime( $from ) ) . '-to-' . gmdate( 'Y-m-d', strtotime( $to ) );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf_bytes ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF binary, not HTML.
		echo $pdf_bytes;
		exit;
	}

	/**
	 * @param resource       $out
	 * @param array<int,int>|null $property_ids
	 */
	private function write_expenses_csv( $out, ?array $property_ids, string $from, string $to ): void {
		fputcsv( $out, array( 'Date', 'Scope', 'Category', 'Amount', 'Recurring' ) );

		foreach ( $this->reports->expenses_in_scope( $property_ids, $from, $to ) as $row ) {
			fputcsv(
				$out,
				Csv::safe_row(
					array(
						$row['expense_date'],
						$row['scope'],
						ExpenseCategory::label_for( $row['category'], $row['custom_category_label'] ),
						number_format( (float) $row['amount'], 2, '.', '' ),
						$row['recurring'],
					)
				)
			);
		}
	}

	/**
	 * @param resource       $out
	 * @param array<int,int>|null $property_ids
	 */
	private function write_occupancy_csv( $out, ?array $property_ids ): void {
		fputcsv( $out, array( 'Property', 'Occupied', 'Total units', 'Occupancy rate' ) );

		foreach ( $this->reports->occupancy_by_property( $property_ids ) as $row ) {
			$rate = $row['total'] > 0 ? round( $row['occupied'] / $row['total'] * 100 ) : 0;
			fputcsv(
				$out,
				Csv::safe_row(
					array(
						$row['property']['name'],
						$row['occupied'],
						$row['total'],
						$rate . '%',
					)
				)
			);
		}
	}

	/**
	 * @param resource       $out
	 * @param array<int,int>|null $property_ids
	 */
	private function write_outstanding_csv( $out, ?array $property_ids, string $as_of ): void {
		fputcsv( $out, array( 'Tenant', 'Unit', 'Property', 'Balance', 'Days overdue', 'Status' ) );

		foreach ( $this->reports->outstanding_balances( $property_ids, $as_of ) as $row ) {
			fputcsv(
				$out,
				Csv::safe_row(
					array(
						null === $row['tenant'] ? '' : $row['tenant']['full_name'],
						null === $row['unit'] ? '' : $row['unit']['unit_label'],
						null === $row['property'] ? '' : $row['property']['name'],
						number_format( (float) $row['balance'], 2, '.', '' ),
						$row['days_overdue'],
						$row['status'],
					)
				)
			);
		}
	}

	/**
	 * @param resource       $out
	 * @param array<int,int>|null $property_ids
	 */
	private function write_payments_csv( $out, ?array $property_ids, string $as_of ): void {
		$units   = new Unit();
		$leases  = new Lease();
		$tenants = new Tenant();

		fputcsv( $out, array( 'Date', 'Tenant', 'Unit', 'Property', 'Method', 'Amount' ) );

		foreach ( $this->reports->payments_in_scope( $property_ids, '', $as_of ) as $row ) {
			$lease    = $leases->find( (int) $row['lease_id'] );
			$unit     = null !== $lease ? $units->find( (int) $lease['unit_id'] ) : null;
			$tenant   = null !== $lease ? $tenants->find( (int) $lease['tenant_id'] ) : null;
			$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

			fputcsv(
				$out,
				Csv::safe_row(
					array(
						gmdate( 'Y-m-d', strtotime( $row['paid_at'] ) ),
						null === $tenant ? '' : $tenant['full_name'],
						null === $unit ? '' : $unit['unit_label'],
						null === $property ? '' : $property['name'],
						PaymentsListTable::method_label( $row['method'] ),
						number_format( (float) $row['amount'], 2, '.', '' ),
					)
				)
			);
		}
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
