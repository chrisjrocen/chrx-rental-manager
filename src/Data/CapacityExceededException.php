<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when creating/activating a lease would push a unit's active-lease
 * count past `rm_units.capacity` (SPEC.md §3.3 — the v2 generalization of
 * the v1 single-active-lease rule, which is just this invariant with
 * capacity = 1). Extends DuplicateActiveLeaseException so existing v1
 * catch sites (LeasesController, LeaseRenewalController) keep working
 * unchanged against a capacity-1 unit without modification.
 */
final class CapacityExceededException extends DuplicateActiveLeaseException {

	/** @var array<int,int> ids of the leases already occupying the unit's capacity. */
	public readonly array $conflicting_lease_ids;

	public readonly int $capacity;

	/**
	 * @param array<int,int> $conflicting_lease_ids
	 */
	public function __construct( int $unit_id, int $capacity, array $conflicting_lease_ids ) {
		parent::__construct(
			$conflicting_lease_ids[0] ?? 0,
			sprintf(
				'Unit #%d is at capacity (%d): active lease(s) #%s.',
				$unit_id,
				$capacity,
				implode( ', ', $conflicting_lease_ids )
			)
		);

		$this->conflicting_lease_ids = $conflicting_lease_ids;
		$this->capacity              = $capacity;
	}
}
