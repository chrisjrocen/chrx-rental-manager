<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Billing;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Portal\PortalReceiptDownload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails the generated PDF receipt to the tenant on file (SPEC.md §4.3/§5:
 * "Receipt generated → Tenant → Email (PDF attached) + WhatsApp (receipt
 * link)"), logging every attempt to rm_notifications_log — including the
 * "tenant has no email" case, which is a skip, not a failure, so support
 * can see why a particular tenant never got a receipt without it looking
 * like a bug. v2: dispatches through Notifier (SPEC.md §10 item 12) so a
 * tenant with a WhatsApp number on file also gets the receipt link there;
 * WhatsApp is additive and never affects this method's return value.
 */
final class ReceiptMailer {

	private Payment $payments;
	private Lease $leases;
	private Tenant $tenants;
	private ReceiptPdf $receipt_pdf;
	private Receipt $receipts;
	private NotificationLog $notifications;
	private Notifier $notifier;

	public function __construct(
		?Payment $payments = null,
		?Lease $leases = null,
		?Tenant $tenants = null,
		?ReceiptPdf $receipt_pdf = null,
		?Receipt $receipts = null,
		?NotificationLog $notifications = null,
		?Notifier $notifier = null
	) {
		$this->payments      = $payments ?? new Payment();
		$this->leases        = $leases ?? new Lease();
		$this->tenants       = $tenants ?? new Tenant();
		$this->receipt_pdf   = $receipt_pdf ?? new ReceiptPdf();
		$this->receipts      = $receipts ?? new Receipt();
		$this->notifications = $notifications ?? new NotificationLog();
		$this->notifier      = $notifier ?? new Notifier( $this->notifications );
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

		$sent = $this->notifier->notify(
			'receipt_emailed',
			(int) $receipt['id'],
			array(
				'email'           => $tenant['email'],
				'whatsapp_number' => $tenant['whatsapp_number'] ?? '',
			),
			$subject,
			$message,
			Settings::TEMPLATE_KEY_PAYMENT_RECEIVED,
			array(
				explode( ' ', trim( (string) $tenant['full_name'] ) )[0] ?? $tenant['full_name'],
				$receipt['receipt_number'],
				PortalReceiptDownload::download_url( (int) $receipt['id'] ),
			),
			file_exists( $absolute_path ) ? array( $absolute_path ) : array()
		);

		if ( $sent ) {
			$this->receipts->mark_emailed( (int) $receipt['id'] );
		}

		return $sent;
	}
}
