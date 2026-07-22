<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nylon Pay gateway transactions (SPEC.md §3.2/§3.3, §4.9) — append-only,
 * same as rm_charges/rm_payments: no delete, no soft-delete; every state
 * change is a status transition on the same row. The write-first flow
 * (§4.9: "the DB row exists first, so a crashed request can't produce an
 * untracked payment") means insert() always happens before the collection
 * API call — Phase V2-5 wires that up; this repository only provides the
 * data-layer surface (CRUD, idempotency lookup, reconciliation queries).
 */
final class GatewayTransaction extends AbstractRepository {

	protected const TABLE = 'rm_gateway_transactions';

	protected const HAS_CREATED_AT = true;

	public const GATEWAY_NYLONPAY = 'nylonpay';

	public const STATUS_INITIATED  = 'initiated';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_SUCCESSFUL = 'successful';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';
	public const STATUS_EXPIRED    = 'expired';

	public const INITIATED_BY_TENANT = 'tenant';
	public const INITIATED_BY_STAFF  = 'staff';

	/**
	 * @param array<string,mixed> $data
	 */
	public function insert( array $data ): int|false {
		if ( ! array_key_exists( 'updated_at', $data ) ) {
			$data['updated_at'] = current_time( 'mysql' );
		}

		return parent::insert( $data );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		$data['updated_at'] = current_time( 'mysql' );

		return parent::update( $id, $data );
	}

	/**
	 * The webhook's dedupe key (SPEC.md §4.9: "dedupes on the transaction
	 * reference — at-least-once delivery — a reference already marked
	 * successful returns 200 and does nothing").
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_reference( string $reference ): ?array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->row( "SELECT * FROM {$table} WHERE reference = %s", array( $reference ) );
	}

	/**
	 * initiated/processing transactions older than $minutes_old — the
	 * hourly reconciliation sweep's candidate set (SPEC.md §4.9).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function unresolved_older_than( int $minutes_old ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE status IN (%s,%s) AND created_at <= DATE_SUB(%s, INTERVAL %d MINUTE)",
			array( self::STATUS_INITIATED, self::STATUS_PROCESSING, current_time( 'mysql' ), $minutes_old )
		);
	}

	public function mark_successful( int $id ): bool {
		return $this->update( $id, array( 'status' => self::STATUS_SUCCESSFUL ) );
	}

	public function mark_failed( int $id, ?string $raw_webhook_payload = null ): bool {
		$data = array( 'status' => self::STATUS_FAILED );

		if ( null !== $raw_webhook_payload ) {
			$data['raw_webhook_payload'] = $raw_webhook_payload;
		}

		return $this->update( $id, $data );
	}

	public function mark_expired( int $id ): bool {
		return $this->update( $id, array( 'status' => self::STATUS_EXPIRED ) );
	}
}
