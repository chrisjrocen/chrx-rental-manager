<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around wp_mail() — the same call every v1 send-site made
 * directly; behavior for email-only recipients is byte-for-byte unchanged
 * by routing it through here (SPEC.md §10 item 12).
 */
final class EmailChannel implements MessageChannel {

	/**
	 * @param array<string,mixed> $recipient
	 * @param array<string,mixed> $payload ['subject' => string, 'body' => string, 'attachments' => array<int,string>]
	 */
	public function send( string $type, array $recipient, array $payload ): bool {
		$email = (string) ( $recipient['email'] ?? '' );

		if ( '' === $email ) {
			return false;
		}

		return (bool) wp_mail(
			$email,
			(string) ( $payload['subject'] ?? '' ),
			(string) ( $payload['body'] ?? '' ),
			array(),
			(array) ( $payload['attachments'] ?? array() )
		);
	}
}
