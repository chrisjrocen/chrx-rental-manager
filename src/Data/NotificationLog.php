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

	public function record( string $type, string $recipient, ?int $entity_id, string $status = self::STATUS_SENT ): int|false {
		return $this->insert(
			array(
				'type'      => $type,
				'recipient' => $recipient,
				'entity_id' => $entity_id,
				'sent_at'   => current_time( 'mysql' ),
				'status'    => $status,
			)
		);
	}

	/**
	 * Used by the renewal-reminder cron to avoid duplicate sends for the
	 * same lease/threshold combination (SPEC.md §4.2).
	 */
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
