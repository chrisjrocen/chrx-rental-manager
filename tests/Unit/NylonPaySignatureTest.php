<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Payments\NylonPaySignature;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the Nylon Pay webhook signature + freshness check
 * (SPEC.md §4.9/§7) — no database/WP bootstrap needed since
 * NylonPaySignature::verify() only calls plain PHP hash functions.
 */
final class NylonPaySignatureTest extends TestCase {

	private function sign( string $timestamp, string $payload, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}

	public function test_a_correctly_signed_fresh_payload_verifies(): void {
		$secret    = 'test-webhook-secret';
		$payload   = '{"event":"collection.completed","reference":"abc-123"}';
		$timestamp = (string) time();
		$signature = $this->sign( $timestamp, $payload, $secret );

		$this->assertTrue( NylonPaySignature::verify( $payload, $signature, $timestamp, $secret ) );
	}

	public function test_a_tampered_payload_fails_verification(): void {
		$secret    = 'test-webhook-secret';
		$timestamp = (string) time();
		$signature = $this->sign( $timestamp, '{"event":"collection.completed","reference":"abc-123"}', $secret );

		$this->assertFalse( NylonPaySignature::verify( '{"event":"collection.completed","reference":"abc-999"}', $signature, $timestamp, $secret ) );
	}

	public function test_the_wrong_secret_fails_verification(): void {
		$timestamp = (string) time();
		$payload   = '{"event":"collection.completed","reference":"abc-123"}';
		$signature = $this->sign( $timestamp, $payload, 'correct-secret' );

		$this->assertFalse( NylonPaySignature::verify( $payload, $signature, $timestamp, 'wrong-secret' ) );
	}

	public function test_a_stale_timestamp_beyond_the_replay_window_fails(): void {
		$secret    = 'test-webhook-secret';
		$payload   = '{"event":"collection.completed","reference":"abc-123"}';
		$timestamp = (string) ( time() - 600 ); // 10 minutes old, default window is 5.
		$signature = $this->sign( $timestamp, $payload, $secret );

		$this->assertFalse( NylonPaySignature::verify( $payload, $signature, $timestamp, $secret ) );
	}

	public function test_a_timestamp_within_a_custom_window_verifies(): void {
		$secret    = 'test-webhook-secret';
		$payload   = '{"event":"collection.completed","reference":"abc-123"}';
		$timestamp = (string) ( time() - 600 );
		$signature = $this->sign( $timestamp, $payload, $secret );

		$this->assertTrue( NylonPaySignature::verify( $payload, $signature, $timestamp, $secret, 900 ) );
	}

	public function test_a_non_numeric_timestamp_fails(): void {
		$secret  = 'test-webhook-secret';
		$payload = '{"event":"collection.completed","reference":"abc-123"}';

		$this->assertFalse( NylonPaySignature::verify( $payload, 'anything', 'not-a-number', $secret ) );
	}

	public function test_empty_secret_or_headers_fail_closed(): void {
		$this->assertFalse( NylonPaySignature::verify( 'payload', 'sig', (string) time(), '' ) );
		$this->assertFalse( NylonPaySignature::verify( 'payload', '', (string) time(), 'secret' ) );
		$this->assertFalse( NylonPaySignature::verify( 'payload', 'sig', '', 'secret' ) );
	}
}
