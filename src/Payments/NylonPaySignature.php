<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nylon Pay webhook signature + freshness verification (SPEC.md §4.9/§7:
 * "requires valid HMAC signature + freshness check before any
 * processing"). A pure static class — no $wpdb, no WP function calls
 * except hash_equals()/hash_hmac() (both plain PHP) — so it's unit-
 * testable without a database, mirroring ChargeGenerator's date-math
 * split between pure logic and the DB-facing wrapper around it.
 *
 * Signature scheme (documented assumption, not verified against Nylon
 * Pay's live docs — no network access in this environment; flagged in
 * the phase report): HMAC-SHA256 of "{timestamp}.{raw_body}" keyed by the
 * webhook secret, sent as an `X-Nylonpay-Signature` header alongside an
 * `X-Nylonpay-Timestamp` header — the same "sign the timestamp plus body"
 * shape most webhook providers (Stripe, Paystack, etc.) use, applied here
 * since Nylon Pay's own docs weren't reachable to confirm the exact
 * header names/scheme. This must be reconciled against
 * https://docs.nylonpay.nilesquad.com before going live.
 */
final class NylonPaySignature {

	/**
	 * @param string $payload           the exact raw request body (must be
	 *                                   verified before any JSON decoding).
	 * @param string $signature_header  the signature Nylon Pay sent.
	 * @param string $timestamp_header  the Unix timestamp Nylon Pay sent.
	 * @param int    $max_age_seconds   replay-protection window.
	 */
	public static function verify(
		string $payload,
		string $signature_header,
		string $timestamp_header,
		string $webhook_secret,
		int $max_age_seconds = 300
	): bool {
		if ( '' === $webhook_secret || '' === $signature_header || '' === $timestamp_header ) {
			return false;
		}

		if ( ! ctype_digit( $timestamp_header ) ) {
			return false;
		}

		$timestamp = (int) $timestamp_header;

		if ( abs( time() - $timestamp ) > $max_age_seconds ) {
			return false; // Too old (or suspiciously "future") — reject as a possible replay.
		}

		$expected = hash_hmac( 'sha256', $timestamp_header . '.' . $payload, $webhook_secret );

		return hash_equals( $expected, $signature_header );
	}
}
