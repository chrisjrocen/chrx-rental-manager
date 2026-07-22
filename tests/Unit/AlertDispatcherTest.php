<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Cron\AlertDispatcher;
use ChrxRentalManager\Data\Alert;
use PHPUnit\Framework\TestCase;

/**
 * Pure schedule due-ness math for the custom-alerts cron (SPEC.md §4.8) —
 * no database needed since AlertDispatcher::is_due()/has_occurred() take
 * "now" as a parameter, mirroring ChargeGeneratorTest's split between
 * pure date logic and the DB-facing wrapper around it.
 */
final class AlertDispatcherTest extends TestCase {

	private function alert( string $schedule_type, string $scheduled_at, ?string $last_sent_at = null ): array {
		return [
			'schedule_type' => $schedule_type,
			'scheduled_at'  => $scheduled_at,
			'last_sent_at'  => $last_sent_at,
		];
	}

	public function test_a_once_alert_is_due_after_its_scheduled_time_and_never_sent(): void {
		$alert = $this->alert( Alert::SCHEDULE_ONCE, '2026-06-01 09:00:00' );

		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-01 09:00:01' ) );
	}

	public function test_a_once_alert_is_not_due_before_its_scheduled_time(): void {
		$alert = $this->alert( Alert::SCHEDULE_ONCE, '2026-06-01 09:00:00' );

		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-01 08:59:59' ) );
	}

	public function test_a_once_alert_is_never_due_again_after_being_sent(): void {
		$alert = $this->alert( Alert::SCHEDULE_ONCE, '2026-06-01 09:00:00', '2026-06-01 09:05:00' );

		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-07-01 09:00:00' ) );
	}

	public function test_a_daily_alert_is_due_once_its_time_of_day_passes_and_not_yet_sent_today(): void {
		// last_sent_at reflects yesterday's occurrence already having been
		// sent, isolating "is today's occurrence due yet" from a brand-new
		// alert's catch-up behavior (covered separately below).
		$alert = $this->alert( Alert::SCHEDULE_DAILY, '2026-01-01 08:00:00', '2026-06-14 08:00:05' );

		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-15 08:00:01' ) );
		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-15 07:59:59' ) );
	}

	public function test_a_never_sent_daily_alert_catches_up_immediately_rather_than_waiting_for_todays_slot(): void {
		// Mirrors ChargeGenerator's "a missed run still generates late"
		// philosophy: a brand-new recurring alert whose anchor date is in
		// the past is due as soon as the cron next runs, not stuck waiting
		// for the exact time-of-day to roll around again.
		$alert = $this->alert( Alert::SCHEDULE_DAILY, '2026-01-01 08:00:00' );

		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-15 07:59:59' ) );
	}

	public function test_a_daily_alert_already_sent_today_is_not_due_again_until_tomorrow(): void {
		$alert = $this->alert( Alert::SCHEDULE_DAILY, '2026-01-01 08:00:00', '2026-06-15 08:00:05' );

		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-15 20:00:00' ) );
		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-16 08:00:01' ) );
	}

	public function test_a_weekly_alert_is_due_on_its_anchored_weekday(): void {
		// 2026-01-05 is a Monday; last_sent_at reflects the previous
		// Monday already being sent, isolating this week's due-ness.
		$alert = $this->alert( Alert::SCHEDULE_WEEKLY, '2026-01-05 10:00:00', '2026-06-08 10:00:05' );

		// 2026-06-15 is also a Monday.
		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-15 10:00:01' ) );
	}

	public function test_a_weekly_alert_is_not_due_on_a_different_weekday(): void {
		// last_sent_at reflects this week's own Monday already sent.
		$alert = $this->alert( Alert::SCHEDULE_WEEKLY, '2026-01-05 10:00:00', '2026-06-15 10:00:05' );

		// 2026-06-16 is a Tuesday, one day after that Monday's occurrence -- still not due again until next Monday.
		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-16 10:00:01' ) );
	}

	public function test_a_weekly_alert_already_sent_this_week_is_not_due_again_until_next_week(): void {
		$alert = $this->alert( Alert::SCHEDULE_WEEKLY, '2026-01-05 10:00:00', '2026-06-15 10:00:05' );

		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-17 10:00:00' ) );
		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-22 10:00:01' ) ); // The following Monday.
	}

	public function test_a_monthly_alert_is_due_on_its_anchored_day_of_month(): void {
		// last_sent_at reflects last month's 15th already being sent.
		$alert = $this->alert( Alert::SCHEDULE_MONTHLY, '2026-01-15 12:00:00', '2026-05-15 12:00:05' );

		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-06-15 12:00:01' ) );
		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-14 12:00:01' ) );
	}

	public function test_a_monthly_alert_anchored_on_the_31st_clamps_in_shorter_months(): void {
		$alert = $this->alert( Alert::SCHEDULE_MONTHLY, '2026-01-31 12:00:00' );

		// April has 30 days -- the occurrence should clamp to April 30.
		$this->assertTrue( AlertDispatcher::is_due( $alert, '2026-04-30 12:00:01' ) );
	}

	public function test_a_recurring_alert_with_no_scheduled_at_is_never_due(): void {
		$alert = [ 'schedule_type' => Alert::SCHEDULE_DAILY, 'scheduled_at' => null, 'last_sent_at' => null ];

		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-15 08:00:00' ) );
	}

	public function test_has_occurred_stays_true_after_the_alert_has_been_sent(): void {
		// Unlike is_due(), has_occurred() (used for banner display) must
		// keep reporting true after last_sent_at is stamped -- a banner
		// shouldn't vanish the instant the cron marks the alert sent.
		$alert = $this->alert( Alert::SCHEDULE_DAILY, '2026-01-01 08:00:00', '2026-06-15 08:00:05' );

		$this->assertTrue( AlertDispatcher::has_occurred( $alert, '2026-06-15 20:00:00' ) );
		$this->assertFalse( AlertDispatcher::is_due( $alert, '2026-06-15 20:00:00' ), 'Sanity check: is_due() must be false for the same alert/time.' );
	}

	public function test_has_occurred_is_false_before_the_first_occurrence(): void {
		$alert = $this->alert( Alert::SCHEDULE_ONCE, '2026-06-01 09:00:00' );

		$this->assertFalse( AlertDispatcher::has_occurred( $alert, '2026-05-31 09:00:00' ) );
	}
}
