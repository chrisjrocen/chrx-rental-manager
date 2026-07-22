<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The in-portal/dashboard banner "channel" (SPEC.md §4.8: "channel
 * selection (email / WhatsApp / in-portal banner)"). Unlike Email/WhatsApp,
 * nothing is actually pushed anywhere — a banner is a query-time render of
 * due/active alerts scoped to the current viewer (see
 * Portal\PortalShortcode/Admin\DashboardController), not a delivery. send()
 * always succeeds: by the time Cron\AlertDispatcher reaches this channel,
 * the alert is already active and due, which is the only condition a
 * banner render needs — there's no separate "delivery" step that can fail.
 * It still exists as a MessageChannel implementation (rather than a
 * special-cased branch in the dispatcher) so every channel logs through
 * the exact same per-recipient/per-channel NotificationLog::record() call,
 * satisfying SPEC.md §4.8's "log per-recipient per-channel" for all three
 * channels uniformly.
 */
final class PortalChannel implements MessageChannel {

	public function send( string $type, array $recipient, array $payload ): bool {
		return true;
	}
}
