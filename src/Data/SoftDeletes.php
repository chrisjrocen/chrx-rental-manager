<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Soft-delete behavior for the four archivable entities (SPEC.md §3):
 * properties, units, tenants, leases. Used only by repositories whose
 * table has a `deleted_at` column — financial tables do not use this.
 *
 * @mixin AbstractRepository
 */
trait SoftDeletes {

	public function soft_delete( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => current_time( 'mysql' ) ) );
	}

	public function restore( int $id ): bool {
		$wpdb   = $this->wpdb();
		$result = $wpdb->update( $this->table_name(), array( 'deleted_at' => null ), array( 'id' => $id ) );

		return false !== $result;
	}

	public function is_deleted( int $id ): bool {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, see table_name() doc.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT deleted_at FROM {$table} WHERE id = %d", $id ) );

		return null !== $value;
	}

	/**
	 * Active (non-archived) rows only — the default list-query view.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_active( int $limit = 0, int $offset = 0 ): array {
		return $this->fetch_by_archived_state( false, $limit, $offset );
	}

	/**
	 * Archived (soft-deleted) rows only — the "Archived" restore view.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_archived( int $limit = 0, int $offset = 0 ): array {
		return $this->fetch_by_archived_state( true, $limit, $offset );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_by_archived_state( bool $archived, int $limit, int $offset ): array {
		$table     = $this->table_name();
		$condition = $archived ? 'IS NOT NULL' : 'IS NULL';

		// $limit <= 0 means "no limit"; PHP_INT_MAX as the LIMIT keeps this a
		// single prepared query instead of conditionally building the SQL.
		$effective_limit = $limit > 0 ? $limit : PHP_INT_MAX;

		return $this->results(
			"SELECT * FROM {$table} WHERE deleted_at {$condition} ORDER BY id DESC LIMIT %d OFFSET %d",
			array( $effective_limit, $offset )
		);
	}
}
