<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tenant extends AbstractRepository {

	use SoftDeletes;

	protected const TABLE = 'rm_tenants';

	public const STATUS_ACTIVE = 'active';
	public const STATUS_FORMER = 'former';

	/**
	 * Search by name/phone/email for the tenants list screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $term = '', string $status = '', int $limit = 20, int $offset = 0 ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		if ( '' !== $term ) {
			$like     = '%' . $wpdb->esc_like( $term ) . '%';
			$where[]  = '(full_name LIKE %s OR phone LIKE %s OR email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$params[] = $limit;
		$params[] = $offset;

		$where_sql = implode( ' AND ', $where );

		return $this->results(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY full_name ASC LIMIT %d OFFSET %d",
			$params
		);
	}

	public function find_by_wp_user_id( int $wp_user_id ): ?array {
		$wpdb  = $this->wpdb();
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->row( "SELECT * FROM {$table} WHERE wp_user_id = %d AND deleted_at IS NULL", array( $wp_user_id ) );
	}

	public function link_wp_user( int $tenant_id, int $wp_user_id ): bool {
		return $this->update( $tenant_id, array( 'wp_user_id' => $wp_user_id ) );
	}

	/**
	 * Any lease row at all (deleted/trashed or not) — used to block
	 * permanent delete, since a tenant's leases may carry charges/payments
	 * that must stay queryable.
	 */
	public function has_lease_history( int $tenant_id ): bool {
		$wpdb = $this->wpdb();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_leases WHERE tenant_id = %d",
				$tenant_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Permanently removes the tenant row. Only safe to call after
	 * has_lease_history() returns false — callers (TenantsController) are
	 * responsible for enforcing that guard before invoking this.
	 */
	public function delete_permanently( int $tenant_id ): bool {
		return $this->hard_delete( $tenant_id );
	}
}
