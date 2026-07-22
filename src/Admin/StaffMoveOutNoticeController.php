<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\MoveOutNotice;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Leases\MoveOutNoticeService;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff walk-in move-out notice entry (SPEC.md §4.10: "staff can record
 * or cancel notices on tenants' behalf (walk-ins)") — lives on the lease
 * detail screen, mirroring Admin\SendPaymentRequestController's shape
 * (a small admin-post action, not its own list/form screen), delegating
 * all business logic to the same Leases\MoveOutNoticeService the portal
 * write path uses so a walk-in notice and a portal-submitted one are
 * indistinguishable once recorded.
 */
final class StaffMoveOutNoticeController {

	private const GIVE_NOTICE_ACTION   = 'rm_staff_give_notice';
	private const CANCEL_NOTICE_ACTION = 'rm_staff_cancel_notice';

	private Lease $leases;
	private Unit $units;
	private MoveOutNotice $notices;
	private Access $access;
	private MoveOutNoticeService $service;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?MoveOutNotice $notices = null,
		?Access $access = null,
		?MoveOutNoticeService $service = null
	) {
		$this->units   = $units ?? new Unit();
		$this->leases  = $leases ?? new Lease( $this->units );
		$this->notices = $notices ?? new MoveOutNotice();
		$this->access  = $access ?? new Access();
		$this->service = $service ?? new MoveOutNoticeService();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::GIVE_NOTICE_ACTION, array( $this, 'handle_give_notice' ) );
		add_action( 'admin_post_' . self::CANCEL_NOTICE_ACTION, array( $this, 'handle_cancel_notice' ) );
	}

	public function handle_give_notice(): void {
		check_admin_referer( self::GIVE_NOTICE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to record a move-out notice.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$requested_move_out_date = isset( $_POST['rm_requested_move_out_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_requested_move_out_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notes = isset( $_POST['rm_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_notes'] ) ) : '';

		$back_url = add_query_arg(
			array(
				'page' => LeasesController::page_slug(),
				'id'   => $lease_id,
			),
			admin_url( 'admin.php' )
		);

		$lease = $this->leases->find( $lease_id );

		if ( null === $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to record a notice on this lease.', 'chrx-rental-manager' ), 403 );
		}

		$result = $this->service->submit_notice(
			$lease_id,
			MoveOutNotice::SUBMITTED_BY_STAFF,
			get_current_user_id(),
			'' !== $requested_move_out_date ? $requested_move_out_date : null,
			$notes
		);

		FlashNotice::set(
			'leases',
			$result['success'] ? __( 'Move-out notice recorded.', 'chrx-rental-manager' ) : (string) $result['failure_reason']
		);

		wp_safe_redirect( $back_url );
		exit;
	}

	public function handle_cancel_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer() once the notice id is known.
		$notice_id = isset( $_GET['notice_id'] ) ? absint( $_GET['notice_id'] ) : 0;
		check_admin_referer( self::CANCEL_NOTICE_ACTION . '_' . $notice_id );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel a move-out notice.', 'chrx-rental-manager' ), 403 );
		}

		$notice_row = $this->notices->find( $notice_id );

		if ( null === $notice_row ) {
			wp_die( esc_html__( 'Notice not found.', 'chrx-rental-manager' ), 404 );
		}

		$lease = $this->leases->find( (int) $notice_row['lease_id'] );
		$unit  = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel this notice.', 'chrx-rental-manager' ), 403 );
		}

		$this->service->cancel_notice( $notice_id );

		FlashNotice::set( 'leases', __( 'Move-out notice cancelled.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => LeasesController::page_slug(),
					'id'   => $notice_row['lease_id'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function give_notice_action(): string {
		return self::GIVE_NOTICE_ACTION;
	}

	public static function cancel_notice_action_for( int $notice_id ): string {
		return self::CANCEL_NOTICE_ACTION . '_' . $notice_id;
	}
}
