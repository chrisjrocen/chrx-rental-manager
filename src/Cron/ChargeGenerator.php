<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Billing\PaymentAllocator;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `rm_generate_charges` (SPEC.md §4.2/§6, daily; renamed from
 * `rm_generate_monthly_charges` by V2-0's data migration): for every
 * active lease, creates the next period's rm_charges row a configurable
 * number of days before the period's due date (default 5) — never in
 * bulk at lease creation (that's the Admin CRUD phase's deliberate
 * deviation from the design copy, confirmed with the user: SPEC.md's
 * incremental cron model is authoritative).
 *
 * v2 (SPEC.md §4.2): period advancement now follows the lease's own
 * `cycle_months` (1/3/semester-setting/12/N) instead of always +1 month —
 * "one generalized billing engine, not per-cycle code paths." A lease
 * without a `cycle_months` value (defensive fallback only; the schema
 * default and every write path set it) behaves exactly as v1 (monthly).
 *
 * The date math (compute_next_period()) is a pure static method — no
 * $wpdb, no WP time functions inside it — so the tricky boundary cases
 * (lead-time edge, month-end billing_day clamping, lease-end clipping,
 * multi-month cycle advancement) are unit-testable without a database.
 * generate() is the thin DB-facing wrapper around it.
 */
final class ChargeGenerator {

	private Lease $leases;
	private Charge $charges;
	private PaymentAllocator $payment_allocator;

	public function __construct( ?Lease $leases = null, ?Charge $charges = null, ?PaymentAllocator $payment_allocator = null ) {
		$this->leases            = $leases ?? new Lease();
		$this->charges           = $charges ?? new Charge();
		$this->payment_allocator = $payment_allocator ?? new PaymentAllocator( null, $this->charges );
	}

	/**
	 * @return int number of charges created
	 */
	public function generate(): int {
		$today     = current_time( 'Y-m-d' ); // WP site timezone, per SPEC.md §4.2/§7.
		$lead_days = Settings::charge_lead_days();
		$created   = 0;

		foreach ( $this->leases->all_with_status( Lease::STATUS_ACTIVE ) as $lease ) {
			$existing_periods = array_map(
				static fn( array $charge ): string => $charge['period_start'],
				array_filter(
					$this->charges->for_lease( (int) $lease['id'] ),
					static fn( array $charge ): bool => Charge::TYPE_RENT === $charge['type']
				)
			);

			$period = self::compute_next_period( $lease, $existing_periods, $today, $lead_days );

			if ( null === $period ) {
				continue;
			}

			$charge_id = $this->charges->insert(
				array(
					'lease_id'        => (int) $lease['id'],
					'period_start'    => $period['period_start'],
					'period_due_date' => $period['period_due_date'],
					'amount_due'      => (float) $lease['rent_amount'],
					'type'            => Charge::TYPE_RENT,
					'status'          => Charge::STATUS_UNPAID,
				)
			);

			if ( false !== $charge_id ) {
				// SPEC.md §4.3 edge case: an earlier overpayment's excess
				// is an unallocated credit sitting on the lease — apply it
				// to this newly generated charge rather than leaving it
				// dangling until someone notices.
				$this->payment_allocator->apply_credits_to_charge( (int) $lease['id'], $charge_id );
			}

			++$created;
		}

		return $created;
	}

	/**
	 * Pure date math: given a lease, the period_start values it already
	 * has a rent charge for, today's date, and the lead-time setting,
	 * decides whether a new charge should be generated now and for which
	 * period. Returns null if nothing should be generated this run
	 * (either the next period isn't within the lead window yet, or the
	 * lease's term is already fully billed).
	 *
	 * @param array<string,mixed> $lease
	 * @param array<int,string>   $existing_period_starts Y-m-d, one per already-charged period.
	 *
	 * @return array{period_start:string,period_due_date:string}|null
	 */
	public static function compute_next_period( array $lease, array $existing_period_starts, string $today, int $lead_days ): ?array {
		$start        = new \DateTimeImmutable( $lease['start_date'] );
		$end          = new \DateTimeImmutable( $lease['end_date'] );
		$today_date   = new \DateTimeImmutable( $today );
		$billing_day  = (int) $lease['billing_day'];
		$cycle_months = max( 1, (int) ( $lease['cycle_months'] ?? 1 ) );

		// The first unbilled period is the lease's start month unless
		// charges already exist, in which case it's $cycle_months after the
		// most recently charged period (SPEC.md §4.2: "period due dates
		// advance by cycle_months from the lease start/billing_day anchor").
		if ( array() === $existing_period_starts ) {
			$period_start = $start->modify( 'first day of this month' );
		} else {
			sort( $existing_period_starts );
			$latest       = new \DateTimeImmutable( end( $existing_period_starts ) );
			$period_start = $latest->modify( "first day of +{$cycle_months} months" );
		}

		if ( $period_start > $end ) {
			return null; // Lease term is fully billed.
		}

		$due_date = self::clamp_to_month( $period_start, $billing_day );

		// Don't bill before lead_days out from the due date, but do allow
		// a same-day-or-later generation if the cron missed a run.
		$lead_window_start = $due_date->modify( "-{$lead_days} days" );

		if ( $today_date < $lead_window_start ) {
			return null;
		}

		return array(
			'period_start'    => $period_start->format( 'Y-m-d' ),
			'period_due_date' => $due_date->format( 'Y-m-d' ),
		);
	}

	/**
	 * Clamps a target day-of-month (e.g. billing_day = 31) to the last
	 * valid day of $month's actual month (e.g. 28/29/30), rather than
	 * overflowing into the next month the way DateTime::modify('+31 days')
	 * style arithmetic would.
	 */
	private static function clamp_to_month( \DateTimeImmutable $month, int $day ): \DateTimeImmutable {
		$last_day_of_month = (int) $month->format( 't' );
		$clamped_day       = min( $day, $last_day_of_month );

		return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $clamped_day );
	}
}
