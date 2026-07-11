<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level "Rental Manager" wp-admin menu. Dashboard is a placeholder
 * until the Landlord Dashboard & Reporting phase; Payments/Reports
 * submenus are added in their respective later phases.
 */
final class Menu {

	private StaffRolesController $staff_roles_controller;
	private PropertiesController $properties_controller;
	private UnitsController $units_controller;
	private TenantsController $tenants_controller;
	private LeasesController $leases_controller;
	private DocumentsController $documents_controller;

	public function __construct(
		?StaffRolesController $staff_roles_controller = null,
		?PropertiesController $properties_controller = null,
		?UnitsController $units_controller = null,
		?TenantsController $tenants_controller = null,
		?LeasesController $leases_controller = null,
		?DocumentsController $documents_controller = null
	) {
		$this->staff_roles_controller = $staff_roles_controller ?? new StaffRolesController();
		$this->properties_controller  = $properties_controller ?? new PropertiesController();
		$this->units_controller       = $units_controller ?? new UnitsController();
		$this->tenants_controller     = $tenants_controller ?? new TenantsController();
		$this->leases_controller      = $leases_controller ?? new LeasesController();
		$this->documents_controller   = $documents_controller ?? new DocumentsController();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		$this->staff_roles_controller->register();
		$this->properties_controller->register();
		$this->units_controller->register();
		$this->tenants_controller->register();
		$this->leases_controller->register();
		$this->documents_controller->register();
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
			__( 'Properties', 'chrx-rental-manager' ),
			__( 'Properties', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			PropertiesController::page_slug(),
			array( $this->properties_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Units', 'chrx-rental-manager' ),
			__( 'Units', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			UnitsController::page_slug(),
			array( $this->units_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Tenants', 'chrx-rental-manager' ),
			__( 'Tenants', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			TenantsController::page_slug(),
			array( $this->tenants_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Leases', 'chrx-rental-manager' ),
			__( 'Leases', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			LeasesController::page_slug(),
			array( $this->leases_controller, 'render' )
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
