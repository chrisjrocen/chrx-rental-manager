<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates/updates the plugin's `wp_rm_*` tables via dbDelta and tracks the
 * installed schema version so `admin_init` can detect a version bump
 * without requiring a full deactivate/reactivate cycle.
 *
 * Real `FOREIGN KEY` constraints are deliberately not used: dbDelta does
 * not parse `FOREIGN KEY` clauses reliably (it can re-issue/duplicate them
 * on every call since it only understands `KEY`/`PRIMARY KEY`), and WordPress
 * core itself avoids them for the same reason. Referential integrity is
 * enforced at the repository layer instead; every FK column still gets a
 * `KEY` index for join/filter performance.
 */
final class Migrator {

	/**
	 * Bump whenever the schema in create_table_sql() changes; dbDelta only
	 * applies additive changes (new tables/columns/indexes), so column
	 * removals or type changes still need an explicit upgrade routine.
	 */
	public const SCHEMA_VERSION = '4';

	public static function maybe_migrate(): void {
		if ( get_option( \ChrxRentalManager\DB_SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::migrate();
	}

	public static function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::create_table_sql( $wpdb->prefix, $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::run_data_migrations();

		update_option( \ChrxRentalManager\DB_SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Data transformations dbDelta cannot express (value renames, backfills,
	 * cron hook swaps) — SPEC.md §10 items 1 (booked rename), 7 (channel
	 * backfill), and 10 (cron hook rename). Each step checks current state
	 * before mutating, so this is safe to run on every migrate() call
	 * (including on an already-migrated v2 site, and repeatedly in tests).
	 */
	private static function run_data_migrations(): void {
		global $wpdb;

		// §10 item 1: rm_units 'reserved' -> 'booked'.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}rm_units SET status = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				'booked',
				'reserved'
			)
		);

		// §10 item 7: rm_notifications_log.channel backfill for pre-v2 rows.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
			"UPDATE {$wpdb->prefix}rm_notifications_log SET channel = 'email' WHERE channel = '' OR channel IS NULL"
		);

		// §10 item 10: rename cron hook rm_generate_monthly_charges -> rm_generate_charges.
		self::migrate_cron_hook();

		// §10 item 4: recorded_by must become nullable for webhook-recorded
		// Nylon Pay payments. dbDelta's CREATE TABLE diffing does not
		// reliably relax an existing column's NOT NULL constraint, so this
		// is done with an explicit, idempotent MODIFY (MySQL allows
		// re-running an identical MODIFY with no error).
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}rm_payments MODIFY recorded_by BIGINT UNSIGNED NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static DDL, no user input.
	}

	/**
	 * Unschedules the old hook name and schedules the new one, preserving
	 * the recurrence. wp_next_scheduled()/wp_unschedule_event() make this
	 * safe to call repeatedly: once the old hook is gone, the "unschedule"
	 * half becomes a no-op, and wp_schedule_event() is itself a no-op when
	 * the new hook is already scheduled.
	 */
	private static function migrate_cron_hook(): void {
		$old_hook = 'rm_generate_monthly_charges';
		$new_hook = 'rm_generate_charges';

		$old_timestamp = wp_next_scheduled( $old_hook );

		if ( false !== $old_timestamp ) {
			wp_unschedule_event( $old_timestamp, $old_hook );

			if ( false === wp_next_scheduled( $new_hook ) ) {
				wp_schedule_event( $old_timestamp, 'daily', $new_hook );
			}
		}
	}

	/**
	 * @return array<int,string> one CREATE TABLE statement per table, in
	 *                           dbDelta's required formatting (each column
	 *                           on its own line, two spaces before
	 *                           `PRIMARY KEY`, etc).
	 */
	private static function create_table_sql( string $prefix, string $charset_collate ): array {
		return array(
			"CREATE TABLE {$prefix}rm_properties (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL,
				address VARCHAR(255) NOT NULL DEFAULT '',
				city VARCHAR(120) NOT NULL DEFAULT '',
				notes TEXT NULL,
				notice_period_months SMALLINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY deleted_at (deleted_at)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_units (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				property_id BIGINT UNSIGNED NOT NULL,
				unit_label VARCHAR(100) NOT NULL,
				bedrooms SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				rent_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'vacant',
				occupancy_type VARCHAR(20) NOT NULL DEFAULT 'single',
				self_contained TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				notes TEXT NULL,
				created_at DATETIME NOT NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY property_id (property_id),
				KEY status (status),
				KEY deleted_at (deleted_at)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_tenants (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id BIGINT UNSIGNED NULL,
				full_name VARCHAR(191) NOT NULL,
				phone VARCHAR(40) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				whatsapp_number VARCHAR(20) NULL,
				national_id VARCHAR(80) NOT NULL DEFAULT '',
				next_of_kin_name VARCHAR(191) NULL,
				next_of_kin_phone VARCHAR(40) NULL,
				next_of_kin_relationship VARCHAR(80) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY wp_user_id (wp_user_id),
				KEY status (status),
				KEY deleted_at (deleted_at)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_leases (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				unit_id BIGINT UNSIGNED NOT NULL,
				tenant_id BIGINT UNSIGNED NOT NULL,
				start_date DATE NOT NULL,
				end_date DATE NOT NULL,
				rent_amount DECIMAL(12,2) NOT NULL,
				billing_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
				billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
				cycle_months TINYINT UNSIGNED NOT NULL DEFAULT 1,
				deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
				deposit_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				auto_renewed_from BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY unit_id (unit_id),
				KEY tenant_id (tenant_id),
				KEY status (status),
				KEY deleted_at (deleted_at)
			) ENGINE=InnoDB {$charset_collate};",

			// Financial ledger tables: never hard-deleted through the application
			// layer (SPEC.md §3) — no deleted_at column. Corrections happen via
			// status transitions (e.g. charges.status = 'waived',
			// payments.status = 'voided') or adjustment entries, never row
			// removal.
			"CREATE TABLE {$prefix}rm_charges (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id BIGINT UNSIGNED NOT NULL,
				period_start DATE NOT NULL,
				period_due_date DATE NOT NULL,
				amount_due DECIMAL(12,2) NOT NULL,
				type VARCHAR(20) NOT NULL DEFAULT 'rent',
				status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY lease_id (lease_id),
				KEY status (status)
			) ENGINE=InnoDB {$charset_collate};",

			// recorded_by is intentionally declared nullable here (v2: null for
			// webhook-recorded Nylon Pay payments, SPEC.md §3.1) — but dbDelta
			// does not reliably re-issue ALTER TABLE ... MODIFY for a column
			// whose NULL-ness changed on an existing installation, so
			// run_data_migrations() also runs an explicit MODIFY for upgrades
			// from schema versions where this column was NOT NULL.
			"CREATE TABLE {$prefix}rm_payments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id BIGINT UNSIGNED NOT NULL,
				charge_id BIGINT UNSIGNED NULL,
				amount DECIMAL(12,2) NOT NULL,
				method VARCHAR(20) NOT NULL,
				reference_note VARCHAR(255) NOT NULL DEFAULT '',
				gateway_transaction_id BIGINT UNSIGNED NULL,
				recorded_by BIGINT UNSIGNED NULL,
				receipt_id BIGINT UNSIGNED NULL,
				paid_at DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'recorded',
				voided_reason VARCHAR(255) NOT NULL DEFAULT '',
				voided_by BIGINT UNSIGNED NULL,
				voided_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY lease_id (lease_id),
				KEY charge_id (charge_id),
				KEY status (status),
				KEY gateway_transaction_id (gateway_transaction_id)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_receipts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				payment_id BIGINT UNSIGNED NOT NULL,
				receipt_number VARCHAR(60) NOT NULL,
				pdf_path VARCHAR(255) NOT NULL DEFAULT '',
				emailed_at DATETIME NULL,
				whatsapp_sent_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY receipt_number (receipt_number),
				KEY payment_id (payment_id)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_documents (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				entity_type VARCHAR(20) NOT NULL,
				entity_id BIGINT UNSIGNED NOT NULL,
				attachment_id BIGINT UNSIGNED NOT NULL,
				label VARCHAR(191) NOT NULL DEFAULT '',
				uploaded_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY entity_type_id (entity_type,entity_id)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_property_staff (
				property_id BIGINT UNSIGNED NOT NULL,
				wp_user_id BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (property_id,wp_user_id),
				KEY wp_user_id (wp_user_id)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_property_landlords (
				property_id BIGINT UNSIGNED NOT NULL,
				wp_user_id BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (property_id,wp_user_id),
				KEY wp_user_id (wp_user_id)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_notifications_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(60) NOT NULL,
				channel VARCHAR(20) NOT NULL DEFAULT 'email',
				recipient VARCHAR(191) NOT NULL,
				entity_id BIGINT UNSIGNED NULL,
				sent_at DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'sent',
				failure_reason VARCHAR(255) NULL,
				PRIMARY KEY  (id),
				KEY type (type),
				KEY entity_id (entity_id),
				KEY channel (channel)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_unit_amenities (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				unit_id BIGINT UNSIGNED NOT NULL,
				tag VARCHAR(80) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY unit_id (unit_id),
				KEY tag (tag)
			) ENGINE=InnoDB {$charset_collate};",

			// Void-not-delete (SPEC.md §3.3), same append-only-with-status
			// pattern as rm_charges/rm_payments — no deleted_at column;
			// voided_at/void_reason serve that role instead.
			"CREATE TABLE {$prefix}rm_expenses (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scope VARCHAR(20) NOT NULL DEFAULT 'account',
				property_id BIGINT UNSIGNED NULL,
				unit_id BIGINT UNSIGNED NULL,
				category VARCHAR(40) NOT NULL,
				custom_category_label VARCHAR(191) NULL,
				amount DECIMAL(12,2) NOT NULL,
				expense_date DATE NOT NULL,
				description TEXT NULL,
				recurring VARCHAR(20) NOT NULL DEFAULT 'none',
				recurring_parent_id BIGINT UNSIGNED NULL,
				recorded_by BIGINT UNSIGNED NOT NULL,
				voided_at DATETIME NULL,
				void_reason VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY property_id (property_id),
				KEY unit_id (unit_id),
				KEY recurring_parent_id (recurring_parent_id),
				KEY expense_date (expense_date),
				KEY voided_at (voided_at)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_alerts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(191) NOT NULL,
				message TEXT NOT NULL,
				entity_type VARCHAR(20) NOT NULL DEFAULT 'none',
				entity_id BIGINT UNSIGNED NULL,
				schedule_type VARCHAR(20) NOT NULL DEFAULT 'once',
				scheduled_at DATETIME NULL,
				recipients TEXT NOT NULL,
				channels TEXT NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				last_sent_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY entity_type_id (entity_type,entity_id),
				KEY active (active)
			) ENGINE=InnoDB {$charset_collate};",

			// Append-only (SPEC.md §3.3) — no deleted_at, corrections happen
			// via status transitions only, same as rm_charges/rm_payments.
			"CREATE TABLE {$prefix}rm_gateway_transactions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				gateway VARCHAR(20) NOT NULL DEFAULT 'nylonpay',
				reference VARCHAR(64) NOT NULL,
				lease_id BIGINT UNSIGNED NOT NULL,
				charge_id BIGINT UNSIGNED NULL,
				tenant_id BIGINT UNSIGNED NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				currency VARCHAR(10) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'initiated',
				initiated_by VARCHAR(20) NOT NULL,
				initiator_user_id BIGINT UNSIGNED NOT NULL,
				phone_used VARCHAR(20) NOT NULL DEFAULT '',
				raw_webhook_payload LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY reference (reference),
				KEY lease_id (lease_id),
				KEY charge_id (charge_id),
				KEY tenant_id (tenant_id),
				KEY status (status)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_move_out_notices (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id BIGINT UNSIGNED NOT NULL,
				notice_date DATE NOT NULL,
				earliest_move_out_date DATE NOT NULL,
				requested_move_out_date DATE NULL,
				submitted_by VARCHAR(20) NOT NULL,
				submitted_by_user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				notes TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY lease_id (lease_id),
				KEY status (status)
			) ENGINE=InnoDB {$charset_collate};",
		);
	}
}
