<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leases.
 *
 * Enforces SPEC.md §4.1's core invariant here at the data layer (not just
 * the UI): a unit can never have two simultaneously active leases.
 * create() and activate() both check active_lease_for_unit() and throw
 * DuplicateActiveLeaseException rather than silently allowing the
 * conflicting row.
 */
final class Lease extends AbstractRepository {

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
	 * @throws DuplicateActiveLeaseException if $data['unit_id'] already has
	 *                                        an active lease and status is
	 *                                        (or defaults to) 'active'.
	 */
	public function create( array $data ): int {
		$status = $data['status'] ?? self::STATUS_ACTIVE;

		if ( self::STATUS_ACTIVE === $status ) {
			$this->guard_against_duplicate_active_lease( (int) $data['unit_id'] );
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
	 * @throws DuplicateActiveLeaseException
	 */
	public function change_status( int $lease_id, string $new_status ): bool {
		$lease = $this->find( $lease_id );

		if ( null === $lease ) {
			return false;
		}

		if ( self::STATUS_ACTIVE === $new_status ) {
			$this->guard_against_duplicate_active_lease( (int) $lease['unit_id'], $lease_id );
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
		$table = $this->table_name();

		$sql    = "SELECT * FROM {$table} WHERE unit_id = %d AND status = %s AND deleted_at IS NULL";
		$params = array( $unit_id, self::STATUS_ACTIVE );

		if ( null !== $exclude_lease_id ) {
			$sql     .= ' AND id != %d';
			$params[] = $exclude_lease_id;
		}

		$sql .= ' LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->row( $sql, $params );
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
	 * @throws DuplicateActiveLeaseException
	 */
	private function guard_against_duplicate_active_lease( int $unit_id, ?int $exclude_lease_id = null ): void {
		$conflict = $this->active_lease_for_unit( $unit_id, $exclude_lease_id );

		if ( null !== $conflict ) {
			throw new DuplicateActiveLeaseException( (int) $conflict['id'] );
		}
	}
}
