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
	 * lease history must be archived, not hard-deleted. This class never
	 * exposes hard_delete() publicly at all, so this is only relevant to
	 * callers deciding whether soft_delete() is appropriate to offer in the UI.
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
}
