<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Leases;

use ChrxRentalManager\Admin\StaffRolesController;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\MoveOutNotice;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single place every move-out-notice flow (portal give/cancel, staff
 * walk-in entry, the existing v1 move-out workflow) goes through (SPEC.md
 * §4.10) — the notice-policy equivalent of Payments\GatewayPaymentService's
 * "one service, several callers" role from V2-5. Kept out of both
 * Portal\PortalMoveOutNoticeController and Admin\LeaseMoveOutController so
 * the earliest-date math, single-active-notice constraint, and shortfall
 * calculation are each testable directly against a real DB without ever
 * calling an admin-post handler's exit()-terminated handle_submit().
 */
final class MoveOutNoticeService {

	private MoveOutNotice $notices;
	private Lease $leases;
	private Unit $units;
	private Property $properties;
	private Tenant $tenants;
	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private Notifier $notifier;

	public function __construct(
		?MoveOutNotice $notices = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Property $properties = null,
		?Tenant $tenants = null,
		?PropertyStaff $property_staff = null,
		?PropertyLandlord $property_landlords = null,
		?Notifier $notifier = null
	) {
		$this->units              = $units ?? new Unit();
		$this->leases             = $leases ?? new Lease( $this->units );
		$this->properties         = $properties ?? new Property();
		$this->tenants            = $tenants ?? new Tenant();
		$this->notices            = $notices ?? new MoveOutNotice();
		$this->property_staff     = $property_staff ?? new PropertyStaff();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
		$this->notifier           = $notifier ?? new Notifier();
	}

	/**
	 * Pure date math (no DB): notice_date + $notice_period_months, capped
	 * at $lease_end_date — SPEC.md §4.10's "a notice never extends
	 * liability beyond the lease term" edge case.
	 */
	public static function earliest_move_out_date( string $notice_date, int $notice_period_months, string $lease_end_date ): string {
		$computed = ( new \DateTimeImmutable( $notice_date ) )->modify( '+' . max( 1, $notice_period_months ) . ' months' )->format( 'Y-m-d' );

		return $computed > $lease_end_date ? $lease_end_date : $computed;
	}

	/**
	 * Pure math: the rent shortfall from a tenant leaving before
	 * $earliest_move_out_date — the number of whole billing cycles between
	 * $move_out_date and $earliest_move_out_date, times the lease's
	 * rent_amount (SPEC.md §4.10: "the final balance includes rent up to
	 * that date — this is the policy's financial teeth"). Cycle-aware
	 * (respects the lease's own cycle_months from V2-3's flexible billing
	 * cycles) rather than assuming monthly, since a semester/annual lease's
	 * "one period" isn't a calendar month.
	 *
	 * @param array<string,mixed> $lease
	 */
	public static function early_leave_shortfall( array $lease, string $move_out_date, string $earliest_move_out_date ): float {
		if ( $move_out_date >= $earliest_move_out_date ) {
			return 0.0;
		}

		$cycle_months = max( 1, (int) ( $lease['cycle_months'] ?? 1 ) );

		$move_out = new \DateTimeImmutable( $move_out_date );
		$earliest = new \DateTimeImmutable( $earliest_move_out_date );

		$months_short = ( (int) $earliest->format( 'Y' ) - (int) $move_out->format( 'Y' ) ) * 12
			+ ( (int) $earliest->format( 'n' ) - (int) $move_out->format( 'n' ) );

		if ( $months_short <= 0 ) {
			return 0.0;
		}

		$periods = (int) ceil( $months_short / $cycle_months );

		return $periods * (float) $lease['rent_amount'];
	}

	/**
	 * @return array{success:bool,notice_id:?int,failure_reason:?string}
	 */
	public function submit_notice(
		int $lease_id,
		string $submitted_by,
		int $submitted_by_user_id,
		?string $requested_move_out_date,
		string $notes
	): array {
		$lease = $this->leases->find( $lease_id );

		if ( null === $lease || Lease::STATUS_ACTIVE !== $lease['status'] ) {
			return $this->failure( __( 'Only an active lease can have a move-out notice.', 'chrx-rental-manager' ) );
		}

		// SPEC.md §4.10 edge case: "Multiple active notices per lease:
		// blocked; cancel the existing one first."
		if ( null !== $this->notices->active_for_lease( $lease_id ) ) {
			return $this->failure( __( 'This lease already has an active move-out notice. Cancel it first.', 'chrx-rental-manager' ) );
		}

		$unit     = $this->units->find( (int) $lease['unit_id'] );
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;
		$months   = Settings::notice_period_months_for_property( $property );

		$notice_date = current_time( 'Y-m-d' );
		$earliest    = self::earliest_move_out_date( $notice_date, $months, (string) $lease['end_date'] );

		$notice_id = $this->notices->insert(
			array(
				'lease_id'                => $lease_id,
				'notice_date'             => $notice_date,
				'earliest_move_out_date'  => $earliest,
				'requested_move_out_date' => ( null !== $requested_move_out_date && '' !== $requested_move_out_date ) ? $requested_move_out_date : null,
				'submitted_by'            => $submitted_by,
				'submitted_by_user_id'    => $submitted_by_user_id,
				'status'                  => MoveOutNotice::STATUS_ACTIVE,
				'notes'                   => $notes,
			)
		);

		if ( false === $notice_id ) {
			return $this->failure( __( 'Could not save the move-out notice.', 'chrx-rental-manager' ) );
		}

		$this->notify( $lease, $unit, 'move_out_notice_submitted', $notice_id, $earliest );

		return array(
			'success'        => true,
			'notice_id'      => $notice_id,
			'failure_reason' => null,
		);
	}

	/**
	 * @return array{success:bool,failure_reason:?string}
	 */
	public function cancel_notice( int $notice_id ): array {
		$notice_row = $this->notices->find( $notice_id );

		if ( null === $notice_row || MoveOutNotice::STATUS_ACTIVE !== $notice_row['status'] ) {
			return array(
				'success'        => false,
				'failure_reason' => __( 'This notice is not active.', 'chrx-rental-manager' ),
			);
		}

		$this->notices->cancel( $notice_id );

		$lease = $this->leases->find( (int) $notice_row['lease_id'] );
		$unit  = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

		if ( null !== $lease ) {
			$this->notify( $lease, $unit, 'move_out_notice_cancelled', $notice_id, (string) $notice_row['earliest_move_out_date'] );
		}

		return array(
			'success'        => true,
			'failure_reason' => null,
		);
	}

	/**
	 * SPEC.md §5: "Move-out notice submitted/cancelled → Assigned staff,
	 * landlord-owner → Email + WhatsApp + dashboard flag." The dashboard-
	 * flag half is handled separately by DashboardController querying
	 * active notices directly; this covers email/WhatsApp only.
	 *
	 * @param array<string,mixed>  $lease
	 * @param array<string,mixed>|null $unit
	 */
	private function notify( array $lease, ?array $unit, string $type, int $notice_id, string $earliest_move_out_date ): void {
		if ( null === $unit ) {
			return;
		}

		$tenant      = $this->tenants->find( (int) $lease['tenant_id'] );
		$tenant_name = null === $tenant ? '' : (string) $tenant['full_name'];

		$subject = 'move_out_notice_submitted' === $type
			? sprintf( /* translators: 1: tenant name, 2: unit label */ __( 'Move-out notice submitted — %1$s, %2$s', 'chrx-rental-manager' ), $tenant_name, $unit['unit_label'] )
			: sprintf( /* translators: 1: tenant name, 2: unit label */ __( 'Move-out notice cancelled — %1$s, %2$s', 'chrx-rental-manager' ), $tenant_name, $unit['unit_label'] );

		$message = sprintf(
			/* translators: 1: tenant name, 2: unit label, 3: earliest move-out date */
			__( '%1$s (%2$s). Earliest move-out date: %3$s.', 'chrx-rental-manager' ),
			$tenant_name,
			$unit['unit_label'],
			$earliest_move_out_date
		);

		$recipient_user_ids = array_unique(
			array_merge(
				$this->property_staff->user_ids_for_property( (int) $unit['property_id'] ),
				$this->property_landlords->user_ids_for_property( (int) $unit['property_id'] )
			)
		);

		foreach ( $recipient_user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user || '' === $user->user_email ) {
				continue;
			}

			$this->notifier->notify(
				$type,
				$notice_id,
				array(
					'email'           => $user->user_email,
					'whatsapp_number' => get_user_meta( $user_id, StaffRolesController::WHATSAPP_META_KEY, true ),
				),
				$subject,
				$message,
				Settings::TEMPLATE_KEY_MOVE_OUT_NOTICE,
				array( $tenant_name, $unit['unit_label'], $earliest_move_out_date )
			);
		}
	}

	/**
	 * @return array{success:bool,notice_id:?int,failure_reason:?string}
	 */
	private function failure( string $reason ): array {
		return array(
			'success'        => false,
			'notice_id'      => null,
			'failure_reason' => $reason,
		);
	}
}
