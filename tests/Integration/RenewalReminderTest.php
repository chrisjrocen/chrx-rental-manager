<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Cron\RenewalReminder;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\RoleManager;

/**
 * `rm_send_renewal_reminders` (SPEC.md §4.2) — dedup-per-threshold and the
 * same-day-renewal suppression, against real DB rows.
 */
final class RenewalReminderTest extends IntegrationTestCase {

	private Lease $leases;
	private NotificationLog $notifications;
	private RenewalReminder $reminder;
	private int $lease_id;
	private int $staff_user_id;

	protected function setUp(): void {
		parent::setUp();

		update_option( Settings::OPT_REMINDER_THRESHOLDS, [ 30, 14, 7 ] );
		update_option( Settings::OPT_REMINDER_NOTIFY_TENANT, false );

		$this->leases        = new Lease();
		$this->notifications = new NotificationLog();
		$this->reminder      = new RenewalReminder();

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Reminder Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'RR1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$this->staff_user_id = wp_insert_user( [
			'user_login' => 'reminder_staff_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'staff_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_STAFF,
		] );

		( new PropertyStaff() )->assign( $property_id, $this->staff_user_id );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Reminder Tenant' ] );

		$leases_repo    = new Lease( $units );
		$today          = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => $today->modify( '-11 months' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+7 days' )->format( 'Y-m-d' ),
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	public function test_sends_and_logs_a_reminder_for_a_due_threshold(): void {
		$this->reminder->send_due_reminders();

		$this->assertTrue( $this->notifications->already_sent( 'lease_expiring_7', $this->lease_id ) );
	}

	public function test_does_not_send_the_same_threshold_twice(): void {
		$this->reminder->send_due_reminders();
		$first_count = $this->count_notifications_for_lease();

		$this->reminder->send_due_reminders();
		$second_count = $this->count_notifications_for_lease();

		$this->assertSame( $first_count, $second_count, 'Running the cron again the same day must not duplicate reminders.' );
	}

	public function test_a_renewed_lease_is_not_reminded_even_though_it_matched_a_threshold_at_scan_time(): void {
		// Simulates SPEC.md §4.2's "reminder fires but the lease was
		// renewed in the meantime" case: end the lease before the cron's
		// per-lease notify step runs by ending it immediately, then
		// running the cron — it must not send anything for this lease.
		$this->leases->change_status( $this->lease_id, Lease::STATUS_RENEWED );

		$this->reminder->send_due_reminders();

		$this->assertSame( 0, $this->count_notifications_for_lease() );
	}

	private function count_notifications_for_lease(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}rm_notifications_log WHERE entity_id = %d",
				$this->lease_id
			)
		);
	}
}
