<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Billing\ReceiptMailer;
use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payments list (designs/20) and payment-confirmation/receipt screen
 * (designs/19): filter by property/method/month, CSV export, and the
 * per-payment receipt view (preview + email + download).
 */
final class PaymentsController {

	private const PAGE_SLUG       = 'chrx-rm-payments';
	private const EXPORT_ACTION   = 'rm_payments_export_csv';
	private const EMAIL_ACTION    = 'rm_email_receipt';
	private const DOWNLOAD_ACTION = 'rm_download_receipt';

	private Payment $payments;
	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Property $properties;
	private Receipt $receipts;
	private Access $access;
	private ReceiptPdf $receipt_pdf;
	private ReceiptMailer $receipt_mailer;

	public function __construct(
		?Payment $payments = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?Property $properties = null,
		?Receipt $receipts = null,
		?Access $access = null,
		?ReceiptPdf $receipt_pdf = null,
		?ReceiptMailer $receipt_mailer = null
	) {
		$this->payments       = $payments ?? new Payment();
		$this->leases         = $leases ?? new Lease();
		$this->units          = $units ?? new Unit();
		$this->tenants        = $tenants ?? new Tenant();
		$this->properties     = $properties ?? new Property();
		$this->receipts       = $receipts ?? new Receipt();
		$this->access         = $access ?? new Access();
		$this->receipt_pdf    = $receipt_pdf ?? new ReceiptPdf();
		$this->receipt_mailer = $receipt_mailer ?? new ReceiptMailer();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_' . self::EMAIL_ACTION, array( $this, 'handle_email_receipt' ) );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download_receipt' ) );
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) && ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view payments.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'payments' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'receipt' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
			$this->render_receipt( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table               = new PaymentsListTable( $restrict_to_property_ids );
		$list_table->prepare_items();

		$properties = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_filter(
				$this->properties->all_active(),
				fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
			);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change; re-read here only to echo back into the form/export-URL, not to act on.
		$selected_property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
		$selected_method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
		$selected_month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::EXPORT_ACTION,
					'property_id' => $selected_property_id,
					'method'      => $selected_method,
					'month'       => $selected_month,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/payments-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_receipt( int $receipt_id, ?string $notice ): void {
		$receipt = $this->receipts->find( $receipt_id );

		if ( null === $receipt ) {
			wp_die( esc_html__( 'Receipt not found.', 'chrx-rental-manager' ), 404 );
		}

		$payment = $this->payments->find( (int) $receipt['payment_id'] );

		if ( null === $payment ) {
			wp_die( esc_html__( 'Payment not found.', 'chrx-rental-manager' ), 404 );
		}

		$lease  = $this->leases->find( (int) $payment['lease_id'] );
		$unit   = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;
		$tenant = null !== $lease ? $this->tenants->find( (int) $lease['tenant_id'] ) : null;

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this receipt.', 'chrx-rental-manager' ), 403 );
		}

		$property = $this->properties->find( (int) $unit['property_id'] );

		$download_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DOWNLOAD_ACTION,
					'id'     => $receipt_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DOWNLOAD_ACTION . '_' . $receipt_id
		);

		$email_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::EMAIL_ACTION,
					'id'     => $receipt_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::EMAIL_ACTION . '_' . $receipt_id
		);

		$list_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/receipt-view.php';
	}

	public function handle_export_csv(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) && ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to export payments.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );

		$rows = PaymentsListTable::apply_filters(
			$this->payments->all_ordered(),
			$this->leases,
			$this->units,
			$restrict_to_property_ids,
			$property_id,
			$method,
			$month
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="payments-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Date', 'Tenant', 'Unit', 'Property', 'Method', 'Amount', 'Reference note', 'Receipt number' ) );

		foreach ( $rows as $row ) {
			$lease    = $this->leases->find( (int) $row['lease_id'] );
			$unit     = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;
			$tenant   = null !== $lease ? $this->tenants->find( (int) $lease['tenant_id'] ) : null;
			$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;
			$receipt  = null !== $row['receipt_id'] ? $this->receipts->find( (int) $row['receipt_id'] ) : null;

			fputcsv(
				$out,
				array(
					gmdate( 'Y-m-d', strtotime( $row['paid_at'] ) ),
					null === $tenant ? '' : $tenant['full_name'],
					null === $unit ? '' : $unit['unit_label'],
					null === $property ? '' : $property['name'],
					PaymentsListTable::method_label( $row['method'] ),
					number_format( (float) $row['amount'], 2, '.', '' ),
					$row['reference_note'],
					null === $receipt ? '' : $receipt['receipt_number'],
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a generated CSV directly to the HTTP response body (php://output), not a real filesystem write WP_Filesystem would apply to.
		fclose( $out );
		exit;
	}

	public function handle_email_receipt(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer() once $receipt_id is known.
		$receipt_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( self::EMAIL_ACTION . '_' . $receipt_id );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to email receipts.', 'chrx-rental-manager' ), 403 );
		}

		$receipt = $this->authorize_receipt( $receipt_id );
		$sent    = $this->receipt_mailer->send( $receipt );

		FlashNotice::set(
			'payments',
			$sent
				? __( 'Receipt emailed to the tenant.', 'chrx-rental-manager' )
				: __( 'Could not email the receipt — the tenant may have no email on file.', 'chrx-rental-manager' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'receipt',
					'id'     => $receipt_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_download_receipt(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer() once $receipt_id is known.
		$receipt_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( self::DOWNLOAD_ACTION . '_' . $receipt_id );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) && ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to download this receipt.', 'chrx-rental-manager' ), 403 );
		}

		$receipt       = $this->authorize_receipt( $receipt_id );
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

	/**
	 * @return array<string,mixed>
	 */
	private function authorize_receipt( int $receipt_id ): array {
		$receipt = $this->receipts->find( $receipt_id );

		if ( null === $receipt ) {
			wp_die( esc_html__( 'Receipt not found.', 'chrx-rental-manager' ), 404 );
		}

		$payment = $this->payments->find( (int) $receipt['payment_id'] );
		$lease   = null !== $payment ? $this->leases->find( (int) $payment['lease_id'] ) : null;
		$unit    = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to access this receipt.', 'chrx-rental-manager' ), 403 );
		}

		return $receipt;
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
