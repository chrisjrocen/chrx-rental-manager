<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move-out notices (SPEC.md §3.2, §4.10) — earliest_move_out_date
 * computation, notice-flow wiring into the v1 move-out workflow, and
 * portal ownership enforcement all land in Phase V2-7; this repository
 * only provides the CRUD/query surface (including the single-active-notice
 * constraint's lookup) that later phase needs.
 */
final class MoveOutNotice extends AbstractRepository {

	protected const TABLE = 'rm_move_out_notices';

	public const SUBMITTED_BY_TENANT = 'tenant';
	public const SUBMITTED_BY_STAFF  = 'staff';

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * SPEC.md §4.10: "multiple active notices per lease: blocked; cancel
	 * the existing one first."
	 *
	 * @return array<string,mixed>|null
	 */
	public function active_for_lease( int $lease_id ): ?array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->row(
			"SELECT * FROM {$table} WHERE lease_id = %d AND status = %s",
			array( $lease_id, self::STATUS_ACTIVE )
		);
	}

	/**
	 * Every active notice, account-wide — the dashboard flag's data source
	 * (SPEC.md §5: "Move-out notice submitted/cancelled → ... dashboard
	 * flag"); scoping to the viewer's accessible properties happens in
	 * Admin\Support\Reports, same as every other dashboard query.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_active(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
		return $this->results(
			"SELECT * FROM {$table} WHERE status = %s ORDER BY earliest_move_out_date ASC",
			array( self::STATUS_ACTIVE )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_lease( int $lease_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE lease_id = %d ORDER BY created_at DESC",
			array( $lease_id )
		);
	}

	public function cancel( int $id ): bool {
		return $this->update( $id, array( 'status' => self::STATUS_CANCELLED ) );
	}

	public function complete( int $id ): bool {
		return $this->update( $id, array( 'status' => self::STATUS_COMPLETED ) );
	}
}
