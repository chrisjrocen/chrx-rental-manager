<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Data\UnitAmenity;

/**
 * SPEC.md §4.1 edge case: "Amenity tag filtering: unit list filterable by
 * occupancy type, self-contained, and any tag."
 */
final class UnitAmenityFilterTest extends IntegrationTestCase {

	private Unit $units;
	private UnitAmenity $amenities;
	private int $property_id;

	protected function setUp(): void {
		parent::setUp();

		$this->units     = new Unit();
		$this->amenities = new UnitAmenity();

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Amenity Filter Test Property', 'city' => 'Kampala' ] );
	}

	private function make_unit( array $overrides = array() ): int {
		return $this->units->insert(
			array_merge(
				array(
					'property_id'    => $this->property_id,
					'unit_label'     => 'Amenity Unit ' . wp_generate_password( 6, false ),
					'rent_amount'    => 500,
					'status'         => Unit::STATUS_VACANT,
					'occupancy_type' => Unit::OCCUPANCY_SINGLE,
					'self_contained' => 0,
					'capacity'       => 1,
				),
				$overrides
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $results
	 *
	 * @return array<int,int>
	 */
	private function ids( array $results ): array {
		return array_map( 'intval', array_column( $results, 'id' ) );
	}

	public function test_search_filters_by_occupancy_type(): void {
		$single_id = $this->make_unit( [ 'occupancy_type' => Unit::OCCUPANCY_SINGLE ] );
		$family_id = $this->make_unit( [ 'occupancy_type' => Unit::OCCUPANCY_FAMILY ] );

		$results = $this->units->search( '', '', 0, PHP_INT_MAX, 0, Unit::OCCUPANCY_FAMILY );
		$ids     = $this->ids( $results );

		$this->assertContains( $family_id, $ids );
		$this->assertNotContains( $single_id, $ids );
	}

	public function test_search_filters_by_self_contained(): void {
		$shared_id     = $this->make_unit( [ 'self_contained' => 0 ] );
		$contained_id  = $this->make_unit( [ 'self_contained' => 1 ] );

		$results = $this->units->search( '', '', 0, PHP_INT_MAX, 0, '', true );
		$ids     = $this->ids( $results );

		$this->assertContains( $contained_id, $ids );
		$this->assertNotContains( $shared_id, $ids );
	}

	public function test_search_filters_by_amenity_tag(): void {
		$with_parking_id    = $this->make_unit();
		$without_parking_id = $this->make_unit();

		$this->amenities->sync_for_unit( $with_parking_id, [ 'parking', 'balcony' ] );

		$results = $this->units->search( '', '', 0, PHP_INT_MAX, 0, '', null, 'parking' );
		$ids     = $this->ids( $results );

		$this->assertContains( $with_parking_id, $ids );
		$this->assertNotContains( $without_parking_id, $ids );
	}

	public function test_search_by_tag_does_not_duplicate_a_unit_with_multiple_matching_amenities(): void {
		$unit_id = $this->make_unit();
		$this->amenities->sync_for_unit( $unit_id, [ 'parking', 'balcony' ] );

		$results = $this->units->search( '', '', 0, PHP_INT_MAX, 0, '', null, 'parking' );
		$ids     = $this->ids( $results );

		$this->assertCount( 1, array_filter( $ids, static fn( int $id ): bool => $id === $unit_id ) );
	}

	public function test_distinct_tags_lists_every_tag_once(): void {
		$unit_a = $this->make_unit();
		$unit_b = $this->make_unit();

		$this->amenities->sync_for_unit( $unit_a, [ 'parking', 'water tank' ] );
		$this->amenities->sync_for_unit( $unit_b, [ 'parking' ] );

		$tags = $this->amenities->distinct_tags();

		$this->assertContains( 'parking', $tags );
		$this->assertContains( 'water tank', $tags );
		$this->assertCount( 1, array_filter( $tags, static fn( string $t ): bool => 'parking' === $t ) );
	}

	public function test_sync_for_unit_replaces_the_full_tag_set(): void {
		$unit_id = $this->make_unit();

		$this->amenities->sync_for_unit( $unit_id, [ 'parking', 'balcony' ] );
		$this->amenities->sync_for_unit( $unit_id, [ 'water tank' ] );

		$tags = array_column( $this->amenities->for_unit( $unit_id ), 'tag' );

		$this->assertSame( [ 'water tank' ], $tags );
	}
}
