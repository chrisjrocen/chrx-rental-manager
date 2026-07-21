<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level "Rental Manager" wp-admin menu.
 */
final class Menu {

	private StaffRolesController $staff_roles_controller;
	private PropertiesController $properties_controller;
	private UnitsController $units_controller;
	private TenantsController $tenants_controller;
	private LeasesController $leases_controller;
	private DocumentsController $documents_controller;
	private PaymentsController $payments_controller;
	private DashboardController $dashboard_controller;
	private ReportsController $reports_controller;
	private StatementsController $statements_controller;
	private SettingsController $settings_controller;
	private Access $access;

	public function __construct(
		?StaffRolesController $staff_roles_controller = null,
		?PropertiesController $properties_controller = null,
		?UnitsController $units_controller = null,
		?TenantsController $tenants_controller = null,
		?LeasesController $leases_controller = null,
		?DocumentsController $documents_controller = null,
		?PaymentsController $payments_controller = null,
		?DashboardController $dashboard_controller = null,
		?ReportsController $reports_controller = null,
		?StatementsController $statements_controller = null,
		?SettingsController $settings_controller = null,
		?Access $access = null
	) {
		$this->staff_roles_controller = $staff_roles_controller ?? new StaffRolesController();
		$this->properties_controller  = $properties_controller ?? new PropertiesController();
		$this->units_controller       = $units_controller ?? new UnitsController();
		$this->tenants_controller     = $tenants_controller ?? new TenantsController();
		$this->leases_controller      = $leases_controller ?? new LeasesController();
		$this->documents_controller   = $documents_controller ?? new DocumentsController();
		$this->payments_controller    = $payments_controller ?? new PaymentsController();
		$this->dashboard_controller   = $dashboard_controller ?? new DashboardController();
		$this->reports_controller     = $reports_controller ?? new ReportsController();
		$this->statements_controller  = $statements_controller ?? new StatementsController();
		$this->settings_controller    = $settings_controller ?? new SettingsController();
		$this->access                 = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_menu', array( $this, 'hide_other_menus' ), 9999 );
		$this->staff_roles_controller->register();
		$this->properties_controller->register();
		$this->units_controller->register();
		$this->tenants_controller->register();
		$this->leases_controller->register();
		$this->documents_controller->register();
		$this->payments_controller->register();
		$this->reports_controller->register();
		$this->statements_controller->register();
		$this->settings_controller->register();
	}

	public function add_menu_pages(): void {
		add_menu_page(
			__( 'Rental Manager', 'chrx-rental-manager' ),
			__( 'Rental Manager', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			'chrx-rental-manager',
			array( $this->dashboard_controller, 'render' ),
			'dashicons-building',
			25
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Dashboard', 'chrx-rental-manager' ),
			__( 'Dashboard', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			'chrx-rental-manager',
			array( $this->dashboard_controller, 'render' )
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
			__( 'Payments', 'chrx-rental-manager' ),
			__( 'Payments', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			PaymentsController::page_slug(),
			array( $this->payments_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Reports', 'chrx-rental-manager' ),
			__( 'Reports', 'chrx-rental-manager' ),
			RoleManager::CAP_MANAGE_PROPERTIES,
			ReportsController::page_slug(),
			array( $this->reports_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Statements', 'chrx-rental-manager' ),
			__( 'Statements', 'chrx-rental-manager' ),
			RoleManager::CAP_VIEW_DASHBOARD,
			StatementsController::page_slug(),
			array( $this->statements_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Staff & Roles', 'chrx-rental-manager' ),
			__( 'Staff & Roles', 'chrx-rental-manager' ),
			RoleManager::CAP_MANAGE_STAFF,
			'chrx-rm-staff-roles',
			array( $this->staff_roles_controller, 'render' )
		);

		add_submenu_page(
			'chrx-rental-manager',
			__( 'Settings', 'chrx-rental-manager' ),
			__( 'Settings', 'chrx-rental-manager' ),
			RoleManager::CAP_MANAGE_SETTINGS,
			SettingsController::page_slug(),
			array( $this->settings_controller, 'render' )
		);
	}

	/**
	 * Hides every top-level wp-admin menu item except this plugin's own,
	 * for a fully standalone/white-labeled admin experience for non-admin
	 * roles (Staff, Landlord-Owner) — Administrators always see the full
	 * WP admin menu regardless. Admin-configurable via the Settings screen
	 * (Settings::hide_other_menus_enabled(), CAP_MANAGE_SETTINGS-gated, so
	 * only Administrators can change it). Hooked at priority 9999 so it
	 * runs after every other plugin (core included) has registered its
	 * menu items.
	 */
	public function hide_other_menus(): void {
		if ( ! Settings::hide_other_menus_enabled() ) {
			return;
		}

		if ( $this->access->is_administrator( get_current_user_id() ) ) {
			return;
		}

		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $position => $item ) {
			// $item[2] is the menu slug; separators have no slug.
			if ( isset( $item[2] ) && 'chrx-rental-manager' === $item[2] ) {
				continue;
			}

			unset( $menu[ $position ] );
		}
	}
}
