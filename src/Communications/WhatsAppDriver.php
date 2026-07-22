<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The actual wire-protocol client for a WhatsApp Business provider.
 * WhatsAppChannel is provider-agnostic; MetaCloudApiDriver is the only
 * implementation shipped, but SPEC.md §4.7 requires the driver layer be
 * "pluggable (filter/DI) so other providers can be added" — a future
 * Twilio/aggregator driver only needs to implement this interface and be
 * returned from the `chrx_rm_whatsapp_driver` filter.
 */
interface WhatsAppDriver {

	/**
	 * @param array<string,mixed> $variables template placeholder values, in
	 *                                        the order/shape the provider
	 *                                        expects.
	 *
	 * @return array{success:bool,failure_reason:?string}
	 */
	public function send( string $to_e164, string $template_name, array $variables ): array;
}
