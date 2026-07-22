<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Migrator;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Unit;

/**
 * SPEC.md §10 item 1: rm_units 'reserved' -> 'booked'. Simulates a v1 row
 * still carrying the old value (inserted directly via $wpdb, bypassing the
 * Unit repository's now-'booked'-only STATUS_BOOKED constant) and asserts
 * Migrator::migrate()'s data-migration step backfills it.
 */
final class BookedStatusMigrationTest extends IntegrationTestCase {

	public function test_reserved_status_is_backfilled_to_booked(): void {
		global $wpdb;

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Booked Migration Test Property', 'city' => 'Kampala' ] );

		$units   = new Unit();
		$unit_id = $units->insert(
			[
				'property_id' => $property_id,
				'unit_label'  => 'Unit R1',
				'rent_amount' => 500,
				'status'      => Unit::STATUS_VACANT,
			]
		);

		// Simulate a pre-migration v1 row: write the legacy value directly,
		// bypassing the repository (which only knows 'booked' now).
		$wpdb->update( $wpdb->prefix . 'rm_units', [ 'status' => 'reserved' ], [ 'id' => $unit_id ] );

		Migrator::migrate();

		$unit = $units->find( $unit_id );

		$this->assertSame( Unit::STATUS_BOOKED, $unit['status'] );
	}

	public function test_migration_does_not_touch_other_statuses(): void {
		global $wpdb;

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Booked Migration Test Property 2', 'city' => 'Kampala' ] );

		$units   = new Unit();
		$unit_id = $units->insert(
			[
				'property_id' => $property_id,
				'unit_label'  => 'Unit R2',
				'rent_amount' => 500,
				'status'      => Unit::STATUS_MAINTENANCE,
			]
		);

		Migrator::migrate();

		$unit = $units->find( $unit_id );

		$this->assertSame( Unit::STATUS_MAINTENANCE, $unit['status'] );
	}
}
