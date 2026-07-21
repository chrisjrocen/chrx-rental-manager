<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Property extends AbstractRepository {

	use SoftDeletes;

	protected const TABLE = 'rm_properties';

	/**
	 * Name/city search for the properties list screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $term = '', int $limit = 20, int $offset = 0 ): array {
		$table = $this->table_name();

		if ( '' === $term ) {
			return $this->results(
				"SELECT * FROM {$table} WHERE deleted_at IS NULL ORDER BY name ASC LIMIT %d OFFSET %d",
				array( $limit, $offset )
			);
		}

		$like = '%' . $this->wpdb()->esc_like( $term ) . '%';

		return $this->results(
			"SELECT * FROM {$table} WHERE deleted_at IS NULL AND (name LIKE %s OR city LIKE %s OR address LIKE %s) ORDER BY name ASC LIMIT %d OFFSET %d",
			array( $like, $like, $like, $limit, $offset )
		);
	}

	/**
	 * Blocked-delete check per SPEC.md §4.1: a property with units that have
	 * lease history must be archived (trashed), not permanently deleted.
	 */
	public function has_units( int $property_id ): bool {
		$wpdb = $this->wpdb();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_units WHERE property_id = %d AND deleted_at IS NULL",
				$property_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Any unit row at all (deleted/trashed or not) — used to block permanent
	 * delete, since even a trashed unit may carry lease/financial history
	 * that must stay queryable. Staff must permanently delete every one of
	 * the property's units first, each individually gated by
	 * Unit::has_lease_history().
	 */
	public function has_any_units( int $property_id ): bool {
		$wpdb = $this->wpdb();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_units WHERE property_id = %d",
				$property_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Permanently removes the property row. Only safe to call after
	 * has_any_units() returns false — callers (PropertiesController) are
	 * responsible for enforcing that guard before invoking this.
	 */
	public function delete_permanently( int $property_id ): bool {
		return $this->hard_delete( $property_id );
	}
}
