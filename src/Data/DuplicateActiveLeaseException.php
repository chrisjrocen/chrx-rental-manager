<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when an operation would leave a unit with two simultaneously
 * active leases (SPEC.md §4.1 edge case). Callers (admin controllers in a
 * later phase) catch this to show "blocked with a clear error naming the
 * conflicting lease" per spec, rather than letting it become a fatal.
 *
 * Not final: CapacityExceededException (v2) extends this so existing v1
 * catch sites keep working unchanged for the capacity=1 case.
 */
class DuplicateActiveLeaseException extends \RuntimeException {

	public function __construct( public readonly int $conflicting_lease_id, ?string $message = null ) {
		parent::__construct(
			$message ?? sprintf( 'Unit already has an active lease (#%d).', $conflicting_lease_id )
		);
	}
}
