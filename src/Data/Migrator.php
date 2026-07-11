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
	public const SCHEMA_VERSION = '1';

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

		update_option( \ChrxRentalManager\DB_SCHEMA_OPTION, self::SCHEMA_VERSION );
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
				national_id VARCHAR(80) NOT NULL DEFAULT '',
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
			// status transitions (e.g. charges.status = 'waived') or adjustment
			// entries, never row removal.
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

			"CREATE TABLE {$prefix}rm_payments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				lease_id BIGINT UNSIGNED NOT NULL,
				charge_id BIGINT UNSIGNED NULL,
				amount DECIMAL(12,2) NOT NULL,
				method VARCHAR(20) NOT NULL,
				reference_note VARCHAR(255) NOT NULL DEFAULT '',
				recorded_by BIGINT UNSIGNED NOT NULL,
				receipt_id BIGINT UNSIGNED NULL,
				paid_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY lease_id (lease_id),
				KEY charge_id (charge_id)
			) ENGINE=InnoDB {$charset_collate};",

			"CREATE TABLE {$prefix}rm_receipts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				payment_id BIGINT UNSIGNED NOT NULL,
				receipt_number VARCHAR(60) NOT NULL,
				pdf_path VARCHAR(255) NOT NULL DEFAULT '',
				emailed_at DATETIME NULL,
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
				recipient VARCHAR(191) NOT NULL,
				entity_id BIGINT UNSIGNED NULL,
				sent_at DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'sent',
				PRIMARY KEY  (id),
				KEY type (type),
				KEY entity_id (entity_id)
			) ENGINE=InnoDB {$charset_collate};",
		);
	}
}
