<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\NotificationLog;

/**
 * Regression coverage for a real bug caught during Phase 2 live testing:
 * AbstractRepository::insert() unconditionally added a created_at value,
 * but rm_notifications_log has no created_at column (it uses sent_at
 * instead) — every record() call fatally errored against the DB until
 * AbstractRepository::HAS_CREATED_AT was introduced.
 */
final class NotificationLogTest extends IntegrationTestCase {

	public function test_record_does_not_error_on_a_table_without_created_at(): void {
		$log = new NotificationLog();

		$id = $log->record( 'portal_invite', 'tenant@example.com', 123, NotificationLog::STATUS_SENT );

		$this->assertIsInt( $id );

		$row = $log->find( $id );
		$this->assertNotNull( $row );
		$this->assertArrayNotHasKey( 'created_at', $row );
		$this->assertSame( 'sent', $row['status'] );
	}

	public function test_already_sent_dedupes_by_type_and_entity(): void {
		$log = new NotificationLog();

		$this->assertFalse( $log->already_sent( 'lease_expiring_30', 456 ) );

		$log->record( 'lease_expiring_30', 'tenant@example.com', 456 );

		$this->assertTrue( $log->already_sent( 'lease_expiring_30', 456 ) );
	}
}
