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
 * manually set `maintenance`/`booked` via set_manual_status(), which
 * this repository does not auto-clear — only an explicit status change
 * clears it, which is exactly what set_manual_status()/sync_unit_status()
 * do when called.
 *
 * v2 (SPEC.md §10 item 1/11): `reserved` was renamed to `booked` — the
 * Migrator's data migration backfills existing rows, and this constant
 * carries the new value throughout the codebase (no deprecated alias:
 * every call site was updated in the same change).
 */
final class Unit extends AbstractRepository {

	use SoftDeletes;

	protected const TABLE = 'rm_units';

	public const STATUS_VACANT      = 'vacant';
	public const STATUS_OCCUPIED    = 'occupied';
	public const STATUS_MAINTENANCE = 'maintenance';
	public const STATUS_BOOKED      = 'booked';

	public const OCCUPANCY_SINGLE = 'single';
	public const OCCUPANCY_DOUBLE = 'double';
	public const OCCUPANCY_FAMILY = 'family';

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
	 * v2 (SPEC.md §4.1 edge case: "Amenity tag filtering: unit list
	 * filterable by occupancy type, self-contained, and any tag") —
	 * $occupancy_type/$self_contained filter directly on rm_units;
	 * $tag joins rm_unit_amenities (DISTINCT, since a unit can carry
	 * several tags and would otherwise appear once per matching tag).
	 */
	public function search(
		string $term = '',
		string $status = '',
		int $property_id = 0,
		int $limit = 20,
		int $offset = 0,
		string $occupancy_type = '',
		?bool $self_contained = null,
		string $tag = ''
	): array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		$select = "SELECT {$table}.*";
		$join   = '';
		$where  = array( "{$table}.deleted_at IS NULL" );
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

		if ( '' !== $occupancy_type ) {
			$where[]  = 'occupancy_type = %s';
			$params[] = $occupancy_type;
		}

		if ( null !== $self_contained ) {
			$where[]  = 'self_contained = %d';
			$params[] = $self_contained ? 1 : 0;
		}

		if ( '' !== $tag ) {
			$select  .= ', ' . $wpdb->prefix . 'rm_unit_amenities.tag AS matched_tag';
			$join     = 'INNER JOIN ' . $wpdb->prefix . "rm_unit_amenities ON {$wpdb->prefix}rm_unit_amenities.unit_id = {$table}.id";
			$where[]  = $wpdb->prefix . 'rm_unit_amenities.tag = %s';
			$params[] = $tag;
		}

		$params[] = $limit;
		$params[] = $offset;

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		return $this->results(
			"{$select} FROM {$table} {$join} WHERE {$where_sql} GROUP BY {$table}.id ORDER BY unit_label ASC LIMIT %d OFFSET %d",
			$params
		);
	}

	/**
	 * Staff-driven override for maintenance/booked. Not auto-cleared by
	 * lease changes (SPEC.md §4.1) — only another explicit call clears it.
	 */
	public function set_manual_status( int $unit_id, string $status ): bool {
		if ( ! in_array( $status, array( self::STATUS_MAINTENANCE, self::STATUS_BOOKED ), true ) ) {
			return false;
		}

		return $this->update( $unit_id, array( 'status' => $status ) );
	}

	/**
	 * Called by Lease whenever a lease starts/ends/is renewed. Does nothing
	 * if the unit is currently under a manual maintenance/booked override
	 * — that override only clears via an explicit set_manual_status() call
	 * or clear_manual_status(), not implicitly from lease activity.
	 */
	public function sync_derived_status( int $unit_id, bool $has_active_lease ): void {
		$current = $this->find( $unit_id );

		if ( null === $current ) {
			return;
		}

		if ( in_array( $current['status'], array( self::STATUS_MAINTENANCE, self::STATUS_BOOKED ), true ) ) {
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
