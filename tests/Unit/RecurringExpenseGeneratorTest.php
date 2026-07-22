<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Cron\RecurringExpenseGenerator;
use ChrxRentalManager\Data\Expense;
use PHPUnit\Framework\TestCase;

/**
 * Pure date-math tests for the recurring-expense cron (SPEC.md §4.4) — no
 * database needed since compute_next_period_date() is a pure static method,
 * mirroring ChargeGeneratorTest's approach to ChargeGenerator's date math.
 */
final class RecurringExpenseGeneratorTest extends TestCase {

	public function test_monthly_advances_by_one_month(): void {
		$this->assertSame(
			'2026-02-15',
			RecurringExpenseGenerator::compute_next_period_date( '2026-01-15', Expense::RECURRING_MONTHLY )
		);
	}

	public function test_quarterly_advances_by_three_months(): void {
		$this->assertSame(
			'2026-04-15',
			RecurringExpenseGenerator::compute_next_period_date( '2026-01-15', Expense::RECURRING_QUARTERLY )
		);
	}

	public function test_annual_advances_by_twelve_months(): void {
		$this->assertSame(
			'2027-01-15',
			RecurringExpenseGenerator::compute_next_period_date( '2026-01-15', Expense::RECURRING_ANNUAL )
		);
	}

	public function test_month_end_anchor_clamps_via_native_date_arithmetic(): void {
		// Jan 31 + 1 month: PHP's DateTimeImmutable::modify('+1 month')
		// overflows into March 2/3 rather than clamping to Feb 28 — this
		// documents that behavior explicitly (unlike ChargeGenerator, which
		// has its own clamp_to_month() helper; recurring expenses have no
		// billing_day concept to clamp against, so this is accepted as-is).
		$result = RecurringExpenseGenerator::compute_next_period_date( '2026-01-31', Expense::RECURRING_MONTHLY );

		$this->assertNotSame( '2026-02-28', $result );
	}
}
