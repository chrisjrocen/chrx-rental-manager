<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit trail for outbound notification emails (SPEC.md §5) — used by the
 * Billing phase's cron jobs to dedupe reminder sends and by support to
 * confirm "did the tenant actually get the reminder".
 */
final class NotificationLog extends AbstractRepository {

	protected const TABLE = 'rm_notifications_log';

	protected const HAS_CREATED_AT = false;

	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	public const CHANNEL_EMAIL    = 'email';
	public const CHANNEL_WHATSAPP = 'whatsapp';
	public const CHANNEL_PORTAL   = 'portal';

	/**
	 * v2 (SPEC.md §10 item 7, §4.7): $channel/$failure_reason are optional
	 * so every pre-v2 caller (still passing 4 args) keeps logging plain
	 * 'email' rows unchanged; only Communications\Notifier passes them
	 * explicitly.
	 */
	public function record(
		string $type,
		string $recipient,
		?int $entity_id,
		string $status = self::STATUS_SENT,
		string $channel = self::CHANNEL_EMAIL,
		?string $failure_reason = null
	): int|false {
		return $this->insert(
			array(
				'type'           => $type,
				'channel'        => $channel,
				'recipient'      => $recipient,
				'entity_id'      => $entity_id,
				'sent_at'        => current_time( 'mysql' ),
				'status'         => $status,
				'failure_reason' => $failure_reason,
			)
		);
	}

	/**
	 * Used by the renewal-reminder cron to avoid duplicate sends for the
	 * same lease/threshold combination (SPEC.md §4.2).
	 */
	/**
	 * Delivery history for a single type+entity, newest first — used by
	 * the Custom Alerts edit screen (SPEC.md §4.8: "log per-recipient
	 * per-channel") to show what actually went out for a given alert.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function for_type_and_entity( string $type, int $entity_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE type = %s AND entity_id = %d ORDER BY sent_at DESC",
			array( $type, $entity_id )
		);
	}

	public function already_sent( string $type, int $entity_id ): bool {
		$table = $this->table_name();

		$count = $this->wpdb()->get_var(
			$this->wpdb()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT COUNT(*) FROM {$table} WHERE type = %s AND entity_id = %d",
				$type,
				$entity_id
			)
		);

		return (int) $count > 0;
	}
}
