<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Payments\GatewayPaymentService;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay Now (SPEC.md §4.5/§4.9) — the tenant portal's first write path
 * (confirmed by research: despite SPEC.md §4.5's "these are the only two
 * write paths in the portal" wording, neither Pay Now nor give-notice
 * existed in code before this phase; this establishes the pattern the
 * other one should follow). Modeled directly on
 * PortalReceiptDownload::handle()'s shape: admin-post.php (reachable from
 * the front end for logged-in users), a nonce, and
 * PortalContext::lease_belongs_to_tenant() as the ownership guard — never
 * trusting the posted charge id to belong to this tenant without checking.
 *
 * Amount is capped at the charge's outstanding balance (no overpay via
 * this self-serve path) — SPEC.md §4.3/§4.5 says partial payments are
 * allowed here, not that overpayment is; manual/staff-recorded overpayment
 * handling already exists for cash and is a deliberate scope difference
 * for the tenant-initiated flow, not an oversight.
 */
final class PortalPayNowController {

	private const NONCE_ACTION = 'rm_portal_pay_now';

	private PortalContext $context;
	private Charge $charges;
	private Ledger $ledger;
	private GatewayPaymentService $gateway;

	public function __construct(
		?PortalContext $context = null,
		?Charge $charges = null,
		?Ledger $ledger = null,
		?GatewayPaymentService $gateway = null
	) {
		$this->context = $context ?? new PortalContext();
		$this->charges = $charges ?? new Charge();
		$this->ledger  = $ledger ?? new Ledger( $this->charges );
		$this->gateway = $gateway ?? new GatewayPaymentService();
	}

	public function register(): void {
		add_action( 'admin_post_rm_portal_pay_now', array( $this, 'handle_submit' ) );
	}

	public function handle_submit(): void {
		if ( ! is_user_logged_in() || ! current_user_can( RoleManager::CAP_VIEW_PORTAL ) ) {
			wp_die( esc_html__( 'You must be logged in to your tenant portal to make a payment.', 'chrx-rental-manager' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$tenant = $this->context->tenant_for_wp_user( get_current_user_id() );

		if ( null === $tenant ) {
			wp_die( esc_html__( 'No tenant account is linked to your login.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$charge_id = isset( $_POST['rm_charge_id'] ) ? absint( $_POST['rm_charge_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$phone = isset( $_POST['rm_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_phone'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$amount = isset( $_POST['rm_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_amount'] ) ) ) : 0.0;

		$redirect_base = wp_get_referer();
		$redirect_base = false !== $redirect_base ? $redirect_base : home_url();

		$charge = $this->charges->find( $charge_id );

		// Ownership guard: a charge whose lease isn't one of this tenant's
		// own leases is treated exactly like "not found" — the same
		// ID-manipulation defense every other portal read path uses.
		if ( null === $charge || ! $this->context->lease_belongs_to_tenant( (int) $charge['lease_id'], (int) $tenant['id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to pay this charge.', 'chrx-rental-manager' ), 403 );
		}

		$outstanding = $this->ledger->outstanding_for_charge( $charge );

		if ( '' === $phone || $amount < Settings::NYLONPAY_MINIMUM_AMOUNT || $amount > $outstanding ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'rm_view'      => 'home',
						'rm_pay_error' => '1',
					),
					$redirect_base
				)
			);
			exit;
		}

		$result = $this->gateway->initiate_collection(
			(int) $charge['lease_id'],
			$charge_id,
			$amount,
			$phone,
			GatewayTransaction::INITIATED_BY_TENANT,
			get_current_user_id()
		);

		if ( ! $result['success'] ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'rm_view'      => 'home',
						'rm_pay_error' => '1',
					),
					$redirect_base
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'rm_view'    => 'home',
					'rm_pay_ref' => $result['reference'],
				),
				$redirect_base
			)
		);
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
