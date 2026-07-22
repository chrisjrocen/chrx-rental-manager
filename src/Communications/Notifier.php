<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

use ChrxRentalManager\Data\NotificationLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single dispatch point every notification send-site calls (SPEC.md
 * §4.7/§10 item 12: "one dispatch path, then two channels"). Sending
 * policy: email always (when the recipient has one on file); WhatsApp
 * additionally whenever the recipient has a WhatsApp number, with a
 * failure on that side never blocking or delaying the email that already
 * went out. Every send attempt — success, failure, or skip — is logged to
 * rm_notifications_log per channel.
 *
 * Callers keep the exact boolean contract every pre-v2 send-site already
 * had (wp_mail()'s return value): notify() returns the email result, not
 * a combined result, since WhatsApp is additive and none of the refactored
 * callers' existing logic (e.g. "only mark the receipt emailed if $sent")
 * should start depending on WhatsApp succeeding too.
 */
final class Notifier {

	private NotificationLog $notifications;
	private MessageChannel $email_channel;
	private MessageChannel $whatsapp_channel;

	public function __construct(
		?NotificationLog $notifications = null,
		?MessageChannel $email_channel = null,
		?MessageChannel $whatsapp_channel = null
	) {
		$this->notifications    = $notifications ?? new NotificationLog();
		$this->email_channel    = $email_channel ?? new EmailChannel();
		$this->whatsapp_channel = $whatsapp_channel ?? new WhatsAppChannel();
	}

	/**
	 * @param array<string,mixed> $recipient        ['email' => ?string, 'whatsapp_number' => ?string]
	 * @param array<int,string>   $attachments       absolute file paths, email-only.
	 * @param array<string,mixed> $whatsapp_context   template placeholder variables for the WhatsApp send.
	 *
	 * @return bool true iff the email send succeeded (WhatsApp result never affects this).
	 */
	public function notify(
		string $type,
		int $entity_id,
		array $recipient,
		string $email_subject,
		string $email_body,
		string $whatsapp_template_key = '',
		array $whatsapp_context = array(),
		array $attachments = array()
	): bool {
		$email = (string) ( $recipient['email'] ?? '' );

		if ( '' === $email ) {
			$this->notifications->record( $type, '', $entity_id, NotificationLog::STATUS_SKIPPED, NotificationLog::CHANNEL_EMAIL );
			$email_sent = false;
		} else {
			$email_sent = $this->email_channel->send(
				$type,
				$recipient,
				array(
					'subject'     => $email_subject,
					'body'        => $email_body,
					'attachments' => $attachments,
				)
			);

			$this->notifications->record(
				$type,
				$email,
				$entity_id,
				$email_sent ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
				NotificationLog::CHANNEL_EMAIL
			);
		}

		$this->dispatch_whatsapp( $type, $entity_id, $recipient, $whatsapp_template_key, $whatsapp_context );

		return $email_sent;
	}

	/**
	 * @param array<string,mixed> $recipient
	 * @param array<string,mixed> $whatsapp_context
	 */
	private function dispatch_whatsapp( string $type, int $entity_id, array $recipient, string $whatsapp_template_key, array $whatsapp_context ): void {
		$whatsapp_number = (string) ( $recipient['whatsapp_number'] ?? '' );

		if ( '' === $whatsapp_number ) {
			return; // No number on file — SPEC.md §4.7: email-only, not a failure, not logged.
		}

		try {
			$this->whatsapp_channel->send(
				$type,
				$recipient,
				array(
					'template_key' => '' !== $whatsapp_template_key ? $whatsapp_template_key : $type,
					'context'      => $whatsapp_context,
				)
			);

			$this->notifications->record( $type, $whatsapp_number, $entity_id, NotificationLog::STATUS_SENT, NotificationLog::CHANNEL_WHATSAPP );
		} catch ( \Throwable $e ) {
			// Never rethrown: a WhatsApp failure must never block or delay
			// the email path above, which has already completed by now.
			$this->notifications->record(
				$type,
				$whatsapp_number,
				$entity_id,
				NotificationLog::STATUS_FAILED,
				NotificationLog::CHANNEL_WHATSAPP,
				$e->getMessage()
			);
		}
	}
}
