<?php
/**
 * Plugin Name:       Chrx Rental Manager
 * Plugin URI:        https://chrx.example.com/rental-manager
 * Description:       Standalone rental/lease management for property management companies — properties, units, tenants, leases, payments, receipts, landlord reporting, and a tenant self-service portal.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Chrx
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chrx-rental-manager
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

namespace ChrxRentalManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

const VERSION          = '0.1.0';
const PLUGIN_FILE      = __FILE__;
const PLUGIN_DIR       = __DIR__;
const PLUGIN_SLUG      = 'chrx-rental-manager';
const DB_SCHEMA_OPTION = 'chrx_rm_db_schema_version';

define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Guard against activation on unsupported PHP/WP versions.
 */
function meets_minimum_requirements(): bool {
	global $wp_version;

	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, '6.0', '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Show an admin notice and deactivate self when requirements are not met.
 */
function requirements_not_met_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Chrx Rental Manager requires PHP 8.0+ and WordPress 6.0+. The plugin has been deactivated.', 'chrx-rental-manager' );
	echo '</p></div>';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- suppressing WP core's own "Plugin activated" notice, not processing form input.
	if ( isset( $_GET['activate'] ) ) {
		unset( $_GET['activate'] );
	}
}

/**
 * Composer autoloader. vendor/ is committed to the repo so the plugin
 * works with zero build step on any host.
 */
function load_autoloader(): bool {
	$autoload = PLUGIN_DIR . '/vendor/autoload.php';

	if ( ! file_exists( $autoload ) ) {
		return false;
	}

	require_once $autoload;

	return true;
}

/**
 * Missing-vendor admin notice — surfaces the composer install requirement
 * clearly instead of a silent fatal.
 */
function vendor_missing_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Chrx Rental Manager: dependencies are missing. Run "composer install" in the plugin directory.', 'chrx-rental-manager' );
	echo '</p></div>';
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\on_activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\on_deactivate' );

/**
 * Activation: create/update the wp_rm_* tables, register the three
 * custom roles + capabilities and extend Administrator, and create the
 * front-end auth/portal pages the shortcodes need a URL for.
 */
function on_activate(): void {
	if ( ! meets_minimum_requirements() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Chrx Rental Manager requires PHP 8.0+ and WordPress 6.0+.', 'chrx-rental-manager' ),
			esc_html__( 'Plugin activation error', 'chrx-rental-manager' ),
			array( 'back_link' => true )
		);
	}

	if ( ! load_autoloader() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Chrx Rental Manager could not find its dependencies. Run "composer install" in the plugin directory before activating.', 'chrx-rental-manager' ),
			esc_html__( 'Plugin activation error', 'chrx-rental-manager' ),
			array( 'back_link' => true )
		);
	}

	Data\Migrator::migrate();

	( new Roles\RoleManager() )->register_roles();
	( new Auth\Pages() )->ensure_pages_exist();

	flush_rewrite_rules();
}

/**
 * Deactivation intentionally leaves all data intact: roles remain
 * registered and no tables/options are removed. Destructive cleanup is a
 * separate, deliberate decision that belongs in an uninstall.php (not
 * implied by deactivation) — not added in this phase.
 */
function on_deactivate(): void {
	flush_rewrite_rules();
}

/**
 * Bootstrap.
 */
function bootstrap(): void {
	if ( ! meets_minimum_requirements() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\requirements_not_met_notice' );
		return;
	}

	if ( ! load_autoloader() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\vendor_missing_notice' );
		return;
	}

	Plugin::instance()->init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'chrx-rm seed', array( new Cli\SeedCommand(), 'run' ) );
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\bootstrap' );
