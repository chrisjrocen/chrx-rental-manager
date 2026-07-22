<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Payments;

use ChrxRentalManager\Admin\StaffRolesController;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Billing\PaymentAllocator;
use ChrxRentalManager\Billing\ReceiptMailer;
use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single place every Nylon Pay flow (portal Pay Now, staff-sent
 * request, webhook, reconciliation cron) goes through to initiate a
 * collection or settle its outcome (SPEC.md §4.9) — the gateway-payments
 * equivalent of Communications\Notifier's "one dispatch point" role.
 * Every settle path is idempotent by design: a transaction already in a
 * final state (successful/failed/cancelled/expired) is a silent no-op,
 * since webhook delivery is at-least-once and the reconciliation cron may
 * race a late-arriving webhook for the same reference.
 */
final class GatewayPaymentService {

	private const RESOLVED_STATUSES = array(
		GatewayTransaction::STATUS_SUCCESSFUL,
		GatewayTransaction::STATUS_FAILED,
		GatewayTransaction::STATUS_CANCELLED,
		GatewayTransaction::STATUS_EXPIRED,
	);

	private const EXPIRE_AFTER_MINUTES = 1440; // 24h, per SPEC.md §4.9.

	private GatewayTransaction $transactions;
	private PaymentAllocator $allocator;
	private ReceiptPdf $receipt_pdf;
	private ReceiptMailer $receipt_mailer;
	private Notifier $notifier;
	private PropertyStaff $property_staff;
	private Property $properties;
	private Unit $units;
	private Lease $leases;
	private Tenant $tenants;
	private NylonPayClient $client;

	public function __construct(
		?GatewayTransaction $transactions = null,
		?PaymentAllocator $allocator = null,
		?ReceiptPdf $receipt_pdf = null,
		?ReceiptMailer $receipt_mailer = null,
		?Notifier $notifier = null,
		?PropertyStaff $property_staff = null,
		?Property $properties = null,
		?Unit $units = null,
		?Lease $leases = null,
		?Tenant $tenants = null,
		?NylonPayClient $client = null
	) {
		$this->units          = $units ?? new Unit();
		$this->leases         = $leases ?? new Lease( $this->units );
		$this->tenants        = $tenants ?? new Tenant();
		$this->transactions   = $transactions ?? new GatewayTransaction();
		$this->allocator      = $allocator ?? new PaymentAllocator();
		$this->receipt_pdf    = $receipt_pdf ?? new ReceiptPdf();
		$this->receipt_mailer = $receipt_mailer ?? new ReceiptMailer();
		$this->notifier       = $notifier ?? new Notifier();
		$this->property_staff = $property_staff ?? new PropertyStaff();
		$this->properties     = $properties ?? new Property();
		$this->client         = $client ?? new Drivers\NylonPayHttpDriver();
	}

	/**
	 * Write-first collection request (SPEC.md §4.9): the rm_gateway_transactions
	 * row is inserted with status = initiated *before* the API call, so a
	 * crashed request never produces an untracked payment — only ever a
	 * row stuck at "initiated" that the reconciliation cron/staff "needs
	 * attention" list will eventually catch.
	 *
	 * @return array{success:bool,transaction_id:?int,reference:?string,failure_reason:?string}
	 */
	public function initiate_collection(
		int $lease_id,
		?int $charge_id,
		float $amount,
		string $phone,
		string $initiated_by,
		int $initiator_user_id
	): array {
		if ( ! Settings::nylonpay_is_available() ) {
			return array(
				'success'        => false,
				'transaction_id' => null,
				'reference'      => null,
				'failure_reason' => __( 'Nylon Pay is not enabled for this site.', 'chrx-rental-manager' ),
			);
		}

		if ( $amount < Settings::NYLONPAY_MINIMUM_AMOUNT ) {
			return array(
				'success'        => false,
				'transaction_id' => null,
				'reference'      => null,
				/* translators: %s: minimum amount */
				'failure_reason' => sprintf( __( 'Amount is below Nylon Pay\'s minimum of %s.', 'chrx-rental-manager' ), (string) Settings::NYLONPAY_MINIMUM_AMOUNT ),
			);
		}

		$lease = $this->leases->find( $lease_id );

		if ( null === $lease ) {
			return array(
				'success'        => false,
				'transaction_id' => null,
				'reference'      => null,
				'failure_reason' => __( 'Lease not found.', 'chrx-rental-manager' ),
			);
		}

		$tenant_id = (int) $lease['tenant_id'];
		$tenant    = $this->tenants->find( $tenant_id );
		$reference = wp_generate_uuid4();

		$transaction_id = $this->transactions->insert(
			array(
				'gateway'             => GatewayTransaction::GATEWAY_NYLONPAY,
				'reference'           => $reference,
				'lease_id'            => $lease_id,
				'charge_id'           => $charge_id,
				'tenant_id'           => $tenant_id,
				'amount'              => $amount,
				'currency'            => Settings::currency_code(),
				'status'              => GatewayTransaction::STATUS_INITIATED,
				'initiated_by'        => $initiated_by,
				'initiator_user_id'   => $initiator_user_id,
				'phone_used'          => $phone,
				'raw_webhook_payload' => null,
			)
		);

		if ( false === $transaction_id ) {
			return array(
				'success'        => false,
				'transaction_id' => null,
				'reference'      => null,
				'failure_reason' => __( 'Could not create the payment request record.', 'chrx-rental-manager' ),
			);
		}

		$result = $this->client->collect(
			$reference,
			$amount,
			Settings::currency_code(),
			$phone,
			null === $tenant ? '' : (string) $tenant['full_name'],
			array(
				'lease_id'  => $lease_id,
				'charge_id' => $charge_id,
				'site_url'  => home_url(),
			)
		);

		if ( $result['success'] ) {
			$this->transactions->update( $transaction_id, array( 'status' => GatewayTransaction::STATUS_PROCESSING ) );
		} else {
			$this->transactions->mark_failed( $transaction_id );
		}

		return array(
			'success'        => $result['success'],
			'transaction_id' => $transaction_id,
			'reference'      => $reference,
			'failure_reason' => $result['failure_reason'],
		);
	}

	/**
	 * Settles a transaction as successful — records the payment through
	 * the same PaymentAllocator every manual payment uses (identical
	 * partial/overpayment handling, per SPEC.md §4.9), generates and
	 * emails the receipt, and notifies assigned staff. Idempotent: a
	 * transaction already in a final state is left untouched.
	 */
	public function settle_successful( int $transaction_id, ?string $raw_payload = null ): bool {
		$transaction = $this->transactions->find( $transaction_id );

		if ( null === $transaction ) {
			return false;
		}

		if ( in_array( $transaction['status'], self::RESOLVED_STATUSES, true ) ) {
			return true; // Already resolved by an earlier webhook delivery or the reconciliation cron — at-least-once delivery, dedupe silently.
		}

		$allocation = $this->allocator->record_payment(
			(int) $transaction['lease_id'],
			null === $transaction['charge_id'] ? null : (int) $transaction['charge_id'],
			(float) $transaction['amount'],
			Payment::METHOD_NYLONPAY,
			sprintf( 'Nylon Pay #%s', $transaction['reference'] ),
			null, // SPEC.md §3.1: recorded_by is null for webhook-recorded payments.
			current_time( 'mysql' ),
			$transaction_id
		);

		$update = array( 'status' => GatewayTransaction::STATUS_SUCCESSFUL );

		if ( null !== $raw_payload ) {
			$update['raw_webhook_payload'] = $raw_payload;
		}

		$this->transactions->update( $transaction_id, $update );

		$this->notify_staff_of_payment( $transaction, (int) $allocation['primary_payment_id'] );

		$receipt = $this->receipt_pdf->generate_for_payment( (int) $allocation['primary_payment_id'], (float) $allocation['credit_applied'] );

		if ( null !== $receipt ) {
			$this->receipt_mailer->send( $receipt );
		}

		return true;
	}

	/**
	 * Settles a transaction as failed (Nylon Pay reported failure/
	 * cancellation) and notifies whoever initiated it — tenant or staff
	 * (SPEC.md §5: "Nylon Pay payment failed → Initiator → Email +
	 * WhatsApp + portal state"). Idempotent, same as settle_successful().
	 */
	public function settle_failed( int $transaction_id, ?string $reason = null, ?string $raw_payload = null ): bool {
		$transaction = $this->transactions->find( $transaction_id );

		if ( null === $transaction ) {
			return false;
		}

		if ( in_array( $transaction['status'], self::RESOLVED_STATUSES, true ) ) {
			return true;
		}

		$this->transactions->mark_failed( $transaction_id, $raw_payload );
		$this->notify_initiator_of_failure( $transaction, $reason ?? __( 'The payment was not completed.', 'chrx-rental-manager' ) );

		return true;
	}

	/**
	 * A transaction that's been unresolved past the 24h ceiling (SPEC.md
	 * §4.9) — marked expired specifically (not failed), so it reads
	 * distinctly on the staff "needs attention" list.
	 */
	public function expire( int $transaction_id ): bool {
		$transaction = $this->transactions->find( $transaction_id );

		if ( null === $transaction ) {
			return false;
		}

		if ( in_array( $transaction['status'], self::RESOLVED_STATUSES, true ) ) {
			return true;
		}

		$this->transactions->mark_expired( $transaction_id );
		$this->notify_initiator_of_failure( $transaction, __( 'The payment request timed out.', 'chrx-rental-manager' ) );

		return true;
	}

	/**
	 * The webhook's deferred-processing entry point (SPEC.md §4.9:
	 * "returns 200 within the 5-second window by deferring heavy work...
	 * to an immediately-scheduled async event") — the REST callback
	 * verifies the signature and dedupe-checks synchronously, then
	 * schedules this via wp_schedule_single_event() so PDF generation/
	 * email sending never holds up the HTTP response Nylon Pay is waiting
	 * on.
	 */
	public function process_webhook_event( int $transaction_id, string $event, string $raw_payload ): void {
		if ( str_contains( $event, 'failed' ) || str_contains( $event, 'cancelled' ) ) {
			$this->settle_failed( $transaction_id, null, $raw_payload );

			return;
		}

		$this->settle_successful( $transaction_id, $raw_payload );
	}

	/**
	 * Hourly sweep (SPEC.md §4.9/§6): initiated/processing transactions
	 * older than 15 minutes are either settled (poll Nylon Pay's status
	 * endpoint) or, past 24h, expired outright without another API call.
	 *
	 * @return array{settled:int,expired:int}
	 */
	public function reconcile_unresolved(): array {
		$settled = 0;
		$expired = 0;

		foreach ( $this->transactions->unresolved_older_than( 15 ) as $transaction ) {
			$age_minutes = ( time() - strtotime( $transaction['created_at'] ) ) / 60;

			if ( $age_minutes >= self::EXPIRE_AFTER_MINUTES ) {
				$this->expire( (int) $transaction['id'] );
				++$expired;
				continue;
			}

			$status = $this->client->check_status( $transaction['reference'] );

			if ( GatewayTransaction::STATUS_SUCCESSFUL === $status['status'] ) {
				$this->settle_successful( (int) $transaction['id'] );
				++$settled;
			} elseif ( in_array( $status['status'], array( GatewayTransaction::STATUS_FAILED, GatewayTransaction::STATUS_CANCELLED ), true ) ) {
				$this->settle_failed( (int) $transaction['id'], $status['failure_reason'] );
				++$settled;
			}
			// Still processing per Nylon Pay — left alone for the next hourly sweep.
		}

		return array(
			'settled' => $settled,
			'expired' => $expired,
		);
	}

	/**
	 * SPEC.md §5: "Payment recorded (manual or Nylon Pay) → Assigned
	 * staff → Email + WhatsApp" — reuses the exact 'payment_recorded'
	 * notify type and PAYMENT_RECEIVED template RecordPaymentController
	 * already dispatches for manual payments, so staff see one consistent
	 * notification regardless of how the payment came in.
	 *
	 * @param array<string,mixed> $transaction
	 */
	private function notify_staff_of_payment( array $transaction, int $payment_id ): void {
		$lease = $this->leases->find( (int) $transaction['lease_id'] );

		if ( null === $lease ) {
			return;
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit ) {
			return;
		}

		$tenant      = $this->tenants->find( (int) $lease['tenant_id'] );
		$tenant_name = null === $tenant ? '' : (string) $tenant['full_name'];
		$property    = $this->properties->find( (int) $unit['property_id'] );
		$amount      = (string) $transaction['amount'];

		$subject = sprintf(
			/* translators: 1: tenant name, 2: unit label */
			__( 'Nylon Pay payment received — %1$s, %2$s', 'chrx-rental-manager' ),
			$tenant_name,
			$unit['unit_label']
		);

		$message = sprintf(
			/* translators: 1: tenant name, 2: unit label, 3: amount */
			__( 'A Nylon Pay payment of %3$s was received for %1$s, %2$s.', 'chrx-rental-manager' ),
			$tenant_name,
			$unit['unit_label'],
			$amount
		);

		foreach ( $this->property_staff->user_ids_for_property( (int) $unit['property_id'] ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user || '' === $user->user_email ) {
				continue;
			}

			$this->notifier->notify(
				'payment_recorded',
				$payment_id,
				array(
					'email'           => $user->user_email,
					'whatsapp_number' => get_user_meta( $user_id, StaffRolesController::WHATSAPP_META_KEY, true ),
				),
				$subject,
				$message,
				Settings::TEMPLATE_KEY_PAYMENT_RECEIVED,
				array( $tenant_name, $unit['unit_label'], null === $property ? '' : (string) $property['name'], $amount )
			);
		}
	}

	/**
	 * SPEC.md §5: "Nylon Pay payment failed → Initiator (tenant or staff)
	 * → Email + WhatsApp + portal state" — the portal-state half is
	 * handled separately by the status-polling endpoint; this covers the
	 * email/WhatsApp half only.
	 *
	 * @param array<string,mixed> $transaction
	 */
	private function notify_initiator_of_failure( array $transaction, string $reason ): void {
		$recipient = GatewayTransaction::INITIATED_BY_STAFF === $transaction['initiated_by']
			? $this->staff_recipient( (int) $transaction['initiator_user_id'] )
			: $this->tenant_recipient( (int) $transaction['tenant_id'] );

		if ( null === $recipient ) {
			return;
		}

		$subject = __( 'Nylon Pay payment failed', 'chrx-rental-manager' );
		$message = sprintf(
			/* translators: %s: failure reason */
			__( 'A Nylon Pay payment request could not be completed: %s', 'chrx-rental-manager' ),
			$reason
		);

		$this->notifier->notify(
			'gateway_payment_failed',
			(int) $transaction['id'],
			$recipient,
			$subject,
			$message,
			Settings::TEMPLATE_KEY_GATEWAY_PAYMENT_FAILED,
			array( $reason )
		);
	}

	/**
	 * @return array{email:string,whatsapp_number:string}|null
	 */
	private function staff_recipient( int $user_id ): ?array {
		$user = get_userdata( $user_id );

		if ( false === $user || '' === $user->user_email ) {
			return null;
		}

		return array(
			'email'           => $user->user_email,
			'whatsapp_number' => (string) get_user_meta( $user_id, StaffRolesController::WHATSAPP_META_KEY, true ),
		);
	}

	/**
	 * @return array{email:string,whatsapp_number:string}|null
	 */
	private function tenant_recipient( int $tenant_id ): ?array {
		$tenant = $this->tenants->find( $tenant_id );

		if ( null === $tenant || '' === (string) ( $tenant['email'] ?? '' ) ) {
			return null;
		}

		return array(
			'email'           => (string) $tenant['email'],
			'whatsapp_number' => (string) ( $tenant['whatsapp_number'] ?? '' ),
		);
	}
}
