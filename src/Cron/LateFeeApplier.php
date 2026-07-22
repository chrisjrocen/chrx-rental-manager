<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Admin\StaffRolesController;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

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
 *
 * v2 (SPEC.md §5: "Charge overdue past grace → Assigned staff → Email +
 * WhatsApp") — this notification did not exist at all in v1; it is new
 * dispatch logic, not a refactor, added right where the late-fee charge
 * that makes a period "past grace" is created, so it fires exactly once
 * per overdue period (the same dedupe boundary the fee itself uses).
 */
final class LateFeeApplier {

	private Charge $charges;
	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private PropertyStaff $property_staff;
	private Notifier $notifier;

	public function __construct(
		?Charge $charges = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?PropertyStaff $property_staff = null,
		?Notifier $notifier = null
	) {
		$this->charges        = $charges ?? new Charge();
		$this->leases         = $leases ?? new Lease();
		$this->units          = $units ?? new Unit();
		$this->tenants        = $tenants ?? new Tenant();
		$this->property_staff = $property_staff ?? new PropertyStaff();
		$this->notifier       = $notifier ?? new Notifier();
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

			$late_fee_charge_id = $this->charges->insert(
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

			if ( false !== $late_fee_charge_id ) {
				$this->notify_staff_of_overdue_charge( $lease, $charge, $fee_amount );
			}
		}

		return $created;
	}

	/**
	 * @param array<string,mixed> $lease
	 * @param array<string,mixed> $overdue_charge
	 */
	private function notify_staff_of_overdue_charge( array $lease, array $overdue_charge, float $fee_amount ): void {
		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit ) {
			return;
		}

		$tenant      = $this->tenants->find( (int) $lease['tenant_id'] );
		$tenant_name = null === $tenant ? '' : (string) $tenant['full_name'];

		$subject = sprintf(
			/* translators: 1: tenant name, 2: unit label */
			__( 'Overdue charge — %1$s, %2$s', 'chrx-rental-manager' ),
			$tenant_name,
			$unit['unit_label']
		);

		$message = sprintf(
			/* translators: 1: tenant name, 2: unit label, 3: charge amount, 4: late fee amount */
			__( "%1\$s's rent for %2\$s is overdue past the grace period. A late fee of %3\$s has been applied.", 'chrx-rental-manager' ),
			$tenant_name,
			$unit['unit_label'],
			(string) $fee_amount
		);

		foreach ( $this->property_staff->user_ids_for_property( (int) $unit['property_id'] ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user || '' === $user->user_email ) {
				continue;
			}

			$this->notifier->notify(
				'charge_overdue',
				(int) $overdue_charge['id'],
				array(
					'email'           => $user->user_email,
					'whatsapp_number' => get_user_meta( $user_id, StaffRolesController::WHATSAPP_META_KEY, true ),
				),
				$subject,
				$message,
				Settings::TEMPLATE_KEY_OVERDUE_NOTICE,
				array( $tenant_name, $unit['unit_label'], (string) $fee_amount )
			);
		}
	}
}
