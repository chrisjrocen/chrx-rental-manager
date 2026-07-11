<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\DuplicateActiveLeaseException;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click Renew (SPEC.md §4.2, designs/17-renew-lease.html): a
 * pre-filled form (same unit/tenant, carried-over rent/deposit/billing
 * day) that on confirm ends the old lease (status = renewed) and creates
 * a new rm_leases row with auto_renewed_from pointing at the old one —
 * "this linkage matters for reporting continuity" per SPEC.md §4.2, so a
 * landlord statement spanning a renewal doesn't show a gap or a fake
 * move-out. The charge-generation cron re-arms itself automatically:
 * ChargeGenerator scans all active leases on its next run, and the new
 * lease is active with no rent charges yet, so it's picked up exactly
 * like any other freshly created lease — no separate "re-arm" step needed.
 *
 * Both steps (end old lease, create new lease) run inside one DB
 * transaction: if the new lease's no-double-active-lease check somehow
 * failed (it shouldn't, since the unit's only active lease is the one
 * this request just ended, but a concurrent request could theoretically
 * create a conflicting lease in between), the old lease's status change
 * rolls back rather than leaving the unit in a lease-less state.
 */
final class LeaseRenewalController {

	private const NONCE_ACTION = 'rm_lease_renew';

	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Access $access;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?Access $access = null
	) {
		$this->units   = $units ?? new Unit();
		$this->leases  = $leases ?? new Lease( $this->units );
		$this->tenants = $tenants ?? new Tenant();
		$this->access  = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_post_rm_lease_renew', array( $this, 'handle_submit' ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	public function render_form( int $old_lease_id, ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to renew leases.', 'chrx-rental-manager' ), 403 );
		}

		$old_lease = $this->leases->find( $old_lease_id );

		if ( null === $old_lease || Lease::STATUS_ACTIVE !== $old_lease['status'] ) {
			wp_die( esc_html__( 'Only an active lease can be renewed.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $old_lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to renew this lease.', 'chrx-rental-manager' ), 403 );
		}

		$tenant = $this->tenants->find( (int) $old_lease['tenant_id'] );

		// Carried-over term: same length as the expiring lease, starting
		// the day after it ends.
		$old_start = new \DateTimeImmutable( $old_lease['start_date'] );
		$old_end   = new \DateTimeImmutable( $old_lease['end_date'] );
		$new_start = $old_end->modify( '+1 day' );
		$new_end   = $new_start->modify( '+' . $old_start->diff( $old_end )->days . ' days' );

		$list_url = add_query_arg( 'page', LeasesController::page_slug(), admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/lease-renew-form.php';
	}

	public function handle_submit(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to renew leases.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$old_lease_id = isset( $_POST['old_lease_id'] ) ? absint( $_POST['old_lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$rent_amount = isset( $_POST['rm_rent_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_rent_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$start_date = isset( $_POST['rm_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$end_date = isset( $_POST['rm_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_end_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$carry_over_deposit = ! empty( $_POST['rm_carry_over_deposit'] );

		$old_lease = $this->leases->find( $old_lease_id );

		$back_url = add_query_arg(
			array(
				'page'   => LeasesController::page_slug(),
				'action' => 'renew',
				'id'     => $old_lease_id,
			),
			admin_url( 'admin.php' )
		);

		if ( null === $old_lease || Lease::STATUS_ACTIVE !== $old_lease['status'] ) {
			wp_die( esc_html__( 'Only an active lease can be renewed.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $old_lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to renew this lease.', 'chrx-rental-manager' ), 403 );
		}

		$valid_dates = false !== strtotime( $start_date ) && false !== strtotime( $end_date ) && strtotime( $end_date ) > strtotime( $start_date );

		if ( $rent_amount <= 0 || ! $valid_dates ) {
			FlashNotice::set( 'leases', __( 'Please provide a valid rent amount and date range.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_url );
			exit;
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->leases->change_status( $old_lease_id, Lease::STATUS_RENEWED );

			$new_lease_id = $this->leases->create(
				array(
					'unit_id'           => (int) $old_lease['unit_id'],
					'tenant_id'         => (int) $old_lease['tenant_id'],
					'start_date'        => $start_date,
					'end_date'          => $end_date,
					'rent_amount'       => $rent_amount,
					'billing_day'       => (int) $old_lease['billing_day'],
					'deposit_amount'    => $carry_over_deposit ? (float) $old_lease['deposit_amount'] : 0.0,
					'deposit_status'    => $carry_over_deposit ? $old_lease['deposit_status'] : 'unpaid',
					'auto_renewed_from' => $old_lease_id,
				)
			);
		} catch ( DuplicateActiveLeaseException $e ) {
			$wpdb->query( 'ROLLBACK' );

			FlashNotice::set(
				'leases',
				sprintf(
					/* translators: %d: conflicting lease id */
					__( 'This unit already has another active lease (#%d). Renewal was not completed.', 'chrx-rental-manager' ),
					$e->conflicting_lease_id
				)
			);
			wp_safe_redirect( $back_url );
			exit;
		}

		$wpdb->query( 'COMMIT' );

		FlashNotice::set( 'leases', __( 'Lease renewed.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => LeasesController::page_slug(),
					'id'   => $new_lease_id,
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
