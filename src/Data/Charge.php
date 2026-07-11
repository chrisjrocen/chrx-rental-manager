<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Charges — the append-only ledger line generated each billing cycle.
 * Never hard-deleted (SPEC.md §3): a waived late fee becomes
 * status = 'waived', it is never removed.
 */
final class Charge extends AbstractRepository {

	protected const TABLE = 'rm_charges';

	public const TYPE_RENT     = 'rent';
	public const TYPE_LATE_FEE = 'late_fee';
	public const TYPE_DEPOSIT  = 'deposit';

	public const STATUS_UNPAID  = 'unpaid';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_PAID    = 'paid';
	public const STATUS_WAIVED  = 'waived';

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_lease( int $lease_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE lease_id = %d ORDER BY period_due_date ASC",
			array( $lease_id )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function unpaid_or_partial_for_lease( int $lease_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE lease_id = %d AND status IN ('unpaid','partial') ORDER BY period_due_date ASC",
			array( $lease_id )
		);
	}

	/**
	 * Overdue charges past their grace period — used by the late-fee cron
	 * (Billing phase).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function overdue_past_grace_period( int $grace_days ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE status IN ('unpaid','partial') AND DATE_ADD(period_due_date, INTERVAL %d DAY) < %s",
			array( $grace_days, current_time( 'mysql' ) )
		);
	}

	/**
	 * Never removed — marked waived instead (SPEC.md §3/§4.3).
	 */
	public function mark_waived( int $charge_id ): bool {
		return $this->update( $charge_id, array( 'status' => self::STATUS_WAIVED ) );
	}

	public function has_late_fee_for_period( int $lease_id, string $period_start ): bool {
		$table = $this->table_name();

		$count = $this->wpdb()->get_var(
			$this->wpdb()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT COUNT(*) FROM {$table} WHERE lease_id = %d AND type = %s AND period_start = %s",
				$lease_id,
				self::TYPE_LATE_FEE,
				$period_start
			)
		);

		return (int) $count > 0;
	}
}
