<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\MoveOutNotice;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only aggregation queries for the shared dashboard component and
 * the Reports screen (SPEC.md §4.4). Every method takes the same
 * `?array<int,int> $property_ids` shape as Access::accessiblePropertyIds()
 * — null means "no restriction" (Admin), an array restricts to exactly
 * those property ids (Staff/Landlord-Owner). This is the single point
 * where "role-scoped queries, not two separate dashboards" (SPEC.md
 * §4.4's explicit instruction) is enforced: callers never filter rows
 * themselves after the fact, every method here filters at the query
 * step so a caller that forgets to pass $property_ids fails loudly
 * (null = deliberately unrestricted) rather than silently leaking data.
 */
final class Reports {

	private Property $properties;
	private Unit $units;
	private Lease $leases;
	private Charge $charges;
	private Payment $payments;
	private Tenant $tenants;
	private Expense $expenses;
	private MoveOutNotice $notices;
	private Ledger $ledger;

	public function __construct(
		?Property $properties = null,
		?Unit $units = null,
		?Lease $leases = null,
		?Charge $charges = null,
		?Payment $payments = null,
		?Tenant $tenants = null,
		?Expense $expenses = null,
		?MoveOutNotice $notices = null,
		?Ledger $ledger = null
	) {
		$this->properties = $properties ?? new Property();
		$this->units      = $units ?? new Unit();
		$this->leases     = $leases ?? new Lease();
		$this->charges    = $charges ?? new Charge();
		$this->payments   = $payments ?? new Payment();
		$this->tenants    = $tenants ?? new Tenant();
		$this->notices    = $notices ?? new MoveOutNotice();
		$this->expenses   = $expenses ?? new Expense();
		$this->ledger     = $ledger ?? new Ledger( $this->charges, $this->payments, $this->leases );
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array{occupied:int,total:int,rate:int}
	 */
	public function occupancy( ?array $property_ids ): array {
		$units    = $this->units_in_scope( $property_ids );
		$total    = count( $units );
		$occupied = count( array_filter( $units, static fn( array $u ): bool => Unit::STATUS_OCCUPIED === $u['status'] ) );

		return array(
			'occupied' => $occupied,
			'total'    => $total,
			'rate'     => $total > 0 ? (int) round( $occupied / $total * 100 ) : 0,
		);
	}

	/**
	 * v2 (SPEC.md §4.1/§4.4): "Occupancy reporting counts beds (sum of
	 * capacity) vs. filled beds (active leases), not just units" — the
	 * hostel/per-bed complement to occupancy()'s unit-count view, which
	 * SPEC.md §4.4 says stays available alongside this one, not replaced.
	 *
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array{filled:int,total:int,rate:int}
	 */
	public function occupancy_beds( ?array $property_ids ): array {
		$units  = $this->units_in_scope( $property_ids );
		$total  = array_sum( array_map( static fn( array $u ): int => max( 1, (int) $u['capacity'] ), $units ) );
		$filled = count( $this->leases_in_scope( $property_ids, Lease::STATUS_ACTIVE ) );

		return array(
			'filled' => $filled,
			'total'  => $total,
			'rate'   => $total > 0 ? (int) round( $filled / $total * 100 ) : 0,
		);
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function occupancy_by_property( ?array $property_ids ): array {
		$rows = array();

		foreach ( $this->properties_in_scope( $property_ids ) as $property ) {
			$units    = $this->units->for_property( (int) $property['id'] );
			$occupied = count( array_filter( $units, static fn( array $u ): bool => Unit::STATUS_OCCUPIED === $u['status'] ) );

			$rows[] = array(
				'property' => $property,
				'occupied' => $occupied,
				'total'    => count( $units ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array{total:float,lease_count:int,overdue_count:int}
	 */
	public function outstanding_summary( ?array $property_ids ): array {
		$total         = 0.0;
		$lease_count   = 0;
		$overdue_count = 0;

		foreach ( $this->leases_in_scope( $property_ids, Lease::STATUS_ACTIVE ) as $lease ) {
			$balance = $this->ledger->outstanding_balance_for_lease( (int) $lease['id'] );

			if ( $balance <= 0 ) {
				continue;
			}

			++$lease_count;
			$total += $balance;

			if ( $this->is_overdue( (int) $lease['id'] ) ) {
				++$overdue_count;
			}
		}

		return array(
			'total'         => $total,
			'lease_count'   => $lease_count,
			'overdue_count' => $overdue_count,
		);
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>> lease/unit/tenant/property/balance/days_overdue/status, only leases with balance > 0 as of $as_of
	 */
	public function outstanding_balances( ?array $property_ids, string $as_of ): array {
		$rows = array();

		foreach ( $this->leases_in_scope( $property_ids, Lease::STATUS_ACTIVE ) as $lease ) {
			$balance = $this->ledger->outstanding_balance_for_lease( (int) $lease['id'] );

			if ( $balance <= 0 ) {
				continue;
			}

			$due_charges = array_filter(
				$this->charges->unpaid_or_partial_for_lease( (int) $lease['id'] ),
				static fn( array $c ): bool => $c['period_due_date'] <= $as_of
			);

			if ( array() === $due_charges ) {
				continue; // Balance exists but nothing is due yet as of this date.
			}

			$earliest_due = min( array_map( static fn( array $c ): string => $c['period_due_date'], $due_charges ) );
			$days_overdue = max( 0, (int) floor( ( strtotime( $as_of ) - strtotime( $earliest_due ) ) / DAY_IN_SECONDS ) );

			$unit     = $this->units->find( (int) $lease['unit_id'] );
			$tenant   = $this->tenants->find( (int) $lease['tenant_id'] );
			$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

			$rows[] = array(
				'lease'        => $lease,
				'unit'         => $unit,
				'tenant'       => $tenant,
				'property'     => $property,
				'balance'      => $balance,
				'days_overdue' => $days_overdue,
				'status'       => $days_overdue > 0 ? 'overdue' : 'partial',
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['days_overdue'] <=> $a['days_overdue'] );

		return $rows;
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array{total:float,count:int}
	 */
	public function collected_this_month( ?array $property_ids ): array {
		$month = current_time( 'Y-m' );
		$total = 0.0;
		$count = 0;

		foreach ( $this->payments_in_scope( $property_ids ) as $payment ) {
			if ( Payment::STATUS_VOIDED === $payment['status'] ) {
				continue;
			}

			if ( gmdate( 'Y-m', strtotime( $payment['paid_at'] ) ) === $month ) {
				$total += (float) $payment['amount'];
				++$count;
			}
		}

		return array(
			'total' => $total,
			'count' => $count,
		);
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_payments( ?array $property_ids, int $limit = 5 ): array {
		return array_slice( $this->payments_in_scope( $property_ids ), 0, $limit );
	}

	/**
	 * @param array<int,int>|null $property_ids
	 * @param string              $method  '' means "any method".
	 * @param string              $as_of   Payments on/before this date only; '' means "no cutoff".
	 *
	 * @return array<int,array<string,mixed>> every payment (raw row), newest first.
	 */
	public function payments_in_scope( ?array $property_ids, string $method = '', string $as_of = '' ): array {
		return array_values(
			array_filter(
				$this->payments->all_ordered(),
				function ( array $row ) use ( $property_ids, $method, $as_of ): bool {
					$lease = $this->leases->find( (int) $row['lease_id'] );
					$unit  = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

					if ( null === $unit ) {
						return false;
					}

					if ( null !== $property_ids && ! in_array( (int) $unit['property_id'], $property_ids, true ) ) {
						return false;
					}

					if ( '' !== $method && $method !== $row['method'] ) {
						return false;
					}

					if ( '' !== $as_of && gmdate( 'Y-m-d', strtotime( $row['paid_at'] ) ) > $as_of ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * v2 (SPEC.md §4.4): "Dashboard: ... plus monthly expense total, net
	 * income figure." Non-voided expenses for the current calendar month,
	 * scoped by $property_ids via expenses_in_scope() — which excludes
	 * account-scoped rows for any restricted (Staff/Landlord) caller, per
	 * SPEC.md's "account-scoped expenses appear only on admin-level
	 * reports" rule.
	 *
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array{total:float,count:int}
	 */
	public function monthly_expense_total( ?array $property_ids ): array {
		$from = current_time( 'Y-m-01' );
		$to   = gmdate( 'Y-m-t', strtotime( $from ) );
		$rows = $this->expenses_in_scope( $property_ids, $from, $to );

		return array(
			'total' => array_sum( array_column( $rows, 'amount' ) ),
			'count' => count( $rows ),
		);
	}

	/**
	 * v2 (SPEC.md §4.4): collected-this-month minus this-month's expenses,
	 * both scoped identically via $property_ids.
	 *
	 * @param array<int,int>|null $property_ids
	 */
	public function net_income( ?array $property_ids ): float {
		return $this->collected_this_month( $property_ids )['total'] - $this->monthly_expense_total( $property_ids )['total'];
	}

	/**
	 * Non-voided expenses in [from, to], scoped by $property_ids exactly
	 * like every other method here: null = unrestricted (every scope,
	 * including account-wide), an array = restrict to those properties'
	 * own property-/unit-scoped expenses only — account-scoped rows
	 * (property_id null) are never included for a restricted caller
	 * (SPEC.md §4.4), since "allocating shared costs across owners is a
	 * business decision the software should not guess at."
	 *
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function expenses_in_scope( ?array $property_ids, string $from_date, string $to_date ): array {
		$rows = $this->expenses->for_report( $from_date, $to_date );

		if ( null === $property_ids ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static fn( array $e ): bool => null !== $e['property_id'] && in_array( (int) $e['property_id'], $property_ids, true )
			)
		);
	}

	/**
	 * v2 (SPEC.md §4.10/§5): active move-out notices, for the dashboard's
	 * flag/panel — scoped exactly like every other method here.
	 *
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function active_move_out_notices( ?array $property_ids ): array {
		$notices = $this->notices->all_active();

		if ( null === $property_ids ) {
			return $notices;
		}

		return array_values(
			array_filter(
				$notices,
				function ( array $notice_row ) use ( $property_ids ): bool {
					$lease = $this->leases->find( (int) $notice_row['lease_id'] );
					$unit  = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

					return null !== $unit && in_array( (int) $unit['property_id'], $property_ids, true );
				}
			)
		);
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>> lease rows expiring within $days, still active
	 */
	public function expiring_within( ?array $property_ids, int $days = 30 ): array {
		$rows = $this->leases->expiring_within( $days );

		if ( null === $property_ids ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				function ( array $lease ) use ( $property_ids ): bool {
					$unit = $this->units->find( (int) $lease['unit_id'] );

					return null !== $unit && in_array( (int) $unit['property_id'], $property_ids, true );
				}
			)
		);
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function leases_in_scope( ?array $property_ids, string $status ): array {
		$leases = $this->leases->all_with_status( $status );

		if ( null === $property_ids ) {
			return $leases;
		}

		return array_values(
			array_filter(
				$leases,
				function ( array $lease ) use ( $property_ids ): bool {
					$unit = $this->units->find( (int) $lease['unit_id'] );

					return null !== $unit && in_array( (int) $unit['property_id'], $property_ids, true );
				}
			)
		);
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function units_in_scope( ?array $property_ids ): array {
		$units = $this->units->all_active();

		if ( null === $property_ids ) {
			return $units;
		}

		return array_values( array_filter( $units, static fn( array $u ): bool => in_array( (int) $u['property_id'], $property_ids, true ) ) );
	}

	/**
	 * @param array<int,int>|null $property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function properties_in_scope( ?array $property_ids ): array {
		$properties = $this->properties->all_active();

		if ( null === $property_ids ) {
			return $properties;
		}

		return array_values( array_filter( $properties, static fn( array $p ): bool => in_array( (int) $p['id'], $property_ids, true ) ) );
	}

	private function is_overdue( int $lease_id ): bool {
		$today = current_time( 'Y-m-d' );

		foreach ( $this->charges->unpaid_or_partial_for_lease( $lease_id ) as $charge ) {
			if ( $charge['period_due_date'] < $today ) {
				return true;
			}
		}

		return false;
	}
}
