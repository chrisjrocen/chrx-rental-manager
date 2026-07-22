<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Leases\MoveOutNoticeService;
use PHPUnit\Framework\TestCase;

/**
 * Pure date/money math for move-out notices (SPEC.md §4.10) — no database
 * needed since both methods take every input as a parameter, mirroring
 * ChargeGenerator's/AlertDispatcher's split between pure logic and a
 * DB-facing wrapper.
 */
final class MoveOutNoticeServiceTest extends TestCase {

	public function test_earliest_move_out_date_adds_the_notice_period(): void {
		$this->assertSame(
			'2026-08-15',
			MoveOutNoticeService::earliest_move_out_date( '2026-06-15', 2, '2027-12-31' )
		);
	}

	public function test_earliest_move_out_date_caps_at_lease_end(): void {
		// SPEC.md §4.10: "a notice never extends liability beyond the lease term."
		$this->assertSame(
			'2026-07-01',
			MoveOutNoticeService::earliest_move_out_date( '2026-06-15', 2, '2026-07-01' )
		);
	}

	public function test_earliest_move_out_date_is_unaffected_by_the_cap_when_well_within_the_lease(): void {
		$this->assertSame(
			'2026-09-15',
			MoveOutNoticeService::earliest_move_out_date( '2026-06-15', 3, '2027-01-01' )
		);
	}

	private function lease( float $rent_amount, int $cycle_months = 1 ): array {
		return [ 'rent_amount' => $rent_amount, 'cycle_months' => $cycle_months ];
	}

	public function test_early_leave_shortfall_is_zero_when_moving_out_on_or_after_the_earliest_date(): void {
		$lease = $this->lease( 1000.0 );

		$this->assertSame( 0.0, MoveOutNoticeService::early_leave_shortfall( $lease, '2026-08-15', '2026-08-15' ) );
		$this->assertSame( 0.0, MoveOutNoticeService::early_leave_shortfall( $lease, '2026-09-01', '2026-08-15' ) );
	}

	public function test_early_leave_shortfall_is_one_months_rent_for_a_one_month_early_monthly_lease(): void {
		$lease = $this->lease( 1000.0, 1 );

		$this->assertSame( 1000.0, MoveOutNoticeService::early_leave_shortfall( $lease, '2026-07-15', '2026-08-15' ) );
	}

	public function test_early_leave_shortfall_is_cycle_aware_for_a_quarterly_lease(): void {
		// A quarterly (3-month cycle) lease leaving exactly one quarter
		// early is short by exactly one quarterly period.
		$lease = $this->lease( 3000.0, 3 );

		$this->assertSame( 3000.0, MoveOutNoticeService::early_leave_shortfall( $lease, '2026-05-15', '2026-08-15' ) );
	}

	public function test_early_leave_shortfall_rounds_a_partial_cycle_up_to_a_whole_one(): void {
		// 4 months short of a quarterly (3-month) lease is more than one
		// quarter but less than two -> rounds up to 2 whole periods, not 1.
		$lease = $this->lease( 3000.0, 3 );

		$this->assertSame( 6000.0, MoveOutNoticeService::early_leave_shortfall( $lease, '2026-06-15', '2026-10-15' ) );
	}

	public function test_early_leave_shortfall_defaults_a_missing_cycle_months_to_monthly(): void {
		$lease = [ 'rent_amount' => 1000.0 ];

		$this->assertSame( 1000.0, MoveOutNoticeService::early_leave_shortfall( $lease, '2026-07-15', '2026-08-15' ) );
	}
}
