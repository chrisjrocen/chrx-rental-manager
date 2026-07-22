<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

use ChrxRentalManager\Data\MoveOutNotice;
use ChrxRentalManager\Leases\MoveOutNoticeService;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Give/cancel move-out notice (SPEC.md §4.5/§4.10) — the tenant portal's
 * second write path (the first being Portal\PortalPayNowController from
 * V2-5), built to the exact same shape it established: admin-post.php +
 * a nonce + PortalContext::lease_belongs_to_tenant() as the ownership
 * guard, all business logic (single-active-notice constraint, earliest-
 * date math, notification dispatch) delegated to
 * Leases\MoveOutNoticeService so it stays testable without ever calling
 * this class's exit()-terminated handlers directly.
 */
final class PortalMoveOutNoticeController {

	private const GIVE_NOTICE_ACTION   = 'rm_portal_give_notice';
	private const CANCEL_NOTICE_ACTION = 'rm_portal_cancel_notice';

	private PortalContext $context;
	private MoveOutNotice $notices;
	private MoveOutNoticeService $service;

	public function __construct(
		?PortalContext $context = null,
		?MoveOutNotice $notices = null,
		?MoveOutNoticeService $service = null
	) {
		$this->context = $context ?? new PortalContext();
		$this->notices = $notices ?? new MoveOutNotice();
		$this->service = $service ?? new MoveOutNoticeService();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::GIVE_NOTICE_ACTION, array( $this, 'handle_give_notice' ) );
		add_action( 'admin_post_' . self::CANCEL_NOTICE_ACTION, array( $this, 'handle_cancel_notice' ) );
	}

	public function handle_give_notice(): void {
		if ( ! is_user_logged_in() || ! current_user_can( RoleManager::CAP_VIEW_PORTAL ) ) {
			wp_die( esc_html__( 'You must be logged in to your tenant portal to give notice.', 'chrx-rental-manager' ), 403 );
		}

		check_admin_referer( self::GIVE_NOTICE_ACTION );

		$tenant = $this->context->tenant_for_wp_user( get_current_user_id() );

		if ( null === $tenant ) {
			wp_die( esc_html__( 'No tenant account is linked to your login.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_POST['rm_lease_id'] ) ? absint( $_POST['rm_lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$requested_move_out_date = isset( $_POST['rm_requested_move_out_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_requested_move_out_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notes = isset( $_POST['rm_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_notes'] ) ) : '';

		$redirect_base = wp_get_referer();
		$redirect_base = false !== $redirect_base ? $redirect_base : home_url();

		// Ownership guard: identical shape to PortalPayNowController's —
		// a lease that isn't one of this tenant's own is "not found", not
		// merely "forbidden" (never confirms the lease id exists at all).
		if ( ! $this->context->lease_belongs_to_tenant( $lease_id, (int) $tenant['id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to give notice on this lease.', 'chrx-rental-manager' ), 403 );
		}

		$result = $this->service->submit_notice(
			$lease_id,
			MoveOutNotice::SUBMITTED_BY_TENANT,
			get_current_user_id(),
			'' !== $requested_move_out_date ? $requested_move_out_date : null,
			$notes
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'rm_view'      => PortalShortcode::VIEW_LEASE,
					'rm_notice_ok' => $result['success'] ? '1' : '0',
				),
				$redirect_base
			)
		);
		exit;
	}

	public function handle_cancel_notice(): void {
		if ( ! is_user_logged_in() || ! current_user_can( RoleManager::CAP_VIEW_PORTAL ) ) {
			wp_die( esc_html__( 'You must be logged in to your tenant portal to cancel a notice.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer() once the notice id is known.
		$notice_id = isset( $_POST['rm_notice_id'] ) ? absint( $_POST['rm_notice_id'] ) : 0;
		check_admin_referer( self::CANCEL_NOTICE_ACTION . '_' . $notice_id );

		$tenant = $this->context->tenant_for_wp_user( get_current_user_id() );

		if ( null === $tenant ) {
			wp_die( esc_html__( 'No tenant account is linked to your login.', 'chrx-rental-manager' ), 403 );
		}

		$redirect_base = wp_get_referer();
		$redirect_base = false !== $redirect_base ? $redirect_base : home_url();

		$notice_row = $this->notices->find( $notice_id );

		if ( null === $notice_row || ! $this->context->lease_belongs_to_tenant( (int) $notice_row['lease_id'], (int) $tenant['id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel this notice.', 'chrx-rental-manager' ), 403 );
		}

		$this->service->cancel_notice( $notice_id );

		wp_safe_redirect( add_query_arg( 'rm_view', PortalShortcode::VIEW_LEASE, $redirect_base ) );
		exit;
	}

	public static function give_notice_action(): string {
		return self::GIVE_NOTICE_ACTION;
	}

	public static function cancel_notice_action_for( int $notice_id ): string {
		return self::CANCEL_NOTICE_ACTION . '_' . $notice_id;
	}
}
