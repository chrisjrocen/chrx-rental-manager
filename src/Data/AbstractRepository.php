<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared CRUD boilerplate for the `wp_rm_*` table repositories: prepared
 * statements throughout, table name resolution via $wpdb->prefix, and a
 * consistent array-shape return type (associative arrays, not objects —
 * keeps callers from depending on a magic model class this phase doesn't
 * need).
 */
abstract class AbstractRepository {

	/** Table name without the $wpdb prefix, e.g. 'rm_properties'. */
	protected const TABLE = '';

	/**
	 * Every table has a created_at column except rm_notifications_log
	 * (which uses sent_at instead) — NotificationLog overrides this to
	 * false so insert() doesn't try to populate a column that doesn't exist.
	 */
	protected const HAS_CREATED_AT = true;

	protected function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Table names can't go through $wpdb->prepare() placeholders; this is
	 * the standard WP-safe pattern since the name is built from
	 * $wpdb->prefix plus a hardcoded class constant, never user input.
	 */
	public function table_name(): string {
		return $this->wpdb()->prefix . static::TABLE;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, see table_name() doc.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return null === $row ? null : $row;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function insert( array $data ): int|false {
		$wpdb = $this->wpdb();

		if ( static::HAS_CREATED_AT && ! array_key_exists( 'created_at', $data ) ) {
			$data['created_at'] = current_time( 'mysql' );
		}

		$result = $wpdb->insert( $this->table_name(), $data );

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		$wpdb   = $this->wpdb();
		$result = $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) );

		return false !== $result;
	}

	/**
	 * Financial repositories (Charge, Payment, Receipt) must never call this
	 * — corrections happen via status transitions (e.g. Charge::mark_waived(),
	 * Payment::void()), never row removal (SPEC.md §3). The four
	 * soft-deletable repositories (Property, Unit, Tenant, Lease) expose
	 * this via their own delete_permanently() wrapper, reachable only from
	 * the Trash view after their own has_*_history() guard passes.
	 */
	public function hard_delete( int $id ): bool {
		$wpdb = $this->wpdb();

		return false !== $wpdb->delete( $this->table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Callers always pass $sql with placeholders and a non-empty $params —
	 * every call site in this codebase builds $sql from a fixed string
	 * (interpolating only table_name(), never user input) plus %d/%s
	 * placeholders for actual values, so this is the single point where
	 * $wpdb->prepare() is applied on their behalf.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function results( string $sql, array $params ): array {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is always prepared below; interpolation in callers' $sql is table_name() only.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return null === $results ? array() : $results;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	protected function row( string $sql, array $params ): ?array {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is always prepared below; interpolation in callers' $sql is table_name() only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return null === $row ? null : $row;
	}
}
