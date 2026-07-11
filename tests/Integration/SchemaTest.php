<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

/**
 * Verifies Migrator::migrate() actually creates every table from
 * SPEC.md §3, with the soft-delete / append-only distinction intact.
 */
final class SchemaTest extends IntegrationTestCase {

	private const EXPECTED_TABLES = [
		'rm_properties',
		'rm_units',
		'rm_tenants',
		'rm_leases',
		'rm_charges',
		'rm_payments',
		'rm_receipts',
		'rm_documents',
		'rm_property_staff',
		'rm_property_landlords',
		'rm_notifications_log',
	];

	private const SOFT_DELETABLE_TABLES = [
		'rm_properties',
		'rm_units',
		'rm_tenants',
		'rm_leases',
	];

	private const APPEND_ONLY_FINANCIAL_TABLES = [
		'rm_charges',
		'rm_payments',
		'rm_receipts',
	];

	public function test_all_expected_tables_exist(): void {
		global $wpdb;

		foreach ( self::EXPECTED_TABLES as $table ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table )
			);

			$this->assertSame( $wpdb->prefix . $table, $exists, "Table {$table} was not created." );
		}
	}

	public function test_soft_deletable_tables_have_deleted_at_column(): void {
		global $wpdb;

		foreach ( self::SOFT_DELETABLE_TABLES as $table ) {
			$columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}{$table}", 0 );

			$this->assertContains( 'deleted_at', $columns, "{$table} is missing deleted_at." );
		}
	}

	public function test_financial_tables_have_no_deleted_at_column(): void {
		global $wpdb;

		foreach ( self::APPEND_ONLY_FINANCIAL_TABLES as $table ) {
			$columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}{$table}", 0 );

			$this->assertNotContains( 'deleted_at', $columns, "{$table} should be append-only, not soft-deletable." );
		}
	}
}
