<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction over the Nylon Pay collection API (SPEC.md §4.9) — kept as
 * an interface, same reasoning as Communications\WhatsAppDriver: SPEC.md
 * §9 lists "additional gateway drivers behind the same abstraction" as an
 * open item, so the collect()/check_status() surface is gateway-agnostic
 * on purpose, swappable via the `chrx_rm_nylonpay_client` filter (see
 * NylonPayHttpDriver's registration in Plugin::init()), the same pattern
 * WhatsAppChannel already uses for chrx_rm_whatsapp_driver.
 *
 * No official Nylon Pay PHP SDK is depended on here (deliberate deviation
 * from SPEC.md §4.9's "official PHP SDK" wording, confirmed with the
 * user): this environment has no way to verify a third-party Composer
 * package by that description actually exists or is safe to add, so
 * NylonPayHttpDriver talks to the documented REST API directly via
 * wp_remote_post()/wp_remote_get(), the same approach already established
 * for WhatsApp's Meta Cloud API integration.
 */
interface NylonPayClient {

	/**
	 * Initiates a mobile-money collection request. The caller has already
	 * inserted the rm_gateway_transactions row (status = initiated) before
	 * calling this — SPEC.md §4.9's "the DB row exists first" write-first
	 * rule — so a failure here just leaves that row to be marked failed,
	 * never an untracked payment.
	 *
	 * @param array<string,mixed> $metadata arbitrary key/value pairs echoed
	 *                                       back on the transaction (e.g.
	 *                                       lease_id, charge_id, site_url).
	 *
	 * @return array{success:bool,failure_reason:?string}
	 */
	public function collect( string $reference, float $amount, string $currency, string $phone, string $customer_name, array $metadata ): array;

	/**
	 * Polls the status of a previously-initiated collection by its
	 * reference — the reconciliation cron's fallback when no webhook
	 * arrives (SPEC.md §4.9).
	 *
	 * @return array{status:string,failure_reason:?string} $status is one
	 *               of GatewayTransaction::STATUS_* ('processing' if
	 *               Nylon Pay reports anything this driver doesn't
	 *               recognize, so an unresolved transaction is never
	 *               mistakenly settled).
	 */
	public function check_status( string $reference ): array;
}
