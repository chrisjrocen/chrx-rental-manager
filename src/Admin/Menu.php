<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level "Rental Manager" wp-admin menu. Phase 2 only adds the
 * Dashboard placeholder (fully built in the Landlord Dashboard &
 * Reporting phase) and Staff & Roles; Phase 3+ appends Properties,
 * Units, Tenants, Leases, Payments, Reports.
 */
final class Menu {

	private StaffRolesController $staff_roles_controller;

	public function __construct( ?StaffRolesController $staff_roles_controller = null ) {
		$this->staff_roles_controller = $staff_roles_controller ?? new StaffRolesController();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		$this->staff_roles_controller->register();
	}

	public function add_menu_pages(): void {
		add_menu_page(
			__( 'Rental Manager', 'chrx-rental-manager' ),
			__( 'Rental Manager', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			'chrx-rental-manager',
			array( $this, 'render_dashboard_placeholder' ),
			'dashicons-building',
			25
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Dashboard', 'chrx-rental-manager' ),
			__( 'Dashboard', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			'chrx-rental-manager',
			array( $this, 'render_dashboard_placeholder' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Staff & Roles', 'chrx-rental-manager' ),
			__( 'Staff & Roles', 'chrx-rental-manager' ),
			RoleManager::CAP_MANAGE_STAFF,
			'chrx-rm-staff-roles',
			array( $this->staff_roles_controller, 'render' )
		);
	}

	public function render_dashboard_placeholder(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Rental Manager', 'chrx-rental-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'The dashboard is built in a later phase.', 'chrx-rental-manager' ) . '</p></div>';
	}
}
