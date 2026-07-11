<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Tenant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for "what tenant/lease is this portal request
 * allowed to see" (SPEC.md §4.5's server-side scoping requirement) —
 * resolved entirely from the logged-in WP user id, never from any
 * request parameter. Both PortalShortcode and PortalReceiptDownload go
 * through this class so the scoping rule can't drift between the two.
 */
final class PortalContext {

	private Tenant $tenants;
	private Lease $leases;

	public function __construct( ?Tenant $tenants = null, ?Lease $leases = null ) {
		$this->tenants = $tenants ?? new Tenant();
		$this->leases  = $leases ?? new Lease();
	}

	/**
	 * @return array<string,mixed>|null the rm_tenants row linked to this
	 *                                   WP user, or null if none exists
	 *                                   (shouldn't happen for a real
	 *                                   Tenant-role login, but a shortcode
	 *                                   rendered for any other logged-in
	 *                                   user must degrade safely).
	 */
	public function tenant_for_wp_user( int $wp_user_id ): ?array {
		return $this->tenants->find_by_wp_user_id( $wp_user_id );
	}

	/**
	 * Every lease ever tied to this tenant (across renewals), newest
	 * first — Lease::for_tenant() already scopes by tenant_id at the
	 * query layer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function leases_for_tenant( int $tenant_id ): array {
		return $this->leases->for_tenant( $tenant_id );
	}

	/**
	 * @param array<int,array<string,mixed>> $leases
	 *
	 * @return array<string,mixed>|null
	 */
	public function active_lease( array $leases ): ?array {
		foreach ( $leases as $lease ) {
			if ( Lease::STATUS_ACTIVE === $lease['status'] ) {
				return $lease;
			}
		}

		return null;
	}

	/**
	 * The soonest lease that hasn't started yet — used for the "no active
	 * lease" empty state's "Expected move-in" box (designs/33) when a
	 * tenant was invited before move-in.
	 *
	 * @param array<int,array<string,mixed>> $leases
	 *
	 * @return array<string,mixed>|null
	 */
	public function next_upcoming_lease( array $leases ): ?array {
		$today    = current_time( 'Y-m-d' );
		$upcoming = array_values( array_filter( $leases, static fn( array $l ): bool => $l['start_date'] > $today ) );

		if ( array() === $upcoming ) {
			return null;
		}

		usort( $upcoming, static fn( array $a, array $b ): int => $a['start_date'] <=> $b['start_date'] );

		return $upcoming[0];
	}

	/**
	 * Ownership check for a specific lease id against a specific tenant
	 * id — the exact guard that stops the "receipt/lease ID manipulation"
	 * attack SPEC.md's deliverable calls out. A lease belonging to
	 * another tenant must never resolve as "found" here.
	 */
	public function lease_belongs_to_tenant( int $lease_id, int $tenant_id ): bool {
		$lease = $this->leases->for_tenant( $tenant_id );

		foreach ( $lease as $row ) {
			if ( (int) $row['id'] === $lease_id ) {
				return true;
			}
		}

		return false;
	}
}
