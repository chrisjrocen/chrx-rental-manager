<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;

final class RepositoryCrudTest extends IntegrationTestCase {

	/**
	 * $wpdb rows come back with every column as a string; assertContains()
	 * compares strictly, so ids need casting before comparing against an
	 * int id.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,int>
	 */
	private function ids( array $rows ): array {
		return array_map( 'intval', array_column( $rows, 'id' ) );
	}

	public function test_insert_find_update(): void {
		$properties = new Property();

		$id = $properties->insert( [
			'name'    => 'CRUD Test Property',
			'address' => '1 Test St',
			'city'    => 'Accra',
		] );

		$this->assertIsInt( $id );

		$row = $properties->find( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 'CRUD Test Property', $row['name'] );
		$this->assertNotEmpty( $row['created_at'], 'created_at should be auto-populated.' );

		$properties->update( $id, [ 'name' => 'Renamed Property' ] );
		$row = $properties->find( $id );
		$this->assertSame( 'Renamed Property', $row['name'] );
	}

	public function test_find_returns_null_for_missing_row(): void {
		$properties = new Property();

		$this->assertNull( $properties->find( PHP_INT_MAX - 1 ) );
	}

	public function test_soft_delete_hides_from_active_and_restore_reverses_it(): void {
		$tenants = new Tenant();

		$id = $tenants->insert( [
			'full_name' => 'Archive Test Tenant',
			'status'    => Tenant::STATUS_ACTIVE,
		] );

		$this->assertFalse( $tenants->is_deleted( $id ) );

		$tenants->soft_delete( $id );

		$this->assertTrue( $tenants->is_deleted( $id ) );
		$this->assertNotContains( $id, $this->ids( $tenants->all_active() ) );
		$this->assertContains( $id, $this->ids( $tenants->all_archived() ) );

		// The row must still exist (soft delete, not hard delete).
		$this->assertNotNull( $tenants->find( $id ) );

		$tenants->restore( $id );

		$this->assertFalse( $tenants->is_deleted( $id ) );
		$this->assertContains( $id, $this->ids( $tenants->all_active() ) );
	}

	public function test_search_filters_out_soft_deleted_rows(): void {
		$tenants = new Tenant();

		$id = $tenants->insert( [
			'full_name' => 'Findable Via Search',
			'status'    => Tenant::STATUS_ACTIVE,
		] );

		$results = $tenants->search( 'Findable Via Search' );
		$this->assertContains( $id, $this->ids( $results ) );

		$tenants->soft_delete( $id );

		$results = $tenants->search( 'Findable Via Search' );
		$this->assertNotContains( $id, $this->ids( $results ) );
	}
}
