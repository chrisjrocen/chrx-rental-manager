<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Payments;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Portal\PortalContext;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's first (and so far only) REST routes (SPEC.md §4.9) — no
 * existing register_rest_route()/rest_api_init usage anywhere else in
 * this codebase to follow, so this establishes the namespace constant and
 * registration pattern from scratch.
 *
 * Two routes:
 * - POST /chrx-rm/v1/nylonpay-webhook — Nylon Pay's server calls this;
 *   `permission_callback` is intentionally `__return_true` (a webhook
 *   can't send a WP nonce or authenticate as a WP user) with the HMAC
 *   signature + freshness check inside the callback acting as the actual
 *   authorization (SPEC.md §7: "webhook requires valid HMAC signature +
 *   freshness check before any processing").
 * - GET /chrx-rm/v1/gateway-transactions/{reference}/status — the
 *   portal's "poll as a fallback if no webhook within ~60s" endpoint
 *   (SPEC.md §4.5), gated by a real WP session (CAP_VIEW_PORTAL) plus an
 *   ownership check against the logged-in tenant, mirroring
 *   PortalContext::lease_belongs_to_tenant()'s pattern for the
 *   transaction's own tenant_id.
 */
final class GatewayRestController {

	public const NAMESPACE_SLUG = 'chrx-rm/v1';

	private GatewayTransaction $transactions;
	private PortalContext $context;

	public function __construct( ?GatewayTransaction $transactions = null, ?PortalContext $context = null ) {
		$this->transactions = $transactions ?? new GatewayTransaction();
		$this->context      = $context ?? new PortalContext();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_SLUG,
			'/nylonpay-webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_SLUG,
			'/gateway-transactions/(?P<reference>[A-Za-z0-9\-]+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'check_portal_permission' ),
				'args'                => array(
					'reference' => array(
						'required' => true,
					),
				),
			)
		);
	}

	public function check_portal_permission(): bool {
		return is_user_logged_in() && current_user_can( RoleManager::CAP_VIEW_PORTAL );
	}

	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body  = $request->get_body();
		$signature = (string) $request->get_header( 'X-Nylonpay-Signature' );
		$timestamp = (string) $request->get_header( 'X-Nylonpay-Timestamp' );

		if ( ! NylonPaySignature::verify( $raw_body, $signature, $timestamp, Settings::nylonpay_webhook_secret() ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) || ! isset( $payload['reference'], $payload['event'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		$transaction = $this->transactions->find_by_reference( (string) $payload['reference'] );

		if ( null === $transaction ) {
			return new \WP_REST_Response( array( 'error' => 'unknown_reference' ), 404 );
		}

		// SPEC.md §4.9: dedupe on reference — at-least-once delivery, a
		// reference already in a final state returns 200 and does nothing.
		if ( in_array( $transaction['status'], array( GatewayTransaction::STATUS_SUCCESSFUL, GatewayTransaction::STATUS_FAILED, GatewayTransaction::STATUS_CANCELLED, GatewayTransaction::STATUS_EXPIRED ), true ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// Deferred processing (SPEC.md §4.9: "returns 200 within the
		// 5-second window by deferring heavy work... to an immediately-
		// scheduled async event") — PDF generation/email sending happen
		// on the scheduled event, not on this request.
		wp_schedule_single_event(
			time(),
			'rm_process_gateway_webhook',
			array( (int) $transaction['id'], (string) $payload['event'], $raw_body )
		);

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant = $this->context->tenant_for_wp_user( get_current_user_id() );

		if ( null === $tenant ) {
			return new \WP_REST_Response( array( 'error' => 'not_a_tenant' ), 403 );
		}

		$reference   = (string) $request->get_param( 'reference' );
		$transaction = $this->transactions->find_by_reference( $reference );

		if ( null === $transaction || (int) $transaction['tenant_id'] !== (int) $tenant['id'] ) {
			// Not found and "belongs to someone else" are indistinguishable
			// on purpose — same ownership-guard shape as
			// PortalContext::lease_belongs_to_tenant()'s callers.
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		return new \WP_REST_Response(
			array(
				'status'    => $transaction['status'],
				'reference' => $transaction['reference'],
			),
			200
		);
	}
}
