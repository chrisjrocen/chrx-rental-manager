<?php

declare( strict_types = 1 );

namespace ChrxRentalManager;

use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin container. Instantiated once on `plugins_loaded`; wires up
 * the subsystems added in later phases (data layer, admin screens, cron,
 * portal). Phase 0 only wires role registration.
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

		// Keeps role capability sets current if the plugin was updated
		// in place without a full deactivate/reactivate cycle.
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	public function maybe_upgrade(): void {
		$installed = get_option( DB_SCHEMA_OPTION, '0' );

		if ( '0' === $installed ) {
			$this->role_manager->register_roles();
			update_option( DB_SCHEMA_OPTION, VERSION );
		}
	}

	public function role_manager(): RoleManager {
		return $this->role_manager;
	}
}
