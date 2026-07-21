<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Billing;

use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment recording + overpayment/credit handling (SPEC.md §4.3 edge
 * case): a payment that exceeds the target charge's outstanding balance
 * is split into a payment that fully settles the charge plus a second,
 * unallocated (charge_id = NULL) "credit" payment for the excess — never
 * left dangling as a random overage on a single charge row. That credit
 * sits unallocated until apply_credits_to_charge() sweeps it onto a
 * charge, which Cron\ChargeGenerator calls immediately after creating
 * each new period's charge ("auto-applied to the next generated charge",
 * per spec) — this class doesn't apply credit to *other already-existing*
 * charges on its own, since the spec's wording is specifically about the
 * next generated one, not a general reallocation sweep.
 */
final class PaymentAllocator {

	private Payment $payments;
	private Charge $charges;
	private Ledger $ledger;

	public function __construct( ?Payment $payments = null, ?Charge $charges = null, ?Ledger $ledger = null ) {
		$this->payments = $payments ?? new Payment();
		$this->charges  = $charges ?? new Charge();
		$this->ledger   = $ledger ?? new Ledger( $this->charges, $this->payments );
	}

	/**
	 * Records a payment. When $charge_id is null, the entire amount is
	 * recorded as an unallocated advance/credit. When $charge_id is set
	 * and $amount exceeds that charge's outstanding balance, the excess
	 * becomes a second, unallocated credit payment.
	 *
	 * @return array{primary_payment_id:int,credit_payment_id:?int,credit_applied:float}
	 *               primary_payment_id represents the full transaction for
	 *               receipt purposes; credit_applied is the portion (if
	 *               any) that went to the separate unallocated row.
	 */
	public function record_payment(
		int $lease_id,
		?int $charge_id,
		float $amount,
		string $method,
		string $reference_note,
		int $recorded_by,
		string $paid_at
	): array {
		$primary_amount = $amount;
		$excess         = 0.0;

		if ( null !== $charge_id ) {
			$charge = $this->charges->find( $charge_id );

			if ( null !== $charge ) {
				$outstanding    = $this->ledger->outstanding_for_charge( $charge );
				$primary_amount = min( $amount, $outstanding );
				$excess         = $amount - $primary_amount;
			}
		}

		// A charge that's already fully paid (outstanding <= 0) leaves
		// nothing for a "primary" row to settle — the whole amount is
		// unallocated credit instead of a zero-value payment row pinned
		// to a charge it didn't actually reduce.
		if ( null !== $charge_id && $primary_amount <= 0 ) {
			$charge_id      = null;
			$excess         = $primary_amount + $excess;
			$primary_amount = $excess;
			$excess         = 0.0;
		}

		$primary_payment_id = $this->insert_payment( $lease_id, $charge_id, $primary_amount, $method, $reference_note, $recorded_by, $paid_at );
		$credit_payment_id  = null;

		if ( $excess > 0 ) {
			$credit_payment_id = $this->insert_payment( $lease_id, null, $excess, $method, $reference_note, $recorded_by, $paid_at );
		}

		if ( null !== $charge_id ) {
			$this->sync_charge_status( $charge_id );
		}

		return array(
			'primary_payment_id' => $primary_payment_id,
			'credit_payment_id'  => $credit_payment_id,
			'credit_applied'     => $excess,
		);
	}

	/**
	 * Sweeps oldest-first unallocated (charge_id IS NULL) credit payments
	 * for this lease onto $charge_id until it's fully paid or credit runs
	 * out. A credit payment larger than the remaining need is split: the
	 * portion applied moves onto $charge_id, the remainder stays
	 * unallocated as its own row.
	 */
	public function apply_credits_to_charge( int $lease_id, int $charge_id ): void {
		$charge = $this->charges->find( $charge_id );

		if ( null === $charge ) {
			return;
		}

		$remaining_need = $this->ledger->outstanding_for_charge( $charge );

		if ( $remaining_need <= 0 ) {
			return;
		}

		foreach ( $this->payments->unallocated_for_lease( $lease_id ) as $credit ) {
			if ( $remaining_need <= 0 ) {
				break;
			}

			if ( Payment::STATUS_VOIDED === $credit['status'] ) {
				continue;
			}

			$credit_amount = (float) $credit['amount'];
			$applied       = min( $credit_amount, $remaining_need );

			if ( $applied >= $credit_amount ) {
				$this->payments->update( (int) $credit['id'], array( 'charge_id' => $charge_id ) );
			} else {
				$this->payments->update( (int) $credit['id'], array( 'amount' => $credit_amount - $applied ) );
				$this->insert_payment(
					$lease_id,
					$charge_id,
					$applied,
					$credit['method'],
					$credit['reference_note'],
					(int) $credit['recorded_by'],
					$credit['paid_at']
				);
			}

			$remaining_need -= $applied;
		}

		$this->sync_charge_status( $charge_id );
	}

	/**
	 * Public so callers correcting a payment after the fact (e.g.
	 * PaymentsController::handle_void_payment()) can recompute a charge's
	 * paid/partial/unpaid status from the ledger without duplicating this
	 * threshold logic.
	 */
	public function sync_charge_status( int $charge_id ): void {
		$charge = $this->charges->find( $charge_id );

		if ( null === $charge || Charge::STATUS_WAIVED === $charge['status'] ) {
			return;
		}

		$outstanding = $this->ledger->outstanding_for_charge( $charge );

		if ( $outstanding <= 0 ) {
			$new_status = Charge::STATUS_PAID;
		} elseif ( $outstanding < (float) $charge['amount_due'] ) {
			$new_status = Charge::STATUS_PARTIAL;
		} else {
			$new_status = Charge::STATUS_UNPAID;
		}

		if ( $new_status !== $charge['status'] ) {
			$this->charges->update( $charge_id, array( 'status' => $new_status ) );
		}
	}

	private function insert_payment(
		int $lease_id,
		?int $charge_id,
		float $amount,
		string $method,
		string $reference_note,
		int $recorded_by,
		string $paid_at
	): int {
		$id = $this->payments->insert(
			array(
				'lease_id'       => $lease_id,
				'charge_id'      => $charge_id,
				'amount'         => $amount,
				'method'         => $method,
				'reference_note' => $reference_note,
				'recorded_by'    => $recorded_by,
				'receipt_id'     => null,
				'paid_at'        => $paid_at,
			)
		);

		return false === $id ? 0 : $id;
	}
}
