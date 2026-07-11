<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `rm_apply_late_fees` (SPEC.md §4.3/§6, daily): scans unpaid/partial
 * charges past their grace period and applies a one-time, non-recurring
 * late fee charge — never a second fee for the same period
 * (Charge::has_late_fee_for_period() dedupes), and never re-applied to a
 * charge whose fee was later waived (waived charges are simply never
 * regenerated; SPEC.md §3/§4.3's "never truly delete, mark waived" rule
 * is enforced entirely by Charge::mark_waived(), not here).
 */
final class LateFeeApplier {

	private Charge $charges;
	private Lease $leases;

	public function __construct( ?Charge $charges = null, ?Lease $leases = null ) {
		$this->charges = $charges ?? new Charge();
		$this->leases  = $leases ?? new Lease();
	}

	/**
	 * @return int number of late fee charges created
	 */
	public function apply(): int {
		$grace_days = Settings::late_fee_grace_days();
		$created    = 0;

		foreach ( $this->charges->overdue_past_grace_period( $grace_days ) as $charge ) {
			if ( Charge::TYPE_RENT !== $charge['type'] ) {
				continue; // Late fees are only charged on overdue rent, not on a previously-applied late fee itself.
			}

			if ( $this->charges->has_late_fee_for_period( (int) $charge['lease_id'], $charge['period_start'] ) ) {
				continue;
			}

			$lease = $this->leases->find( (int) $charge['lease_id'] );

			if ( null === $lease || Lease::STATUS_ACTIVE !== $lease['status'] ) {
				continue; // Don't pile fees onto a lease that's already ended.
			}

			$fee_amount = Settings::calculate_late_fee( (float) $lease['rent_amount'] );

			$this->charges->insert(
				array(
					'lease_id'        => (int) $charge['lease_id'],
					'period_start'    => $charge['period_start'],
					'period_due_date' => $charge['period_due_date'],
					'amount_due'      => $fee_amount,
					'type'            => Charge::TYPE_LATE_FEE,
					'status'          => Charge::STATUS_UNPAID,
				)
			);

			++$created;
		}

		return $created;
	}
}
