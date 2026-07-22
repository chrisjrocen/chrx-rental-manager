<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Payments\GatewayPaymentService;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff-sent Nylon Pay payment request (SPEC.md §2/§4.9: "Property
 * Manager/Staff... can send Nylon Pay payment requests"). Lives on the
 * same Record Payment screen as the manual form (record-payment-form.php)
 * since staff is already picking a charge there — a separate small
 * button/panel triggers this flow instead of the manual
 * RecordPaymentController submit, gated by the same
 * Access::userCanAccessProperty() property scoping every payment-related
 * action already uses.
 */
final class SendPaymentRequestController {

	private const NONCE_ACTION = 'rm_send_payment_request';

	private Lease $leases;
	private Unit $units;
	private Charge $charges;
	private Ledger $ledger;
	private Access $access;
	private GatewayPaymentService $gateway;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?Charge $charges = null,
		?Ledger $ledger = null,
		?Access $access = null,
		?GatewayPaymentService $gateway = null
	) {
		$this->units   = $units ?? new Unit();
		$this->leases  = $leases ?? new Lease( $this->units );
		$this->charges = $charges ?? new Charge();
		$this->ledger  = $ledger ?? new Ledger( $this->charges );
		$this->access  = $access ?? new Access();
		$this->gateway = $gateway ?? new GatewayPaymentService();
	}

	public function register(): void {
		add_action( 'admin_post_rm_send_payment_request', array( $this, 'handle_submit' ) );
	}

	public function handle_submit(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PAYMENTS ) ) {
			wp_die( esc_html__( 'You do not have permission to send payment requests.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$charge_id = isset( $_POST['rm_charge_id'] ) ? absint( $_POST['rm_charge_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$phone = isset( $_POST['rm_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_phone'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$amount = isset( $_POST['rm_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_amount'] ) ) ) : 0.0;

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
			wp_die( esc_html__( 'You do not have permission to send a payment request on this lease.', 'chrx-rental-manager' ), 403 );
		}

		if ( 0 === $charge_id ) {
			FlashNotice::set( 'leases', __( 'Choose a specific charge to send a Nylon Pay request for.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		$charge = $this->charges->find( $charge_id );

		if ( null === $charge || (int) $charge['lease_id'] !== $lease_id ) {
			wp_die( esc_html__( 'That charge does not belong to this lease.', 'chrx-rental-manager' ), 400 );
		}

		$outstanding = $this->ledger->outstanding_for_charge( $charge );

		if ( '' === $phone || $amount <= 0 || $amount > $outstanding ) {
			FlashNotice::set( 'leases', __( 'Please provide a valid phone number and an amount that does not exceed the outstanding balance.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		$result = $this->gateway->initiate_collection( $lease_id, $charge_id, $amount, $phone, GatewayTransaction::INITIATED_BY_STAFF, get_current_user_id() );

		FlashNotice::set(
			'leases',
			$result['success']
				? __( 'Nylon Pay request sent — the tenant will get a prompt on their phone.', 'chrx-rental-manager' )
				: sprintf( /* translators: %s: failure reason */ __( 'Could not send the Nylon Pay request: %s', 'chrx-rental-manager' ), (string) $result['failure_reason'] )
		);

		wp_safe_redirect( $back_url );
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
