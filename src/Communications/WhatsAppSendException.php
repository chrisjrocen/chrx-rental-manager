<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by WhatsAppChannel for any failure to send (unconfigured
 * template, driver/API error). Notifier always catches this — SPEC.md
 * §4.7: a WhatsApp failure "must never block or delay the email send."
 */
final class WhatsAppSendException extends \RuntimeException {}
