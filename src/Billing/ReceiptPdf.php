<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Billing;

use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF receipt generation (SPEC.md §4.3, designs/19-receipt-preview.html).
 *
 * PDF library choice: dompdf/dompdf. It's pure-PHP with no external
 * binary/extension dependency (no wkhtmltopdf, no headless Chrome), which
 * matters for the "standard/shared hosting" target audience in SPEC.md §6
 * — a site owner on shared hosting can `composer install` this without
 * needing shell access to install a system binary. Its HTML/CSS subset is
 * more than enough for a one-page receipt, and Phase 6 reuses this same
 * Dompdf pipeline for landlord statement PDFs rather than adding a second
 * PDF dependency (SPEC.md §4.4's explicit instruction).
 */
final class ReceiptPdf {

	private Payment $payments;
	private Charge $charges;
	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Property $properties;
	private Receipt $receipts;
	private Ledger $ledger;

	public function __construct(
		?Payment $payments = null,
		?Charge $charges = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?Property $properties = null,
		?Receipt $receipts = null,
		?Ledger $ledger = null
	) {
		$this->payments   = $payments ?? new Payment();
		$this->charges    = $charges ?? new Charge();
		$this->leases     = $leases ?? new Lease();
		$this->units      = $units ?? new Unit();
		$this->tenants    = $tenants ?? new Tenant();
		$this->properties = $properties ?? new Property();
		$this->receipts   = $receipts ?? new Receipt();
		$this->ledger     = $ledger ?? new Ledger( $this->charges, $this->payments, $this->leases );
	}

	/**
	 * Generates and stores the PDF, inserting the rm_receipts row and
	 * linking it back onto the payment. Returns the receipt row.
	 *
	 * $credit_applied is the portion of this transaction (if any) that
	 * exceeded the target charge and became an unallocated credit
	 * (SPEC.md §4.3 overpayment edge case) — shown as a second line on the
	 * receipt so the tenant can see their full payment was accounted for,
	 * not just the part that reduced this specific charge.
	 *
	 * @return array<string,mixed>|null null if the payment doesn't exist.
	 */
	public function generate_for_payment( int $payment_id, float $credit_applied = 0.0 ): ?array {
		$payment = $this->payments->find( $payment_id );

		if ( null === $payment ) {
			return null;
		}

		// A temporary, guaranteed-unique placeholder avoids a race on the
		// UNIQUE receipt_number column between the insert and the
		// formatted-number update just below.
		$receipt_id = $this->receipts->insert(
			array(
				'payment_id'     => $payment_id,
				'receipt_number' => 'RC-TMP-' . $payment_id . '-' . wp_generate_password( 8, false ),
				'pdf_path'       => '',
				'emailed_at'     => null,
			)
		);

		if ( false === $receipt_id ) {
			return null;
		}

		$receipt_number = sprintf( 'RC-%s-%04d', gmdate( 'Y' ), $receipt_id );

		$pdf_bytes = $this->render_pdf( $payment, $receipt_number, $credit_applied );
		$pdf_path  = $this->store_pdf( $receipt_number, $pdf_bytes );

		$this->receipts->update(
			$receipt_id,
			array(
				'receipt_number' => $receipt_number,
				'pdf_path'       => $pdf_path,
			)
		);

		$this->payments->attach_receipt( $payment_id, $receipt_id );

		return $this->receipts->find( $receipt_id );
	}

	public function absolute_path( array $receipt ): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . $receipt['pdf_path'];
	}

	/**
	 * Builds the same template-ready data set render_pdf() feeds Dompdf,
	 * for reuse by the print-CSS HTML view (Admin\PaymentsController's and
	 * Portal\PortalReceiptPrint's "Print" actions) — a fresh render off
	 * current payment/lease/tenant data rather than the stored PDF bytes,
	 * so it always reflects e.g. a tenant renamed after the fact.
	 *
	 * $credit_applied isn't persisted on the rm_receipts row, so a
	 * historical overpayment's "held as credit" line can only be
	 * reconstructed at generation time (render_pdf()) — the print view
	 * always passes 0.0 here and simply won't show that line for older
	 * receipts. The PDF itself (already rendered and stored) is
	 * unaffected and keeps showing it correctly.
	 *
	 * @return array<string,mixed>|null null if the receipt's payment no longer exists.
	 */
	public function data_for_receipt( array $receipt ): ?array {
		$payment = $this->payments->find( (int) $receipt['payment_id'] );

		if ( null === $payment ) {
			return null;
		}

		return $this->build_receipt_data( $payment, (string) $receipt['receipt_number'], 0.0 );
	}

	/**
	 * @param array<string,mixed> $payment
	 * @return array<string,mixed>
	 */
	private function build_receipt_data( array $payment, string $receipt_number, float $credit_applied ): array {
		$lease    = $this->leases->find( (int) $payment['lease_id'] );
		$charge   = null !== $payment['charge_id'] ? $this->charges->find( (int) $payment['charge_id'] ) : null;
		$unit     = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;
		$tenant   = null !== $lease ? $this->tenants->find( (int) $lease['tenant_id'] ) : null;
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		$balance = null !== $lease ? $this->ledger->outstanding_balance_for_lease( (int) $lease['id'] ) : 0.0;

		$line_label = null !== $charge
			? sprintf(
				/* translators: 1: charge type, 2: period month/year */
				__( '%1$s — %2$s', 'chrx-rental-manager' ),
				Charge::TYPE_LATE_FEE === $charge['type'] ? __( 'Late fee', 'chrx-rental-manager' ) : __( 'Rent', 'chrx-rental-manager' ),
				gmdate( 'M Y', strtotime( $charge['period_start'] ) )
			)
			: __( 'Advance / unallocated payment', 'chrx-rental-manager' );

		$total = (float) $payment['amount'] + $credit_applied;

		return array(
			'receipt_number'  => $receipt_number,
			'company_name'    => Settings::company_name(),
			'company_address' => Settings::company_address(),
			'company_phone'   => Settings::company_phone(),
			'tenant_name'     => null === $tenant ? '' : $tenant['full_name'],
			'unit_label'      => null === $unit ? '' : $unit['unit_label'],
			'property_name'   => null === $property ? '' : $property['name'],
			'paid_at'         => $payment['paid_at'],
			'line_label'      => $line_label,
			'amount'          => Money::format( (float) $payment['amount'] ),
			'credit_applied'  => $credit_applied > 0 ? Money::format( $credit_applied ) : null,
			'total'           => Money::format( $total ),
			'method'          => $payment['method'],
			'balance'         => Money::format( $balance ),
		);
	}

	/**
	 * @param array<string,mixed> $payment
	 */
	private function render_pdf( array $payment, string $receipt_number, float $credit_applied ): string {
		$html = $this->receipt_html( $this->build_receipt_data( $payment, $receipt_number, $credit_applied ) );

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' ); // Ships with dompdf; covers the currency symbol glyphs used here (e.g. GH₵) that Helvetica lacks.

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A5' );
		$dompdf->render();

		return (string) $dompdf->output();
	}

	/**
	 * $data's keys are extracted into local variables that
	 * templates/pdf/receipt.php reads directly — the parameter itself is
	 * never referenced by name, only unpacked, which is why phpcs sees it
	 * as unused.
	 *
	 * @param array<string,mixed> $data
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- see docblock above.
	private function receipt_html( array $data ): string {
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- the template file's variable contract is documented in its own header comment; this is the same include-with-scope pattern every Admin controller in this codebase uses (e.g. LeasesController::render_detail()), just via extract() instead of individually-named locals since the pdf template's data set is fully dynamic per receipt.

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/templates/pdf/receipt.php';

		return (string) ob_get_clean();
	}

	private function store_pdf( string $receipt_number, string $pdf_bytes ): string {
		$relative_dir = 'chrx-rental-manager/receipts';
		$upload_dir   = wp_upload_dir();
		$absolute_dir = trailingslashit( $upload_dir['basedir'] ) . $relative_dir;

		wp_mkdir_p( $absolute_dir );

		$relative_path = $relative_dir . '/' . sanitize_file_name( $receipt_number ) . '.pdf';
		$absolute_path = trailingslashit( $upload_dir['basedir'] ) . $relative_path;

		// Written directly rather than via WP_Filesystem(): this file is
		// entirely plugin-generated (not user-supplied content) and the
		// target directory is always inside wp-content/uploads, which the
		// web server process already owns — no FTP-credential prompt risk
		// in this admin_post/cron context.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $absolute_path, $pdf_bytes );

		return $relative_path;
	}
}
