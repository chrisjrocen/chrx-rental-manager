<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt PDF download from the tenant portal (SPEC.md §4.5,
 * designs/32-receipt-detail.html's "Download PDF" button).
 * admin-post.php is reachable from the front end for logged-in users
 * (standard WP behavior, same mechanism the Admin\PaymentsController
 * download endpoint uses), so this doesn't need its own rewrite rule.
 *
 * Authorization here is deliberately independent of
 * Admin\PaymentsController::handle_download_receipt() — that endpoint
 * checks Access::userCanAccessProperty() (staff/landlord property
 * scoping) and would reject every tenant outright. This endpoint instead
 * checks PortalContext::lease_belongs_to_tenant(), the tenant-ownership
 * equivalent.
 */
final class PortalReceiptDownload {

	private const ACTION = 'rm_portal_download_receipt';

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
			wp_die( esc_html__( 'You must be logged in to your tenant portal to download this receipt.', 'chrx-rental-manager' ), 403 );
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

		$absolute_path = $this->receipt_pdf->absolute_path( $receipt );

		if ( ! file_exists( $absolute_path ) ) {
			wp_die( esc_html__( 'The receipt PDF could not be found.', 'chrx-rental-manager' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $receipt['receipt_number'] ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $absolute_path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a plugin-generated PDF from wp-content/uploads, not fetching a remote URL.
		readfile( $absolute_path );
		exit;
	}

	public static function download_url( int $receipt_id ): string {
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
