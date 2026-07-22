<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Communications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single delivery channel (SPEC.md §4.7: "a MessageChannel interface
 * with two implementations: EmailChannel and WhatsAppChannel"). Recipients
 * and payloads are plain arrays, matching this codebase's convention of
 * array-shape data everywhere (AbstractRepository's docblock: "keeps
 * callers from depending on a magic model class") rather than a new value
 * object hierarchy.
 */
interface MessageChannel {

	/**
	 * @param array<string,mixed> $recipient ['email' => ?string, 'whatsapp_number' => ?string]
	 * @param array<string,mixed> $payload   channel-specific: EmailChannel
	 *                                        reads 'subject'/'body'/'attachments';
	 *                                        WhatsAppChannel reads 'context'
	 *                                        (template placeholder variables).
	 *
	 * @return bool true on a confirmed send.
	 */
	public function send( string $type, array $recipient, array $payload ): bool;
}
