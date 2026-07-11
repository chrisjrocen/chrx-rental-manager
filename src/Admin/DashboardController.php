<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Reports;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared dashboard (SPEC.md §4.4, designs/04-dashboard.html +
 * designs/27-landlord-dashboard.html): one component, one query surface
 * (Support\Reports), rendered by a single template — only the data scope
 * and a couple of read-only-mode flags differ between an Admin/Staff
 * view and a Landlord-Owner view, per SPEC.md's explicit "reuse one
 * component with role-scoped queries, not two separate dashboards"
 * instruction.
 *
 * "Record Payment" in the design's header links to the Leases list
 * rather than a lease-agnostic quick-add: recording a payment always
 * requires picking a specific lease/charge first (Phase 5), and there's
 * no schema-level "which lease did they mean" shortcut from the
 * dashboard alone.
 */
final class DashboardController {

	private Reports $reports;
	private Property $properties;
	private Access $access;

	public function __construct( ?Reports $reports = null, ?Property $properties = null, ?Access $access = null ) {
		$this->reports    = $reports ?? new Reports();
		$this->properties = $properties ?? new Property();
		$this->access     = $access ?? new Access();
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view the dashboard.', 'chrx-rental-manager' ), 403 );
		}

		$user_id = get_current_user_id();

		// A Landlord-Owner never has the manage-properties capability
		// (SPEC.md §2's role table); an Administrator/Staff user does.
		// This is the one branch point the shared component needs — every
		// query below still goes through the same Reports methods either way.
		$is_landlord_view = ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) && ! current_user_can( 'manage_options' );

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( $user_id );

		$available_properties = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_values(
				array_filter(
					$this->properties->all_active(),
					fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
				)
			);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$selected_property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;

		// A selected property outside the user's accessible set is
		// silently ignored rather than trusted — this is the dashboard's
		// own belt-and-braces on top of Reports already enforcing scope,
		// since $selected_property_id came straight from the query string.
		if ( $selected_property_id > 0 && null !== $restrict_to_property_ids && ! in_array( $selected_property_id, $restrict_to_property_ids, true ) ) {
			$selected_property_id = 0;
		}

		$effective_property_ids = $selected_property_id > 0 ? array( $selected_property_id ) : $restrict_to_property_ids;

		$occupancy   = $this->reports->occupancy( $effective_property_ids );
		$outstanding = $this->reports->outstanding_summary( $effective_property_ids );
		$collected   = $this->reports->collected_this_month( $effective_property_ids );
		$expiring    = $this->reports->expiring_within( $effective_property_ids, 30 );
		$recent      = $this->reports->recent_payments( $effective_property_ids, 5 );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/dashboard.php';
	}
}
