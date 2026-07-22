<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\MoveOutNotice;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Leases\MoveOutNoticeService;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move-out / termination (SPEC.md §4.2 edge cases, designs/23-move-out-termination.html):
 * move-out date, final balance calculation, deposit refund handling, unit
 * status update.
 *
 * Scope note: SPEC.md §3's schema has no dedicated table for a refund
 * payout (rm_payments only models money coming IN from a tenant), so the
 * refund itself is never persisted as a structured row — it's computed
 * for display and the fact that a settlement happened is audit-logged
 * via rm_notifications_log (type = 'move_out_settlement'). Deductions,
 * however, DO have real schema backing: rm_charges.type already includes
 * 'deposit' for exactly this (SPEC.md §3), so a non-zero deduction is
 * recorded as a genuine, paid `deposit`-type charge on the lease rather
 * than only living in a flash message — it shows up in the lease's
 * charge ledger like any other line item. deposit_status itself becomes
 * 'refunded' or 'forfeited' depending on whether anything was actually
 * left to refund. Refund method is collected for the design's sake (staff
 * need to know how they're paying it out) but isn't persisted — there's
 * no online payout in this plugin (SPEC.md §8) and no schema field for it.
 *
 * v2 (SPEC.md §4.10): this is "the existing v1 move-out workflow" the
 * move-out notice policy explicitly wires into rather than duplicating —
 * if the lease has an active notice, an early move-out (before the
 * notice's earliest_move_out_date) adds a real, genuine rent-shortfall
 * `Charge` row (Leases\MoveOutNoticeService::early_leave_shortfall()) to
 * the ledger, which staff can explicitly waive (logged, never a silent
 * edit) — never computed only for display and discarded. Completing the
 * move-out marks the notice `completed`.
 */
final class LeaseMoveOutController {

	private const NONCE_ACTION = 'rm_lease_move_out';

	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Charge $charges;
	private Ledger $ledger;
	private Access $access;
	private NotificationLog $notifications;
	private MoveOutNotice $notices;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?Charge $charges = null,
		?Ledger $ledger = null,
		?Access $access = null,
		?NotificationLog $notifications = null,
		?MoveOutNotice $notices = null
	) {
		$this->units         = $units ?? new Unit();
		$this->leases        = $leases ?? new Lease( $this->units );
		$this->tenants       = $tenants ?? new Tenant();
		$this->charges       = $charges ?? new Charge();
		$this->ledger        = $ledger ?? new Ledger();
		$this->access        = $access ?? new Access();
		$this->notifications = $notifications ?? new NotificationLog();
		$this->notices       = $notices ?? new MoveOutNotice();
	}

	public function register(): void {
		add_action( 'admin_post_rm_lease_move_out', array( $this, 'handle_submit' ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	public function render_form( int $lease_id, ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to process a move-out.', 'chrx-rental-manager' ), 403 );
		}

		$lease = $this->leases->find( $lease_id );

		if ( null === $lease || Lease::STATUS_ACTIVE !== $lease['status'] ) {
			wp_die( esc_html__( 'Only an active lease can be moved out.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to move out this lease.', 'chrx-rental-manager' ), 403 );
		}

		$tenant = $this->tenants->find( (int) $lease['tenant_id'] );

		$deposit_held     = (float) $lease['deposit_amount'];
		$outstanding_rent = $this->ledger->outstanding_balance_for_lease( $lease_id );
		$list_url         = add_query_arg( 'page', LeasesController::page_slug(), admin_url( 'admin.php' ) );

		// v2 (SPEC.md §4.10): pre-fill from the lease's active notice, if
		// any. The shortfall preview uses today's date as a stand-in move-
		// out date — handle_submit() recomputes it against whatever date
		// staff actually picks, so this preview is informational only,
		// never what's actually charged.
		$active_notice            = $this->notices->active_for_lease( $lease_id );
		$notice_shortfall_preview = null !== $active_notice
			? MoveOutNoticeService::early_leave_shortfall( $lease, current_time( 'Y-m-d' ), (string) $active_notice['earliest_move_out_date'] )
			: 0.0;

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/lease-move-out-form.php';
	}

	public function handle_submit(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to process a move-out.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$move_out_date = isset( $_POST['rm_move_out_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_move_out_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_status = isset( $_POST['rm_unit_status'] ) ? sanitize_key( wp_unslash( $_POST['rm_unit_status'] ) ) : Unit::STATUS_VACANT;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$deductions = isset( $_POST['rm_deductions'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_deductions'] ) ) ) : 0.0;
		// rm_refund_method is collected by the form (matching the design)
		// but intentionally not read here — see the class docblock: there's
		// no schema field for it and no online payout in this plugin.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$waive_shortfall = ! empty( $_POST['rm_waive_shortfall'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$waiver_reason = isset( $_POST['rm_waiver_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_waiver_reason'] ) ) : '';

		$lease = $this->leases->find( $lease_id );

		$back_url = add_query_arg(
			array(
				'page'   => LeasesController::page_slug(),
				'action' => 'move-out',
				'id'     => $lease_id,
			),
			admin_url( 'admin.php' )
		);

		if ( null === $lease || Lease::STATUS_ACTIVE !== $lease['status'] ) {
			wp_die( esc_html__( 'Only an active lease can be moved out.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to move out this lease.', 'chrx-rental-manager' ), 403 );
		}

		$valid_statuses = array( Unit::STATUS_VACANT, Unit::STATUS_MAINTENANCE, Unit::STATUS_BOOKED );

		if ( false === strtotime( $move_out_date ) || ! in_array( $unit_status, $valid_statuses, true ) || $deductions < 0 ) {
			FlashNotice::set( 'leases', __( 'Please provide a valid move-out date and settlement.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		// v2 (SPEC.md §4.10): an active notice's "financial teeth" — leaving
		// before earliest_move_out_date adds a real rent-shortfall Charge
		// row to the ledger (never only a display-time number), which
		// staff may explicitly waive here (a reason is required — logged,
		// never a silent edit, per SPEC.md §7's "corrections via
		// adjustment entries").
		$active_notice = $this->notices->active_for_lease( $lease_id );
		$shortfall     = null !== $active_notice
			? MoveOutNoticeService::early_leave_shortfall( $lease, $move_out_date, (string) $active_notice['earliest_move_out_date'] )
			: 0.0;

		if ( $shortfall > 0 && $waive_shortfall && '' === trim( $waiver_reason ) ) {
			FlashNotice::set( 'leases', __( 'Please provide a reason for waiving the notice-period shortfall.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		if ( $shortfall > 0 ) {
			$shortfall_charge_id = $this->charges->insert(
				array(
					'lease_id'        => $lease_id,
					'period_start'    => $move_out_date,
					'period_due_date' => $active_notice['earliest_move_out_date'],
					'amount_due'      => $shortfall,
					'type'            => Charge::TYPE_RENT,
					'status'          => Charge::STATUS_UNPAID,
				)
			);

			if ( $waive_shortfall && false !== $shortfall_charge_id ) {
				$this->charges->mark_waived( $shortfall_charge_id );

				// rm_charges has no reason column of its own (unlike
				// rm_expenses' void_reason) — the audit trail for this
				// adjustment lives in rm_notifications_log, the same place
				// this file already logs the settlement itself.
				$this->notifications->record(
					'move_out_notice_waiver',
					$waiver_reason,
					$lease_id,
					NotificationLog::STATUS_SENT,
					NotificationLog::CHANNEL_EMAIL,
					$waiver_reason
				);
			}
		}

		$deposit_held     = (float) $lease['deposit_amount'];
		$outstanding_rent = $this->ledger->outstanding_balance_for_lease( $lease_id );
		$refund           = $deposit_held - $outstanding_rent - $deductions;

		if ( $deductions > 0 ) {
			$this->charges->insert(
				array(
					'lease_id'        => $lease_id,
					'period_start'    => $move_out_date,
					'period_due_date' => $move_out_date,
					'amount_due'      => $deductions,
					'type'            => Charge::TYPE_DEPOSIT,
					'status'          => Charge::STATUS_PAID,
				)
			);
		}

		$this->leases->change_status( $lease_id, Lease::STATUS_ENDED );
		$this->leases->update(
			$lease_id,
			array(
				'end_date'       => $move_out_date,
				'deposit_status' => $refund > 0 ? 'refunded' : 'forfeited',
			)
		);

		// change_status() already re-derives the unit's occupied/vacant
		// state from remaining active leases; a manual maintenance/
		// booked choice here is then applied on top, same as the Units
		// screen's own manual-override mechanism.
		if ( in_array( $unit_status, array( Unit::STATUS_MAINTENANCE, Unit::STATUS_BOOKED ), true ) ) {
			$this->units->set_manual_status( (int) $lease['unit_id'], $unit_status );
		}

		if ( null !== $active_notice ) {
			$this->notices->complete( (int) $active_notice['id'] );
		}

		$tenant = $this->tenants->find( (int) $lease['tenant_id'] );

		$this->notifications->record(
			'move_out_settlement',
			null === $tenant ? '' : (string) $tenant['email'],
			$lease_id,
			NotificationLog::STATUS_SENT
		);

		FlashNotice::set(
			'leases',
			$refund > 0
				? sprintf(
					/* translators: %s: refund amount */
					__( 'Move-out complete. Refund due to tenant: %s.', 'chrx-rental-manager' ),
					\ChrxRentalManager\Admin\Support\Money::format( $refund )
				)
				: __( 'Move-out complete. Deposit fully applied to outstanding balance/deductions.', 'chrx-rental-manager' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => LeasesController::page_slug(),
					'id'   => $lease_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
