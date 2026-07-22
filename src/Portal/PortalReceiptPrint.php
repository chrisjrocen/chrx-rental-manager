<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt print-CSS view from the tenant portal (SPEC.md §4.11) — the
 * portal-side twin of Admin\PaymentsController::handle_print_receipt(),
 * following PortalReceiptDownload's exact shape (same admin-post-reachable-
 * from-the-front-end mechanism, same PortalContext::lease_belongs_to_tenant()
 * ownership check independent of Access::userCanAccessProperty(), which
 * would reject every tenant outright).
 */
final class PortalReceiptPrint {

	private const ACTION = 'rm_portal_print_receipt';

	private PortalContext $context;
	private Receipt $receipts;
	private Payment $payments;
	private ReceiptPdf $receipt_pdf;

	public function __construct(
		?PortalContext $context = null,
		?Receipt $receipts = null,
		?Payment $payments = null,
		?ReceiptPdf $receipt_pdf = null
	) {
		$this->context     = $context ?? new PortalContext();
		$this->receipts    = $receipts ?? new Receipt();
		$this->payments    = $payments ?? new Payment();
		$this->receipt_pdf = $receipt_pdf ?? new ReceiptPdf();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! is_user_logged_in() || ! current_user_can( RoleManager::CAP_VIEW_PORTAL ) ) {
			wp_die( esc_html__( 'You must be logged in to your tenant portal to print this receipt.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer() once the receipt id is known.
		$receipt_id = isset( $_GET['receipt_id'] ) ? absint( $_GET['receipt_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $receipt_id );

		$tenant = $this->context->tenant_for_wp_user( get_current_user_id() );

		if ( null === $tenant ) {
			wp_die( esc_html__( 'No tenant account is linked to your login.', 'chrx-rental-manager' ), 403 );
		}

		$receipt = $this->receipts->find( $receipt_id );

		if ( null === $receipt ) {
			wp_die( esc_html__( 'Receipt not found.', 'chrx-rental-manager' ), 404 );
		}

		$payment = $this->payments->find( (int) $receipt['payment_id'] );

		if ( null === $payment || ! $this->context->lease_belongs_to_tenant( (int) $payment['lease_id'], (int) $tenant['id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this receipt.', 'chrx-rental-manager' ), 403 );
		}

		$data = $this->receipt_pdf->data_for_receipt( $receipt );

		if ( null === $data ) {
			wp_die( esc_html__( 'Payment not found.', 'chrx-rental-manager' ), 404 );
		}

		$paper_format = Settings::receipt_print_format();
		$back_url     = null;

		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- same include-with-scope pattern as Admin\PaymentsController::handle_print_receipt().

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/print/receipt.php';
		exit;
	}

	public static function print_url( int $receipt_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::ACTION,
					'receipt_id' => $receipt_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $receipt_id
		);
	}
}
