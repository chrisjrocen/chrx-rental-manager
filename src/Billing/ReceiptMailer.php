<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Billing;

use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails the generated PDF receipt to the tenant on file (SPEC.md §4.3/§5:
 * "Receipt generated → Tenant → Email (PDF attached)"), logging every
 * attempt to rm_notifications_log — including the "tenant has no email"
 * case, which is a skip, not a failure, so support can see why a
 * particular tenant never got a receipt without it looking like a bug.
 */
final class ReceiptMailer {

	private Payment $payments;
	private Lease $leases;
	private Tenant $tenants;
	private ReceiptPdf $receipt_pdf;
	private Receipt $receipts;
	private NotificationLog $notifications;

	public function __construct(
		?Payment $payments = null,
		?Lease $leases = null,
		?Tenant $tenants = null,
		?ReceiptPdf $receipt_pdf = null,
		?Receipt $receipts = null,
		?NotificationLog $notifications = null
	) {
		$this->payments      = $payments ?? new Payment();
		$this->leases        = $leases ?? new Lease();
		$this->tenants       = $tenants ?? new Tenant();
		$this->receipt_pdf   = $receipt_pdf ?? new ReceiptPdf();
		$this->receipts      = $receipts ?? new Receipt();
		$this->notifications = $notifications ?? new NotificationLog();
	}

	/**
	 * @param array<string,mixed> $receipt
	 */
	public function send( array $receipt ): bool {
		$payment = $this->payments->find( (int) $receipt['payment_id'] );

		if ( null === $payment ) {
			return false;
		}

		$lease  = $this->leases->find( (int) $payment['lease_id'] );
		$tenant = null !== $lease ? $this->tenants->find( (int) $lease['tenant_id'] ) : null;

		if ( null === $tenant || '' === (string) $tenant['email'] || ! is_email( $tenant['email'] ) ) {
			$this->notifications->record(
				'receipt_emailed',
				null === $tenant ? '' : (string) $tenant['email'],
				(int) $receipt['id'],
				NotificationLog::STATUS_SKIPPED
			);

			return false;
		}

		$absolute_path = $this->receipt_pdf->absolute_path( $receipt );

		$subject = sprintf(
			/* translators: 1: receipt number, 2: site name */
			__( 'Payment receipt %1$s — %2$s', 'chrx-rental-manager' ),
			$receipt['receipt_number'],
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: tenant first name, 2: receipt number */
			__( "Hi %1\$s,\n\nThank you for your payment. Your receipt %2\$s is attached.", 'chrx-rental-manager' ),
			explode( ' ', trim( (string) $tenant['full_name'] ) )[0] ?? $tenant['full_name'],
			$receipt['receipt_number']
		);

		$sent = wp_mail( $tenant['email'], $subject, $message, array(), file_exists( $absolute_path ) ? array( $absolute_path ) : array() );

		if ( $sent ) {
			$this->receipts->mark_emailed( (int) $receipt['id'] );
		}

		$this->notifications->record(
			'receipt_emailed',
			$tenant['email'],
			(int) $receipt['id'],
			$sent ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED
		);

		return $sent;
	}
}
