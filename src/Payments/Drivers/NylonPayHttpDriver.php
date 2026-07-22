<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Payments\Drivers;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Payments\NylonPayClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Direct-HTTP Nylon Pay driver (SPEC.md §4.9) — see NylonPayClient's
 * docblock for why this talks to the REST API directly via
 * wp_remote_post()/wp_remote_get() rather than an official SDK. Endpoint
 * paths/payload shape below are a documented best-effort reconstruction
 * from SPEC.md's own description of the flow (collect-payment call with
 * amount/currency/phone/reference/metadata; a status-poll endpoint keyed
 * by reference) — not verified against Nylon Pay's live API docs, since
 * this environment has no network access to confirm them. Flagged
 * explicitly in the phase report: reconcile against
 * https://docs.nylonpay.nilesquad.com before any live/sandbox traffic.
 *
 * Follows the exact conventions MetaCloudApiDriver established as this
 * codebase's first external HTTP integration: short timeout, explicit
 * non-2xx handling, credentials read fresh from Settings on every call
 * (never cached), and secrets never included in anything logged.
 */
final class NylonPayHttpDriver implements NylonPayClient {

	private const TIMEOUT = 15;

	private const LIVE_BASE_URL    = 'https://api.nylonpay.nilesquad.com';
	private const SANDBOX_BASE_URL = 'https://sandbox.nylonpay.nilesquad.com';

	/**
	 * @param array<string,mixed> $metadata
	 *
	 * @return array{success:bool,failure_reason:?string}
	 */
	public function collect( string $reference, float $amount, string $currency, string $phone, string $customer_name, array $metadata ): array {
		if ( ! Settings::nylonpay_credentials_set() ) {
			return array(
				'success'        => false,
				'failure_reason' => 'Nylon Pay credentials are not configured.',
			);
		}

		$response = wp_remote_post(
			$this->base_url() . '/v1/collections',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->auth_headers(),
				'body'    => wp_json_encode(
					array(
						'reference'     => $reference,
						'amount'        => $amount,
						'currency'      => $currency,
						'phone'         => $phone,
						'customer_name' => $customer_name,
						'metadata'      => $metadata,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'        => false,
				'failure_reason' => $response->get_error_message(),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return array(
				'success'        => false,
				'failure_reason' => $this->error_reason_from_response( $response, $status_code ),
			);
		}

		return array(
			'success'        => true,
			'failure_reason' => null,
		);
	}

	/**
	 * @return array{status:string,failure_reason:?string}
	 */
	public function check_status( string $reference ): array {
		if ( ! Settings::nylonpay_credentials_set() ) {
			return array(
				'status'         => GatewayTransaction::STATUS_PROCESSING,
				'failure_reason' => 'Nylon Pay credentials are not configured.',
			);
		}

		$response = wp_remote_get(
			$this->base_url() . '/v1/collections/' . rawurlencode( $reference ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network/timeout failure polling status is not itself proof
			// the payment failed — leave it "processing" so the next
			// hourly sweep tries again rather than prematurely failing a
			// transaction that may yet succeed.
			return array(
				'status'         => GatewayTransaction::STATUS_PROCESSING,
				'failure_reason' => $response->get_error_message(),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return array(
				'status'         => GatewayTransaction::STATUS_PROCESSING,
				'failure_reason' => $this->error_reason_from_response( $response, $status_code ),
			);
		}

		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );
		$raw_status  = is_array( $decoded ) && isset( $decoded['status'] ) ? (string) $decoded['status'] : '';
		$known_final = array( GatewayTransaction::STATUS_SUCCESSFUL, GatewayTransaction::STATUS_FAILED, GatewayTransaction::STATUS_CANCELLED );

		return array(
			'status'         => in_array( $raw_status, $known_final, true ) ? $raw_status : GatewayTransaction::STATUS_PROCESSING,
			'failure_reason' => is_array( $decoded ) && isset( $decoded['failure_reason'] ) ? (string) $decoded['failure_reason'] : null,
		);
	}

	private function base_url(): string {
		return Settings::nylonpay_test_mode() ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
	}

	/**
	 * @return array<string,string>
	 */
	private function auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . Settings::nylonpay_api_key() . ':' . Settings::nylonpay_api_secret(),
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * @param array<string,mixed>|\WP_Error $response
	 */
	private function error_reason_from_response( $response, int $status_code ): string {
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) && isset( $decoded['message'] )
			? (string) $decoded['message']
			: "Nylon Pay API returned HTTP {$status_code}.";
	}
}
