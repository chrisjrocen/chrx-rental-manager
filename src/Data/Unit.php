<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Units.
 *
 * Status handling (SPEC.md §4.1): `occupied`/`vacant` are derived from
 * active-lease presence and kept in sync by the Lease repository whenever
 * a lease starts/ends/renews (see Lease::sync_unit_status()). Staff can
 * manually set `maintenance`/`reserved` via set_manual_status(), which
 * this repository does not auto-clear — only an explicit status change
 * clears it, which is exactly what set_manual_status()/sync_unit_status()
 * do when called.
 */
final class Unit extends AbstractRepository {

	use SoftDeletes;

	protected const TABLE = 'rm_units';

	public const STATUS_VACANT      = 'vacant';
	public const STATUS_OCCUPIED    = 'occupied';
	public const STATUS_MAINTENANCE = 'maintenance';
	public const STATUS_RESERVED    = 'reserved';

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_property( int $property_id ): array {
		$table = $this->table_name();

		return $this->results(
			"SELECT * FROM {$table} WHERE property_id = %d AND deleted_at IS NULL ORDER BY unit_label ASC",
			array( $property_id )
		);
	}

	/**
	 * Search/filter for the units list screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $term = '', string $status = '', int $property_id = 0, int $limit = 20, int $offset = 0 ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		if ( '' !== $term ) {
			$where[]  = 'unit_label LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $term ) . '%';
		}

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $property_id > 0 ) {
			$where[]  = 'property_id = %d';
			$params[] = $property_id;
		}

		$params[] = $limit;
		$params[] = $offset;

		$where_sql = implode( ' AND ', $where );

		return $this->results(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY unit_label ASC LIMIT %d OFFSET %d",
			$params
		);
	}

	/**
	 * Staff-driven override for maintenance/reserved. Not auto-cleared by
	 * lease changes (SPEC.md §4.1) — only another explicit call clears it.
	 */
	public function set_manual_status( int $unit_id, string $status ): bool {
		if ( ! in_array( $status, array( self::STATUS_MAINTENANCE, self::STATUS_RESERVED ), true ) ) {
			return false;
		}

		return $this->update( $unit_id, array( 'status' => $status ) );
	}

	/**
	 * Called by Lease whenever a lease starts/ends/is renewed. Does nothing
	 * if the unit is currently under a manual maintenance/reserved override
	 * — that override only clears via an explicit set_manual_status() call
	 * or clear_manual_status(), not implicitly from lease activity.
	 */
	public function sync_derived_status( int $unit_id, bool $has_active_lease ): void {
		$current = $this->find( $unit_id );

		if ( null === $current ) {
			return;
		}

		if ( in_array( $current['status'], array( self::STATUS_MAINTENANCE, self::STATUS_RESERVED ), true ) ) {
			return;
		}

		$this->update(
			$unit_id,
			array(
				'status' => $has_active_lease ? self::STATUS_OCCUPIED : self::STATUS_VACANT,
			)
		);
	}

	public function clear_manual_status( int $unit_id, bool $has_active_lease ): bool {
		return $this->update(
			$unit_id,
			array(
				'status' => $has_active_lease ? self::STATUS_OCCUPIED : self::STATUS_VACANT,
			)
		);
	}

	/**
	 * Any lease row at all (deleted or not) — a unit with lease history may
	 * have charges/payments hanging off those leases, so it must stay
	 * blocked from permanent delete even if every lease is already trashed.
	 */
	public function has_lease_history( int $unit_id ): bool {
		$wpdb = $this->wpdb();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_leases WHERE unit_id = %d",
				$unit_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Permanently removes the unit row. Only safe to call after
	 * has_lease_history() returns false — callers (UnitsController) are
	 * responsible for enforcing that guard before invoking this.
	 */
	public function delete_permanently( int $unit_id ): bool {
		return $this->hard_delete( $unit_id );
	}
}
