<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free-form amenity tags on a unit (SPEC.md §3.2) — structured attributes
 * (occupancy_type, self_contained, capacity) live on rm_units directly;
 * this table is only for the open-vocabulary tags (parking, balcony,
 * water tank…) that unit list filtering autocompletes against.
 */
final class UnitAmenity extends AbstractRepository {

	protected const TABLE = 'rm_unit_amenities';

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_unit( int $unit_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results( "SELECT * FROM {$table} WHERE unit_id = %d ORDER BY tag ASC", array( $unit_id ) );
	}

	/**
	 * Distinct tags across every unit — the autocomplete vocabulary source
	 * (SPEC.md §4.1: "autocomplete against existing tags to keep the
	 * vocabulary from fragmenting").
	 *
	 * @return array<int,string>
	 */
	public function distinct_tags(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
		$rows = $this->wpdb()->get_col( "SELECT DISTINCT tag FROM {$table} ORDER BY tag ASC" );

		return null === $rows ? array() : $rows;
	}

	public function add( int $unit_id, string $tag ): int|false {
		return $this->insert(
			array(
				'unit_id' => $unit_id,
				'tag'     => $tag,
			)
		);
	}

	public function remove( int $unit_id, string $tag ): bool {
		$result = $this->wpdb()->delete(
			$this->table_name(),
			array(
				'unit_id' => $unit_id,
				'tag'     => $tag,
			),
			array( '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Replaces a unit's full tag set — the unit form's autocomplete widget
	 * submits the complete desired list rather than incremental add/remove
	 * calls, so this is the primary write path from the form.
	 *
	 * @param array<int,string> $tags
	 */
	public function sync_for_unit( int $unit_id, array $tags ): void {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE unit_id = %d", $unit_id ) );

		foreach ( array_unique( array_filter( array_map( 'trim', $tags ) ) ) as $tag ) {
			$this->add( $unit_id, $tag );
		}
	}
}
