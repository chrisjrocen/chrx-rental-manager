<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Payments\GatewayPaymentService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `rm_reconcile_gateway_transactions` (SPEC.md §4.9/§6, hourly; the hook
 * itself and its hourly schedule were already wired as a no-op stub in
 * V2-0 — this class replaces the stub body). Thin wrapper around
 * GatewayPaymentService::reconcile_unresolved(), same shape as
 * ChargeGenerator/LateFeeApplier/RenewalReminder: a DB/HTTP-facing class
 * whose single public method is what Cron\Scheduler hooks the cron event to.
 */
final class GatewayReconciler {

	private GatewayPaymentService $service;

	public function __construct( ?GatewayPaymentService $service = null ) {
		$this->service = $service ?? new GatewayPaymentService();
	}

	/**
	 * @return array{settled:int,expired:int}
	 */
	public function reconcile(): array {
		return $this->service->reconcile_unresolved();
	}
}
