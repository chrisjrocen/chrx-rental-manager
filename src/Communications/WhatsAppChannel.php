<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\Drivers\MetaCloudApiDriver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WhatsApp Business Cloud API channel (SPEC.md §4.7). Business-initiated
 * WhatsApp messages require pre-approved Meta templates — there is no
 * freeform body text like EmailChannel; $payload['context'] supplies the
 * template's placeholder variables and the template name itself is looked
 * up from the account's settings-screen mapping, keyed by
 * $payload['template_key'] (falls back to $type if not given).
 *
 * Every failure (missing/unconfigured template, driver/API error) throws
 * WhatsAppSendException rather than returning false — Notifier is the
 * single place that decides what "failure never blocks email" means, so
 * this class doesn't swallow errors itself.
 */
final class WhatsAppChannel implements MessageChannel {

	private WhatsAppDriver $driver;

	public function __construct( ?WhatsAppDriver $driver = null ) {
		// SPEC.md §4.7: "the driver layer is pluggable (filter/DI) so
		// Twilio or an aggregator can be added without touching calling
		// code" — the filter lets a site swap MetaCloudApiDriver for
		// another WhatsAppDriver implementation entirely.
		$this->driver = $driver ?? apply_filters( 'chrx_rm_whatsapp_driver', new MetaCloudApiDriver() );
	}

	/**
	 * @param array<string,mixed> $recipient
	 * @param array<string,mixed> $payload ['template_key' => ?string, 'context' => array<string,mixed>]
	 *
	 * @throws WhatsAppSendException
	 */
	public function send( string $type, array $recipient, array $payload ): bool {
		$to = (string) ( $recipient['whatsapp_number'] ?? '' );

		if ( '' === $to ) {
			throw new WhatsAppSendException( 'No WhatsApp number on file for this recipient.' );
		}

		$template_key  = (string) ( $payload['template_key'] ?? $type );
		$template_name = Settings::whatsapp_template_name( $template_key );

		if ( '' === $template_name ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message (caught and logged by Notifier), never echoed to a browser.
			throw new WhatsAppSendException( "No WhatsApp template configured for '{$template_key}'." );
		}

		if ( ! Settings::whatsapp_is_configured() ) {
			throw new WhatsAppSendException( 'WhatsApp Cloud API credentials are not configured.' );
		}

		$result = $this->driver->send( $to, $template_name, (array) ( $payload['context'] ?? array() ) );

		if ( ! $result['success'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message (caught and logged by Notifier), never echoed to a browser.
			throw new WhatsAppSendException( $result['failure_reason'] ?? 'Unknown WhatsApp send failure.' );
		}

		return true;
	}
}
