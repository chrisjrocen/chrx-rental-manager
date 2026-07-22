<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Cron\ChargeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pure date-math boundary tests for the charge-generation cron
 * (SPEC.md §4.2) — no database needed since compute_next_period() takes
 * "today" as a parameter rather than calling current_time() itself.
 */
final class ChargeGeneratorTest extends TestCase {

	private function lease( string $start, string $end, int $billing_day, int $cycle_months = 1 ): array {
		return [
			'start_date'   => $start,
			'end_date'     => $end,
			'billing_day'  => $billing_day,
			'cycle_months' => $cycle_months,
		];
	}

	public function test_first_period_for_a_brand_new_lease_with_no_charges_yet(): void {
		$lease = $this->lease( '2026-08-01', '2027-07-31', 1 );

		$period = ChargeGenerator::compute_next_period( $lease, [], '2026-07-27', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-08-01', $period['period_start'] );
		$this->assertSame( '2026-08-01', $period['period_due_date'] );
	}

	public function test_exactly_at_the_lead_time_boundary_generates(): void {
		// Due 1 Sep 2026, lead_days = 5 -> lead window starts 27 Aug 2026.
		$lease = $this->lease( '2026-08-01', '2027-07-31', 1 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-08-01' ], '2026-08-27', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-09-01', $period['period_start'] );
	}

	public function test_one_day_before_the_lead_time_boundary_does_not_generate_yet(): void {
		$lease = $this->lease( '2026-08-01', '2027-07-31', 1 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-08-01' ], '2026-08-26', 5 );

		$this->assertNull( $period );
	}

	public function test_does_not_generate_a_period_beyond_the_lease_end_date(): void {
		$lease = $this->lease( '2026-01-01', '2026-01-31', 1 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-01-01' ], '2026-02-01', 5 );

		$this->assertNull( $period );
	}

	public function test_billing_day_31_is_clamped_to_last_day_of_a_30_day_month(): void {
		$lease = $this->lease( '2026-04-01', '2027-03-31', 31 );

		$period = ChargeGenerator::compute_next_period( $lease, [], '2026-04-25', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-04-30', $period['period_due_date'], 'April has 30 days; day 31 should clamp to the 30th.' );
	}

	public function test_billing_day_31_is_clamped_to_28_in_february_on_a_non_leap_year(): void {
		$lease = $this->lease( '2027-01-01', '2027-12-31', 31 );

		// Due date is 28 Feb 2027; lead window (5 days) opens 23 Feb.
		$period = ChargeGenerator::compute_next_period( $lease, [ '2027-01-01' ], '2027-02-24', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2027-02-28', $period['period_due_date'] );
	}

	public function test_billing_day_29_is_clamped_to_29_in_february_on_a_leap_year(): void {
		$lease = $this->lease( '2028-01-01', '2028-12-31', 29 );

		// Due date is 29 Feb 2028 (leap year); lead window (5 days) opens 24 Feb.
		$period = ChargeGenerator::compute_next_period( $lease, [ '2028-01-01' ], '2028-02-25', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2028-02-29', $period['period_due_date'] );
	}

	public function test_a_cron_run_that_was_missed_for_several_days_still_generates_late(): void {
		// Lead window opened 27 Aug but "today" is well past the due date
		// (site was offline) — should still generate rather than skip it.
		$lease = $this->lease( '2026-08-01', '2027-07-31', 1 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-08-01' ], '2026-09-10', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-09-01', $period['period_start'] );
	}

	// v2 (SPEC.md §4.2): "period due dates advance by cycle_months" —
	// one generalized billing engine for every cycle, not per-cycle code.

	public function test_quarterly_lease_advances_the_second_period_by_three_months(): void {
		$lease = $this->lease( '2026-01-01', '2027-12-31', 1, 3 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-01-01' ], '2026-04-01', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-04-01', $period['period_start'] );
	}

	public function test_semester_lease_advances_the_second_period_by_four_months(): void {
		$lease = $this->lease( '2026-01-15', '2027-12-31', 15, 4 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-01-01' ], '2026-05-10', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-05-01', $period['period_start'] );
	}

	public function test_annual_lease_advances_the_second_period_by_twelve_months(): void {
		$lease = $this->lease( '2026-06-01', '2029-05-31', 1, 12 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-06-01' ], '2027-05-27', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2027-06-01', $period['period_start'] );
	}

	public function test_custom_cycle_does_not_generate_before_its_multi_month_lead_window(): void {
		// Quarterly, billing_day 1: second period due 1 Apr 2026, lead
		// window opens 27 Mar 2026 — one day earlier must not generate.
		$lease = $this->lease( '2026-01-01', '2027-12-31', 1, 3 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-01-01' ], '2026-03-26', 5 );

		$this->assertNull( $period );
	}

	public function test_quarterly_billing_day_31_clamps_to_the_target_months_last_day(): void {
		// Anchored on Jan 31; +3 months lands in April, which only has 30 days.
		$lease = $this->lease( '2026-01-31', '2027-12-31', 31, 3 );

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-01-01' ], '2026-04-25', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-04-30', $period['period_due_date'] );
	}

	public function test_a_lease_with_no_cycle_months_key_defaults_to_monthly_for_backward_compatibility(): void {
		// Defensive fallback only — every real write path sets cycle_months,
		// but a bare array (as v1 code might still construct) must still
		// behave exactly like a monthly lease.
		$lease = [
			'start_date'  => '2026-08-01',
			'end_date'    => '2027-07-31',
			'billing_day' => 1,
		];

		$period = ChargeGenerator::compute_next_period( $lease, [ '2026-08-01' ], '2026-08-27', 5 );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-09-01', $period['period_start'] );
	}
}
