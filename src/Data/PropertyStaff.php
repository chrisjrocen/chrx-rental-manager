<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Join table: which staff (wp_user_id) are assigned to which property.
 * No surrogate id — (property_id, wp_user_id) is the primary key. Backs
 * the Roles & Permissions phase's Access::userCanAccessProperty() helper.
 */
final class PropertyStaff {

	private const TABLE = 'rm_property_staff';

	private function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	private function table_name(): string {
		return $this->wpdb()->prefix . self::TABLE;
	}

	public function assign( int $property_id, int $wp_user_id ): bool {
		$wpdb = $this->wpdb();

		return false !== $wpdb->replace(
			$this->table_name(),
			array(
				'property_id' => $property_id,
				'wp_user_id'  => $wp_user_id,
			),
			array( '%d', '%d' )
		);
	}

	public function unassign( int $property_id, int $wp_user_id ): bool {
		$wpdb = $this->wpdb();

		return false !== $wpdb->delete(
			$this->table_name(),
			array(
				'property_id' => $property_id,
				'wp_user_id'  => $wp_user_id,
			),
			array( '%d', '%d' )
		);
	}

	public function is_assigned( int $property_id, int $wp_user_id ): bool {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT COUNT(*) FROM {$table} WHERE property_id = %d AND wp_user_id = %d",
				$property_id,
				$wp_user_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * @return array<int,int> property IDs this staff member is assigned to
	 */
	public function property_ids_for_user( int $wp_user_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT property_id FROM {$table} WHERE wp_user_id = %d",
				$wp_user_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @return array<int,int> wp_user_ids assigned to this property
	 */
	public function user_ids_for_property( int $property_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT wp_user_id FROM {$table} WHERE property_id = %d",
				$property_id
			)
		);

		return array_map( 'intval', $ids );
	}
}
