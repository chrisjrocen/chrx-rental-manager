<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Wraps each test in a transaction that's rolled back in tearDown(), so
 * integration tests can run against the real local dev database (there is
 * no separate WP test-suite scaffold in this project — see
 * tests/Integration/bootstrap.php) without leaving rows behind.
 *
 * wp_cache_flush() on both ends matters for anything touching
 * get_option()/update_option(): WP's object cache is not transactional,
 * so a ROLLBACK'd option write still leaves the in-memory cache believing
 * the row exists with the new value. Left unflushed, the next test's
 * update_option() call sees that stale cached value as "unchanged" and
 * silently skips writing — a real bug that cost significant debugging
 * time to trace (looked like Settings/LateFeeApplier logic being wrong;
 * it wasn't) before finding it was this test harness's isolation gap.
 */
abstract class IntegrationTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Migrator::migrate();

		wp_cache_flush();

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );

		wp_cache_flush();

		parent::tearDown();
	}
}
