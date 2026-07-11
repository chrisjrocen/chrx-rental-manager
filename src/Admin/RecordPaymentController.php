<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Billing\PaymentAllocator;
use ChrxRentalManager\Billing\ReceiptMailer;
use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record Payment (SPEC.md §4.3, designs/18-record-payment-modal.html —
 * built as its own admin screen rather than a literal JS modal, matching
 * the same deviation already established by LeaseRenewalController/
 * LeaseMoveOutController for their modal-styled designs).
 *
 * A payment may be recorded against a specific outstanding charge, or as
 * an unallocated advance ("no specific charge") when a tenant is paying
 * ahead. SPEC.md §4.3's closing-out edge case is explicitly allowed here:
 * this screen does not require the lease to be active, only that the
 * current user may access the lease's property — the caller (lease
 * detail template) visually flags a payment recorded against a
 * non-active lease as a settlement, not current activity.
 */
final class RecordPaymentController {

	private const NONCE_ACTION = 'rm_record_payment';

	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Charge $charges;
	private Payment $payments;
	private Ledger $ledger;
	private Access $access;
	private PaymentAllocator $allocator;
	private ReceiptPdf $receipt_pdf;
	private ReceiptMailer $receipt_mailer;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?Charge $charges = null,
		?Payment $payments = null,
		?Ledger $ledger = null,
		?Access $access = null,
		?PaymentAllocator $allocator = null,
		?ReceiptPdf $receipt_pdf = null,
		?ReceiptMailer $receipt_mailer = null
	) {
		$this->units          = $units ?? new Unit();
		$this->leases         = $leases ?? new Lease( $this->units );
		$this->tenants        = $tenants ?? new Tenant();
		$this->charges        = $charges ?? new Charge();
		$this->payments       = $payments ?? new Payment();
		$this->ledger         = $ledger ?? new Ledger( $this->charges, $this->payments, $this->leases );
		$this->access         = $access ?? new Access();
		$this->allocator      = $allocator ?? new PaymentAllocator( $this->payments, $this->charges, $this->ledger );
		$this->receipt_pdf    = $receipt_pdf ?? new ReceiptPdf( $this->payments, $this->charges, $this->leases, $this->units, $this->tenants, null, null, $this->ledger );
		$this->receipt_mailer = $receipt_mailer ?? new ReceiptMailer( $this->payments, $this->leases, $this->tenants, $this->receipt_pdf );
	}

	public function register(): void {
		add_action( 'admin_post_rm_record_payment', array( $this, 'handle_submit' ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	public function render_form( int $lease_id, ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to record payments.', 'chrx-rental-manager' ), 403 );
		}

		$lease = $this->leases->find( $lease_id );

		if ( null === $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to record a payment on this lease.', 'chrx-rental-manager' ), 403 );
		}

		$tenant = $this->tenants->find( (int) $lease['tenant_id'] );

		$open_charges = $this->charges->unpaid_or_partial_for_lease( $lease_id );
		$open_charges = array_map(
			function ( array $charge ): array {
				$charge['outstanding'] = $this->ledger->outstanding_for_charge( $charge );

				return $charge;
			},
			$open_charges
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change; preselects a charge, still re-validated in handle_submit().
		$preselected_charge_id = isset( $_GET['charge_id'] ) ? absint( $_GET['charge_id'] ) : 0;
		$is_closing_out        = Lease::STATUS_ACTIVE !== $lease['status'];

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/record-payment-form.php';
	}

	public function handle_submit(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to record payments.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$charge_id_raw = isset( $_POST['rm_charge_id'] ) ? absint( $_POST['rm_charge_id'] ) : 0;
		$charge_id     = $charge_id_raw > 0 ? $charge_id_raw : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$amount = isset( $_POST['rm_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$method = isset( $_POST['rm_method'] ) ? sanitize_key( wp_unslash( $_POST['rm_method'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$paid_date = isset( $_POST['rm_paid_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_paid_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$note = isset( $_POST['rm_note'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_note'] ) ) : '';

		$back_url = add_query_arg(
			array(
				'page'   => LeasesController::page_slug(),
				'action' => 'record-payment',
				'id'     => $lease_id,
			),
			admin_url( 'admin.php' )
		);

		$lease = $this->leases->find( $lease_id );

		if ( null === $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to record a payment on this lease.', 'chrx-rental-manager' ), 403 );
		}

		$valid_methods = array( Payment::METHOD_CASH, Payment::METHOD_BANK_TRANSFER, Payment::METHOD_MTN_MOMO, Payment::METHOD_AIRTEL_MONEY, Payment::METHOD_OTHER );

		if ( $amount <= 0 || ! in_array( $method, $valid_methods, true ) || false === strtotime( $paid_date ) ) {
			FlashNotice::set( 'leases', __( 'Please provide a valid amount, method, and date.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		if ( null !== $charge_id ) {
			$charge = $this->charges->find( $charge_id );

			if ( null === $charge || (int) $charge['lease_id'] !== $lease_id ) {
				wp_die( esc_html__( 'That charge does not belong to this lease.', 'chrx-rental-manager' ), 400 );
			}
		}

		$allocation = $this->allocator->record_payment(
			$lease_id,
			$charge_id,
			$amount,
			$method,
			$note,
			get_current_user_id(),
			gmdate( 'Y-m-d H:i:s', (int) strtotime( $paid_date ) )
		);

		$receipt = $this->receipt_pdf->generate_for_payment( $allocation['primary_payment_id'], $allocation['credit_applied'] );

		if ( null !== $receipt ) {
			$this->receipt_mailer->send( $receipt );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => PaymentsController::page_slug(),
					'action' => 'receipt',
					'id'     => null === $receipt ? 0 : $receipt['id'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
