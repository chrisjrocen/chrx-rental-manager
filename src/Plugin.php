<?php

declare( strict_types = 1 );

namespace ChrxRentalManager;

use ChrxRentalManager\Data\Migrator;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin container. Instantiated once on `plugins_loaded`; wires up
 * the subsystems added in later phases (admin screens, cron, portal).
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private RoleManager $role_manager;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->role_manager = new RoleManager();
	}

	public function init(): void {
		load_plugin_textdomain(
			'chrx-rental-manager',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);

		// Detects a schema/role version bump without requiring a full
		// deactivate/reactivate cycle (e.g. plugin updated in place).
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	public function maybe_upgrade(): void {
		Migrator::maybe_migrate();

		// Idempotent — add_role() no-ops if the role already exists, so
		// this is safe to run on every admin_init.
		$this->role_manager->register_roles();
	}

	public function role_manager(): RoleManager {
		return $this->role_manager;
	}
}
