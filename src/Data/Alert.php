<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom alerts (SPEC.md §3.2, §4.8) — title/message attached to a
 * property/unit/account-level, with a schedule, recipient config, and
 * channel config. `recipients` and `channels` are stored as JSON (the
 * repository decodes/encodes at the boundary so callers work with plain
 * PHP arrays, matching how every other repository hands back arrays
 * rather than raw row scalars). Dispatch logic (recipient resolution at
 * send time, actual sending) lands in Phase V2-6 — this repository only
 * provides the CRUD/query surface that cron will need.
 */
final class Alert extends AbstractRepository {

	protected const TABLE = 'rm_alerts';

	public const ENTITY_PROPERTY = 'property';
	public const ENTITY_UNIT     = 'unit';
	public const ENTITY_NONE     = 'none';

	public const SCHEDULE_ONCE    = 'once';
	public const SCHEDULE_DAILY   = 'daily';
	public const SCHEDULE_WEEKLY  = 'weekly';
	public const SCHEDULE_MONTHLY = 'monthly';

	// v2 (SPEC.md §4.8) — the `recipients` JSON's shape:
	// {"selectors": [self::RECIPIENT_*, ...], "user_ids": [wp_user_id, ...]}.
	// Selectors resolve dynamically at send time (Cron\AlertDispatcher);
	// user_ids are explicit picks alongside them.
	public const RECIPIENT_TENANTS_OF_ENTITY  = 'tenants_of_entity';
	public const RECIPIENT_STAFF_OF_ENTITY    = 'staff_of_entity';
	public const RECIPIENT_LANDLORD_OF_ENTITY = 'landlord_of_entity';
	public const RECIPIENT_SELF               = 'self';

	/**
	 * @param array<string,mixed> $data recipients/channels may be passed as
	 *                                   plain arrays; JSON-encoded here.
	 */
	public function insert( array $data ): int|false {
		return parent::insert( $this->encode_json_fields( $data ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		return parent::update( $id, $this->encode_json_fields( $data ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = parent::find( $id );

		return null === $row ? null : $this->decode_json_fields( $row );
	}

	/**
	 * Active alerts due to send: one-off alerts whose scheduled_at has
	 * passed, or recurring alerts (dispatch cron applies the
	 * schedule-specific due-ness check per SPEC.md §4.8, since "due" for a
	 * recurring alert depends on last_sent_at plus its recurrence, not a
	 * single column comparison this query can express generically).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
		$rows = $this->results( "SELECT * FROM {$table} WHERE active = 1", array() );

		return array_map( array( $this, 'decode_json_fields' ), $rows );
	}

	/**
	 * Every alert (active or not) — the admin list screen's data source;
	 * active()'s "due to send" framing is cron-specific, this is the plain
	 * CRUD listing every other admin screen's list table needs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
		$rows = $this->results( "SELECT * FROM {$table} ORDER BY created_at DESC", array() );

		return array_map( array( $this, 'decode_json_fields' ), $rows );
	}

	public function deactivate( int $id ): bool {
		return $this->update( $id, array( 'active' => 0 ) );
	}

	public function mark_sent( int $id ): bool {
		return $this->update( $id, array( 'last_sent_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * rm_alerts isn't a financial/append-only table (SPEC.md §3.3's list is
	 * rm_charges/rm_payments/rm_receipts/rm_gateway_transactions only) and
	 * isn't soft-deletable either — "recurring alerts stay active until
	 * toggled off or deleted" (build prompt) implies a real delete, not an
	 * archive/trash flow like Units/Tenants/Leases.
	 */
	public function delete( int $id ): bool {
		return $this->hard_delete( $id );
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function encode_json_fields( array $data ): array {
		foreach ( array( 'recipients', 'channels' ) as $field ) {
			if ( array_key_exists( $field, $data ) && is_array( $data[ $field ] ) ) {
				$data[ $field ] = wp_json_encode( $data[ $field ] );
			}
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $row
	 *
	 * @return array<string,mixed>
	 */
	private function decode_json_fields( array $row ): array {
		foreach ( array( 'recipients', 'channels' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
				$decoded       = json_decode( $row[ $field ], true );
				$row[ $field ] = is_array( $decoded ) ? $decoded : array();
			}
		}

		return $row;
	}
}
