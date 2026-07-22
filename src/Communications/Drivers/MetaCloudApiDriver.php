<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications\Drivers;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\WhatsAppDriver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta WhatsApp Cloud API driver (SPEC.md §4.7) — the first external HTTP
 * integration in this codebase, so there's no existing wp_remote_* wrapper
 * to follow; this establishes the pattern (short timeout, explicit non-2xx
 * handling, credentials read fresh from Settings on every call rather than
 * cached, and the access token is never included in anything logged).
 */
final class MetaCloudApiDriver implements WhatsAppDriver {

	private const API_VERSION = 'v19.0';
	private const TIMEOUT     = 10;

	/**
	 * @param array<string,mixed> $variables template placeholder values, in
	 *                                        template-body order — sent as
	 *                                        a single "body" component with
	 *                                        positional text parameters,
	 *                                        the common case for the
	 *                                        approved-template messages
	 *                                        this plugin sends.
	 *
	 * @return array{success:bool,failure_reason:?string}
	 */
	public function send( string $to_e164, string $template_name, array $variables ): array {
		$phone_number_id = Settings::whatsapp_phone_number_id();
		$token           = Settings::whatsapp_token();

		if ( '' === $phone_number_id || '' === $token ) {
			return array(
				'success'        => false,
				'failure_reason' => 'WhatsApp Cloud API credentials are not configured.',
			);
		}

		$url = sprintf( 'https://graph.facebook.com/%s/%s/messages', self::API_VERSION, rawurlencode( $phone_number_id ) );

		$components = array();

		if ( array() !== $variables ) {
			$components[] = array(
				'type'       => 'body',
				'parameters' => array_map(
					static fn( $value ): array => array(
						'type' => 'text',
						'text' => (string) $value,
					),
					array_values( $variables )
				),
			);
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'to'                => $to_e164,
			'type'              => 'template',
			'template'          => array(
				'name'       => $template_name,
				'language'   => array( 'code' => 'en_US' ),
				'components' => $components,
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
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
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$reason  = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: "WhatsApp API returned HTTP {$status_code}.";

			return array(
				'success'        => false,
				'failure_reason' => $reason,
			);
		}

		return array(
			'success'        => true,
			'failure_reason' => null,
		);
	}
}
