<?php
/**
 * Bootstrap for integration tests that need a real WordPress + MySQL
 * environment (table creation, $wpdb CRUD, the no-double-active-lease
 * constraint). Unlike tests/Unit, these are not runnable with zero
 * configuration — they need a working local WP install.
 *
 * Required environment variables:
 *   WP_LOAD_PATH  Absolute path to the WordPress install's wp-load.php.
 *
 * Optional:
 *   DB_SOCKET     Path to the MySQL socket, if DB_HOST in wp-config.php
 *                 doesn't resolve to the right instance by default (e.g.
 *                 multiple local sites each running their own MySQL, as
 *                 with Local by Flywheel). Set via mysqli.default_socket
 *                 before wp-load.php connects.
 *
 * Example (this project, this machine):
 *   WP_LOAD_PATH="/Users/you/Local Sites/chrx-rental-manager/app/public/wp-load.php" \
 *   DB_SOCKET="/Users/you/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock" \
 *   php vendor/bin/phpunit -c phpunit-integration.xml.dist
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../vendor/autoload.php';

$wp_load_path = getenv( 'WP_LOAD_PATH' );

if ( false === $wp_load_path || ! file_exists( $wp_load_path ) ) {
	fwrite( STDERR, "\nIntegration tests need WP_LOAD_PATH set to a real wp-load.php. See tests/Integration/bootstrap.php for details.\n\n" );
	exit( 1 );
}

$db_socket = getenv( 'DB_SOCKET' );

if ( false !== $db_socket && '' !== $db_socket ) {
	ini_set( 'mysqli.default_socket', $db_socket );
	ini_set( 'pdo_mysql.default_socket', $db_socket );
}

define( 'WP_USE_THEMES', false );
require $wp_load_path;

// Table creation is exercised by the tests themselves (Migrator::migrate()
// is idempotent), not assumed here.
