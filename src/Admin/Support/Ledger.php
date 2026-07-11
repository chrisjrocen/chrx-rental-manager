<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only balance calculations shared by the Tenants/Leases list and
 * detail screens. The full billing engine (charge auto-generation, late
 * fees, overpayment credit) is the Billing/Payments phases' — this only
 * reads existing rm_charges/rm_payments rows to answer "what's owed right
 * now", which Phase 3's read-only ledger views need regardless of when
 * those rows were created.
 */
final class Ledger {

	private Charge $charges;
	private Payment $payments;
	private Lease $leases;

	public function __construct( ?Charge $charges = null, ?Payment $payments = null, ?Lease $leases = null ) {
		$this->charges  = $charges ?? new Charge();
		$this->payments = $payments ?? new Payment();
		$this->leases   = $leases ?? new Lease();
	}

	/**
	 * Sum of amount_due minus payments received, across all non-waived
	 * charges for a lease. Never negative per charge (an overpaid charge
	 * doesn't make the lease's balance go below zero here).
	 */
	public function outstanding_balance_for_lease( int $lease_id ): float {
		$balance = 0.0;

		foreach ( $this->charges->for_lease( $lease_id ) as $charge ) {
			if ( Charge::STATUS_WAIVED === $charge['status'] ) {
				continue;
			}

			$balance += $this->outstanding_for_charge( $charge );
		}

		return $balance;
	}

	public function outstanding_balance_for_tenant( int $tenant_id ): float {
		$balance = 0.0;

		foreach ( $this->leases->for_tenant( $tenant_id ) as $lease ) {
			$balance += $this->outstanding_balance_for_lease( (int) $lease['id'] );
		}

		return $balance;
	}

	public function paid_to_date_for_lease( int $lease_id ): float {
		$paid = 0.0;

		foreach ( $this->charges->for_lease( $lease_id ) as $charge ) {
			foreach ( $this->payments->for_charge( (int) $charge['id'] ) as $payment ) {
				$paid += (float) $payment['amount'];
			}
		}

		return $paid;
	}

	/**
	 * Public: the Payments phase's recording flow needs "how much is
	 * still owed on this specific charge" to compute partial/overpayment
	 * handling, not just the lease-wide total this class otherwise exposes.
	 *
	 * @param array<string,mixed> $charge
	 */
	public function outstanding_for_charge( array $charge ): float {
		$paid = 0.0;

		foreach ( $this->payments->for_charge( (int) $charge['id'] ) as $payment ) {
			$paid += (float) $payment['amount'];
		}

		return max( 0.0, (float) $charge['amount_due'] - $paid );
	}
}
