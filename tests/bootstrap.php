<?php
/**
 * PHPUnit bootstrap for unit tests that don't need a full WordPress
 * environment (tests/Unit). Integration tests that need $wpdb/WP core
 * (tests/Integration) get their own bootstrap wired up in the Data Layer
 * phase once wp-phpunit test scaffolding is added.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/wp-function-stubs.php';
