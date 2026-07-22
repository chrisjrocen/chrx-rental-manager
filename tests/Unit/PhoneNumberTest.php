<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Communications\PhoneNumber;
use PHPUnit\Framework\TestCase;

/**
 * E.164 normalization boundary cases (SPEC.md §2). Pure — no WP/DB needed.
 */
final class PhoneNumberTest extends TestCase {

	public function test_already_international_with_plus_is_kept(): void {
		$this->assertSame( '256772123456', PhoneNumber::normalize_e164( '+256772123456' ) );
	}

	public function test_local_leading_zero_is_replaced_with_default_country_code(): void {
		$this->assertSame( '256772123456', PhoneNumber::normalize_e164( '0772123456', '256' ) );
	}

	public function test_local_format_uses_the_given_default_country_code(): void {
		$this->assertSame( '233241234567', PhoneNumber::normalize_e164( '0241234567', '233' ) );
	}

	public function test_number_already_containing_a_country_code_is_kept(): void {
		$this->assertSame( '256772123456', PhoneNumber::normalize_e164( '256772123456' ) );
	}

	public function test_separators_and_spaces_are_stripped(): void {
		$this->assertSame( '256772123456', PhoneNumber::normalize_e164( '+256 772-123-456' ) );
	}

	public function test_empty_input_returns_null(): void {
		$this->assertNull( PhoneNumber::normalize_e164( '' ) );
	}

	public function test_garbage_input_returns_null(): void {
		$this->assertNull( PhoneNumber::normalize_e164( 'not a number' ) );
	}

	public function test_too_short_local_number_returns_null(): void {
		$this->assertNull( PhoneNumber::normalize_e164( '01234' ) );
	}

	public function test_too_long_number_returns_null(): void {
		$this->assertNull( PhoneNumber::normalize_e164( '+2567721234567890123' ) );
	}
}
