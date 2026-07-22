<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payments — append-only (SPEC.md §3). No delete of any kind is exposed;
 * a mistaken payment is corrected via void() (status = 'voided'), never
 * row removal — same pattern as Charge::mark_waived().
 */
final class Payment extends AbstractRepository {

	protected const TABLE = 'rm_payments';

	public const METHOD_CASH          = 'cash';
	public const METHOD_BANK_TRANSFER = 'bank_transfer';
	public const METHOD_MTN_MOMO      = 'mtn_momo';
	public const METHOD_AIRTEL_MONEY  = 'airtel_money';
	// v2 (SPEC.md §4.3): set automatically by the Nylon Pay webhook/
	// reconciliation flow — never a selectable option in the manual
	// Record Payment form.
	public const METHOD_NYLONPAY = 'nylonpay';
	public const METHOD_OTHER    = 'other';

	public const STATUS_RECORDED = 'recorded';
	public const STATUS_VOIDED   = 'voided';

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_lease( int $lease_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE lease_id = %d ORDER BY paid_at DESC",
			array( $lease_id )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_charge( int $charge_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE charge_id = %d ORDER BY paid_at DESC",
			array( $charge_id )
		);
	}

	public function attach_receipt( int $payment_id, int $receipt_id ): bool {
		return $this->update( $payment_id, array( 'receipt_id' => $receipt_id ) );
	}

	/**
	 * Unallocated advance/overpayment credit for a lease (charge_id IS
	 * NULL) — SPEC.md §4.3's "excess becomes a credit auto-applied to the
	 * next generated charge". Oldest first, so a lease with several past
	 * credits applies them in the order they were received.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function unallocated_for_lease( int $lease_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE lease_id = %d AND charge_id IS NULL ORDER BY paid_at ASC",
			array( $lease_id )
		);
	}

	/**
	 * All payments, newest first — used by the Payments list screen
	 * (designs/20), which filters/joins in PHP the same way the
	 * Leases/Tenants list tables already do at this account's scale
	 * (SPEC.md's "few hundred units", not a high-volume ledger).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_ordered(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results( "SELECT * FROM {$table} ORDER BY paid_at DESC", array() );
	}

	/**
	 * Never removed — marked voided instead (SPEC.md §3/§4.3), same
	 * append-only pattern as Charge::mark_waived(). Excluded from balance
	 * calculations by Ledger and related summation sites, but stays visible
	 * in payment history/statements for audit purposes.
	 */
	public function void( int $payment_id, string $reason, int $voided_by ): bool {
		return $this->update(
			$payment_id,
			array(
				'status'        => self::STATUS_VOIDED,
				'voided_reason' => $reason,
				'voided_by'     => $voided_by,
				'voided_at'     => current_time( 'mysql' ),
			)
		);
	}
}
