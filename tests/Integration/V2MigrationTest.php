<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Migrator;

/**
 * Phase V2-0: verifies the v2 schema additions (SPEC.md §10 items 1-8) and
 * that Migrator::migrate() stays idempotent with the new tables/columns in
 * place — extends SchemaTest's pattern rather than duplicating it.
 */
final class V2MigrationTest extends IntegrationTestCase {

	private const NEW_TABLES = [
		'rm_unit_amenities',
		'rm_expenses',
		'rm_alerts',
		'rm_gateway_transactions',
		'rm_move_out_notices',
	];

	private const NEW_COLUMNS = [
		'rm_units'             => [ 'occupancy_type', 'self_contained', 'capacity' ],
		'rm_tenants'           => [ 'whatsapp_number', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship' ],
		'rm_leases'            => [ 'billing_cycle', 'cycle_months' ],
		'rm_payments'          => [ 'gateway_transaction_id' ],
		'rm_receipts'          => [ 'whatsapp_sent_at' ],
		'rm_notifications_log' => [ 'channel', 'failure_reason' ],
	];

	public function test_new_v2_tables_exist(): void {
		global $wpdb;

		foreach ( self::NEW_TABLES as $table ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table )
			);

			$this->assertSame( $wpdb->prefix . $table, $exists, "Table {$table} was not created." );
		}
	}

	public function test_new_v2_columns_exist(): void {
		global $wpdb;

		foreach ( self::NEW_COLUMNS as $table => $columns ) {
			$actual_columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}{$table}", 0 );

			foreach ( $columns as $column ) {
				$this->assertContains( $column, $actual_columns, "{$table} is missing {$column}." );
			}
		}
	}

	public function test_recorded_by_is_nullable_on_payments(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
		$row = $wpdb->get_row( "DESCRIBE {$wpdb->prefix}rm_payments recorded_by", ARRAY_A );

		$this->assertSame( 'YES', $row['Null'], 'rm_payments.recorded_by must be nullable for webhook-recorded payments (SPEC.md §10 item 4).' );
	}

	public function test_migrate_is_idempotent_with_v2_schema(): void {
		Migrator::migrate();
		Migrator::migrate();

		global $wpdb;

		foreach ( self::NEW_TABLES as $table ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table )
			);

			$this->assertSame( $wpdb->prefix . $table, $exists );
		}

		$this->assertSame( Migrator::SCHEMA_VERSION, get_option( \ChrxRentalManager\DB_SCHEMA_OPTION ) );
	}

	public function test_notifications_log_channel_column_defaults_to_email(): void {
		$notifications = new \ChrxRentalManager\Data\NotificationLog();

		$id = $notifications->insert(
			[
				'type'      => 'test_event',
				'recipient' => 'someone@example.com',
				'entity_id' => 1,
				'sent_at'   => current_time( 'mysql' ),
				'status'    => 'sent',
			]
		);

		$row = $notifications->find( $id );

		$this->assertSame( 'email', $row['channel'] );
	}
}
