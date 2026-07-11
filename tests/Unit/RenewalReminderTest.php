<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Cron\RenewalReminder;
use PHPUnit\Framework\TestCase;

/**
 * Pure date-math boundary tests for the renewal-reminder cron
 * (SPEC.md §4.2).
 */
final class RenewalReminderTest extends TestCase {

	public function test_days_until_expiry_counts_whole_days(): void {
		$this->assertSame( 30, RenewalReminder::days_until_expiry( '2026-08-30', '2026-07-31' ) );
		$this->assertSame( 0, RenewalReminder::days_until_expiry( '2026-07-31', '2026-07-31' ) );
	}

	public function test_days_until_expiry_is_negative_after_expiry(): void {
		$this->assertSame( -1, RenewalReminder::days_until_expiry( '2026-07-30', '2026-07-31' ) );
	}

	public function test_threshold_is_due_exactly_at_the_boundary(): void {
		$this->assertTrue( RenewalReminder::is_due( 30, 30 ) );
	}

	public function test_threshold_is_not_due_one_day_before_the_boundary(): void {
		$this->assertFalse( RenewalReminder::is_due( 31, 30 ) );
	}

	public function test_threshold_stays_due_all_the_way_down_to_the_day_of_expiry(): void {
		$this->assertTrue( RenewalReminder::is_due( 0, 30 ) );
	}

	public function test_threshold_is_not_due_once_the_lease_has_already_expired(): void {
		// SPEC.md §4.2: an expired-and-not-renewed lease is the
		// dashboard's "flag it" case, not another reminder email.
		$this->assertFalse( RenewalReminder::is_due( -1, 30 ) );
	}
}
