<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leases.
 *
 * Enforces SPEC.md §3.3's capacity invariant at the data layer (not just
 * the UI): a unit's active-lease count can never exceed `rm_units.capacity`
 * (default 1, which reproduces v1's original "never two simultaneously
 * active leases" rule exactly). create() and change_status() both check
 * this via guard_against_capacity_exceeded() and throw
 * CapacityExceededException (a DuplicateActiveLeaseException, so v1 catch
 * sites keep working unchanged) rather than silently allowing the
 * conflicting row.
 *
 * The guard/count/insert sequence is wrapped in a MySQL named lock
 * (GET_LOCK/RELEASE_LOCK) keyed per unit, not a SQL transaction — this
 * plugin has no reliable way to detect whether a caller (or the
 * integration test harness) already has an open transaction, and issuing
 * a second `START TRANSACTION` while one is already open silently commits
 * the outer one, which would be a far worse bug than the race this guard
 * closes. A named lock is independent of transaction state and serializes
 * concurrent capacity checks for the same unit regardless of what
 * transaction (if any) the caller is in.
 */
final class Lease extends AbstractRepository {

	public const CYCLE_MONTHLY   = 'monthly';
	public const CYCLE_QUARTERLY = 'quarterly';
	public const CYCLE_SEMESTER  = 'semester';
	public const CYCLE_ANNUAL    = 'annual';
	public const CYCLE_CUSTOM    = 'custom';

	public const CYCLE_MONTHS_MIN = 1;
	public const CYCLE_MONTHS_MAX = 24;

	use SoftDeletes {
		soft_delete as private trait_soft_delete;
	}

	protected const TABLE = 'rm_leases';

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_ENDED   = 'ended';
	public const STATUS_RENEWED = 'renewed';

	private Unit $units;

	public function __construct( ?Unit $units = null ) {
		$this->units = $units ?? new Unit();
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @throws CapacityExceededException if $data['unit_id'] is already at
	 *                                    capacity and status is (or
	 *                                    defaults to) 'active'.
	 */
	public function create( array $data ): int {
		$status = $data['status'] ?? self::STATUS_ACTIVE;

		if ( self::STATUS_ACTIVE === $status ) {
			$this->guard_against_capacity_exceeded( (int) $data['unit_id'] );
		}

		$data['status'] = $status;
		$id             = $this->insert( $data );

		if ( false === $id ) {
			throw new \RuntimeException( 'Failed to insert lease.' );
		}

		if ( self::STATUS_ACTIVE === $status ) {
			$this->units->sync_derived_status( (int) $data['unit_id'], true );
		}

		return $id;
	}

	/**
	 * Guarded status transition — use this instead of update() directly
	 * whenever a lease's status is changing, so the invariant is checked
	 * and the unit's derived status stays in sync.
	 *
	 * @throws CapacityExceededException
	 */
	public function change_status( int $lease_id, string $new_status ): bool {
		$lease = $this->find( $lease_id );

		if ( null === $lease ) {
			return false;
		}

		if ( self::STATUS_ACTIVE === $new_status ) {
			$this->guard_against_capacity_exceeded( (int) $lease['unit_id'], $lease_id );
		}

		$result = $this->update( $lease_id, array( 'status' => $new_status ) );

		if ( $result ) {
			$still_active = $this->active_lease_for_unit( (int) $lease['unit_id'] );
			$this->units->sync_derived_status( (int) $lease['unit_id'], null !== $still_active );
		}

		return $result;
	}

	/**
	 * Soft-deleting a lease removes it from active_lease_for_unit()'s result
	 * set the same way change_status() to a non-active status does — so the
	 * unit's derived occupied/vacant status must be resynced here too, or a
	 * trashed lease leaves rm_units.status permanently stale at 'occupied',
	 * wrongly blocking the unit's own trash/delete action afterward.
	 */
	public function soft_delete( int $id ): bool {
		$lease = $this->find( $id );

		$result = $this->trait_soft_delete( $id );

		if ( $result && null !== $lease ) {
			$still_active = $this->active_lease_for_unit( (int) $lease['unit_id'] );
			$this->units->sync_derived_status( (int) $lease['unit_id'], null !== $still_active );
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function active_lease_for_unit( int $unit_id, ?int $exclude_lease_id = null ): ?array {
		$matches = $this->active_leases_for_unit( $unit_id, $exclude_lease_id );

		return $matches[0] ?? null;
	}

	/**
	 * Every currently active lease on a unit — the capacity guard's count
	 * source (v1's active_lease_for_unit() only needed existence, v2's
	 * capacity check needs the count and, when over capacity, the full list
	 * of conflicting lease ids for the error message).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active_leases_for_unit( int $unit_id, ?int $exclude_lease_id = null ): array {
		$table = $this->table_name();

		$sql    = "SELECT * FROM {$table} WHERE unit_id = %d AND status = %s AND deleted_at IS NULL";
		$params = array( $unit_id, self::STATUS_ACTIVE );

		if ( null !== $exclude_lease_id ) {
			$sql     .= ' AND id != %d';
			$params[] = $exclude_lease_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results( $sql, $params );
	}

	/**
	 * Count of currently active leases on a unit — used both by the
	 * capacity guard here and, in a later phase, by unit-capacity-edit
	 * validation (SPEC.md §4.1: "reducing capacity below the current
	 * active-lease count is blocked").
	 */
	public function count_active_for_unit( int $unit_id, ?int $exclude_lease_id = null ): int {
		return count( $this->active_leases_for_unit( $unit_id, $exclude_lease_id ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_tenant( int $tenant_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE tenant_id = %d AND deleted_at IS NULL ORDER BY start_date DESC",
			array( $tenant_id )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_unit( int $unit_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE unit_id = %d AND deleted_at IS NULL ORDER BY start_date DESC",
			array( $unit_id )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function all_with_status( string $status ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE status = %s AND deleted_at IS NULL ORDER BY end_date ASC",
			array( $status )
		);
	}

	/**
	 * Leases expiring within $days_ahead, still active — used by the
	 * renewal reminder cron (Billing phase) and the dashboard's "upcoming
	 * expirations" widget.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function expiring_within( int $days_ahead ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE status = %s AND deleted_at IS NULL AND end_date <= DATE_ADD(%s, INTERVAL %d DAY) ORDER BY end_date ASC",
			array( self::STATUS_ACTIVE, current_time( 'mysql' ), $days_ahead )
		);
	}

	/**
	 * Any row in rm_charges or rm_payments for this lease — used to block
	 * permanent delete, since removing the lease would orphan financial
	 * rows that must remain queryable for reporting/reconciliation.
	 */
	public function has_financial_history( int $lease_id ): bool {
		$wpdb = $this->wpdb();

		$charge_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_charges WHERE lease_id = %d",
				$lease_id
			)
		);

		if ( $charge_count > 0 ) {
			return true;
		}

		$payment_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_payments WHERE lease_id = %d",
				$lease_id
			)
		);

		return $payment_count > 0;
	}

	/**
	 * Permanently removes the lease row. Only safe to call after
	 * has_financial_history() returns false — callers (LeasesController)
	 * are responsible for enforcing that guard before invoking this.
	 */
	public function delete_permanently( int $lease_id ): bool {
		return $this->hard_delete( $lease_id );
	}

	/**
	 * Serializes the count-then-insert sequence per unit via a MySQL named
	 * lock (see class doc comment for why this is a named lock and not a
	 * SQL transaction), so two concurrent requests can't both observe
	 * "capacity not yet reached" and both insert the (capacity+1)th active
	 * lease.
	 *
	 * @throws CapacityExceededException
	 */
	private function guard_against_capacity_exceeded( int $unit_id, ?int $exclude_lease_id = null ): void {
		$wpdb      = $this->wpdb();
		$lock_name = "rm_lease_capacity_unit_{$unit_id}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below via placeholders.
		$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		if ( 1 !== $acquired ) {
			throw new \RuntimeException( 'Could not acquire the lease capacity lock for this unit; please try again.' );
		}

		try {
			$unit     = $this->units->find( $unit_id );
			$capacity = null === $unit ? 1 : max( 1, (int) $unit['capacity'] );
			$active   = $this->active_leases_for_unit( $unit_id, $exclude_lease_id );

			if ( count( $active ) >= $capacity ) {
				throw new CapacityExceededException(
					$unit_id,
					$capacity,
					array_map( static fn( array $lease ): int => (int) $lease['id'], $active )
				);
			}
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below via placeholders.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}
