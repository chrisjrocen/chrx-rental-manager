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
 */
abstract class IntegrationTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Migrator::migrate();

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );

		parent::tearDown();
	}
}
