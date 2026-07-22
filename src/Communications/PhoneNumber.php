<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E.164 normalization for WhatsApp numbers (SPEC.md §2: "accept local
 * formats, normalize to `256…`-style international"). Deliberately a
 * light regex-based normalizer rather than a full phone-number library —
 * this plugin has exactly one dependency (dompdf) today and the shape of
 * the problem (one configurable default country per install) doesn't
 * warrant a heavier one.
 */
final class PhoneNumber {

	/**
	 * @param string $raw            whatever the staff/tenant typed — local
	 *                                or international, with or without
	 *                                separators.
	 * @param string $default_country_code the account's default country
	 *                                       calling code (digits only, no
	 *                                       '+'), used when $raw is in local
	 *                                       format (leading 0).
	 *
	 * @return string|null E.164 digits without the leading '+' (Meta Cloud
	 *                      API's expected format), or null if $raw can't be
	 *                      confidently normalized.
	 */
	public static function normalize_e164( string $raw, string $default_country_code = '256' ): ?string {
		$digits = preg_replace( '/[^0-9]/', '', $raw ) ?? '';

		if ( '' === $digits ) {
			return null;
		}

		// Already has an explicit '+' international prefix.
		if ( str_starts_with( trim( $raw ), '+' ) ) {
			return self::valid_or_null( $digits );
		}

		// Local format: leading 0 is the trunk prefix, replaced by the
		// account's default country code (e.g. '0772...' -> '256772...').
		if ( str_starts_with( $digits, '0' ) ) {
			return self::valid_or_null( $default_country_code . substr( $digits, 1 ) );
		}

		// Already looks like it includes a country code (no leading 0,
		// longer than a bare local subscriber number).
		if ( strlen( $digits ) >= 10 ) {
			return self::valid_or_null( $digits );
		}

		return null;
	}

	private static function valid_or_null( string $digits ): ?string {
		// E.164 max length is 15 digits; anything shorter than 8 is not a
		// plausible subscriber number.
		return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? $digits : null;
	}
}
