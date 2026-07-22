<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Communications\MessageChannel;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\NotificationLog;

/**
 * Notifier's fan-out and failure-isolation logic (SPEC.md §4.7) — uses the
 * real NotificationLog (a final class, plus AbstractRepository needs a
 * live $wpdb, so this can't run as a pure tests/Unit case) with hand-rolled
 * MessageChannel test doubles standing in for EmailChannel/WhatsAppChannel,
 * so no real wp_mail()/Cloud API call happens.
 */
final class NotifierTest extends IntegrationTestCase {

	private NotificationLog $notifications;

	protected function setUp(): void {
		parent::setUp();

		$this->notifications = new NotificationLog();
	}

	public function test_email_only_recipient_only_invokes_the_email_channel(): void {
		$email_channel    = new RecordingChannel( true );
		$whatsapp_channel = new RecordingChannel( true );
		$notifier         = new Notifier( $this->notifications, $email_channel, $whatsapp_channel );

		$notifier->notify( 'test_type', 1, [ 'email' => 'tenant@example.com' ], 'Subject', 'Body' );

		$this->assertSame( 1, $email_channel->call_count );
		$this->assertSame( 0, $whatsapp_channel->call_count );
	}

	public function test_recipient_with_both_invokes_both_channels(): void {
		$email_channel    = new RecordingChannel( true );
		$whatsapp_channel = new RecordingChannel( true );
		$notifier         = new Notifier( $this->notifications, $email_channel, $whatsapp_channel );

		$notifier->notify(
			'test_type',
			1,
			[ 'email' => 'tenant@example.com', 'whatsapp_number' => '256772123456' ],
			'Subject',
			'Body'
		);

		$this->assertSame( 1, $email_channel->call_count );
		$this->assertSame( 1, $whatsapp_channel->call_count );
	}

	public function test_whatsapp_failure_does_not_prevent_the_email_result(): void {
		$email_channel    = new RecordingChannel( true );
		$whatsapp_channel = new ThrowingChannel( 'Template not approved' );
		$notifier         = new Notifier( $this->notifications, $email_channel, $whatsapp_channel );

		$result = $notifier->notify(
			'test_type',
			1,
			[ 'email' => 'tenant@example.com', 'whatsapp_number' => '256772123456' ],
			'Subject',
			'Body'
		);

		$this->assertTrue( $result, 'A WhatsApp failure must never affect the email result.' );
	}

	public function test_whatsapp_failure_is_logged_with_failure_reason_on_its_own_channel_row(): void {
		$email_channel    = new RecordingChannel( true );
		$whatsapp_channel = new ThrowingChannel( 'Template not approved' );
		$notifier         = new Notifier( $this->notifications, $email_channel, $whatsapp_channel );

		$notifier->notify(
			'test_type_wa_fail',
			42,
			[ 'email' => 'tenant@example.com', 'whatsapp_number' => '256772123456' ],
			'Subject',
			'Body'
		);

		$row = $this->find_log_row( 'test_type_wa_fail', 42, NotificationLog::CHANNEL_WHATSAPP );

		$this->assertNotNull( $row );
		$this->assertSame( NotificationLog::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Template not approved', $row['failure_reason'] );

		// The email row must still show up, unaffected, on its own channel.
		$email_row = $this->find_log_row( 'test_type_wa_fail', 42, NotificationLog::CHANNEL_EMAIL );
		$this->assertNotNull( $email_row );
		$this->assertSame( NotificationLog::STATUS_SENT, $email_row['status'] );
	}

	public function test_recipient_with_no_whatsapp_number_never_touches_the_whatsapp_channel(): void {
		$email_channel    = new RecordingChannel( true );
		$whatsapp_channel = new ThrowingChannel( 'should never be called' );
		$notifier         = new Notifier( $this->notifications, $email_channel, $whatsapp_channel );

		$notifier->notify( 'test_type_no_wa', 7, [ 'email' => 'tenant@example.com' ], 'Subject', 'Body' );

		$this->assertNull( $this->find_log_row( 'test_type_no_wa', 7, NotificationLog::CHANNEL_WHATSAPP ) );
	}

	public function test_no_email_on_file_logs_a_skip_and_returns_false(): void {
		$email_channel = new RecordingChannel( true );
		$notifier      = new Notifier( $this->notifications, $email_channel, new RecordingChannel( true ) );

		$result = $notifier->notify( 'test_type_no_email', 3, [], 'Subject', 'Body' );

		$this->assertFalse( $result );
		$this->assertSame( 0, $email_channel->call_count );

		$row = $this->find_log_row( 'test_type_no_email', 3, NotificationLog::CHANNEL_EMAIL );
		$this->assertNotNull( $row );
		$this->assertSame( NotificationLog::STATUS_SKIPPED, $row['status'] );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_log_row( string $type, int $entity_id, string $channel ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rm_notifications_log WHERE type = %s AND entity_id = %d AND channel = %s",
				$type,
				$entity_id,
				$channel
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}
}

/**
 * @internal test double
 */
final class RecordingChannel implements MessageChannel {

	public int $call_count = 0;

	public function __construct( private bool $result ) {}

	public function send( string $type, array $recipient, array $payload ): bool {
		++$this->call_count;

		return $this->result;
	}
}

/**
 * @internal test double simulating a WhatsAppSendException-style failure
 * without depending on the Communications namespace's exact exception type.
 */
final class ThrowingChannel implements MessageChannel {

	public function __construct( private string $message ) {}

	public function send( string $type, array $recipient, array $payload ): bool {
		throw new \RuntimeException( $this->message );
	}
}
