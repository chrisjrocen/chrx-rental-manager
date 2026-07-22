<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Cron\AlertDispatcher;
use ChrxRentalManager\Data\Alert;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\RoleManager;

/**
 * DB-facing coverage for AlertDispatcher::dispatch() (SPEC.md §4.8):
 * send-time recipient resolution, one-off deactivation, the empty-
 * recipient edge case, and the overlap-safe mutex — everything
 * AlertDispatcherTest's pure due-ness math can't exercise since it needs
 * real inserted rows and real notification logging.
 */
final class AlertDispatcherIntegrationTest extends IntegrationTestCase {

	private Alert $alerts;
	private NotificationLog $notifications;
	private AlertDispatcher $dispatcher;
	private int $property_id;
	private int $unit_id;
	private int $tenant_id;
	private int $staff_wp_user_id;

	protected function setUp(): void {
		parent::setUp();

		( new RoleManager() )->register_roles();

		$this->alerts        = new Alert();
		$this->notifications = new NotificationLog();
		$this->dispatcher     = new AlertDispatcher();

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Alert Test Property', 'city' => 'Accra' ] );

		$units          = new Unit();
		$this->unit_id  = $units->insert( [ 'property_id' => $this->property_id, 'unit_label' => 'AL1', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );

		$tenants         = new Tenant();
		$this->tenant_id = $tenants->insert( [ 'full_name' => 'Alert Tenant', 'email' => 'alert-tenant@example.com' ] );

		$this->staff_wp_user_id = wp_insert_user( [
			'user_login' => 'alert_staff_' . wp_generate_password( 8, false ),
			'user_email' => uniqid( 'alert_staff_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_STAFF,
		] );
		( new PropertyStaff() )->assign( $this->property_id, $this->staff_wp_user_id );
	}

	private function insert_alert( array $overrides = [] ): int {
		return $this->alerts->insert( array_merge(
			[
				'title'         => 'Test Alert',
				'message'       => 'Test message body',
				'entity_type'   => Alert::ENTITY_PROPERTY,
				'entity_id'     => $this->property_id,
				'schedule_type' => Alert::SCHEDULE_ONCE,
				'scheduled_at'  => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -1 minute' ) ),
				'recipients'    => [ 'selectors' => [ Alert::RECIPIENT_STAFF_OF_ENTITY ], 'user_ids' => [] ],
				'channels'      => [ NotificationLog::CHANNEL_EMAIL ],
				'created_by'    => 1,
				'active'        => 1,
			],
			$overrides
		) );
	}

	public function test_dispatch_sends_to_staff_of_entity_and_logs_it(): void {
		$alert_id = $this->insert_alert();

		$outcome = $this->dispatcher->dispatch();

		$this->assertSame( 1, $outcome['sent'] );

		$log = $this->notifications->for_type_and_entity( 'custom_alert', $alert_id );
		$this->assertNotEmpty( $log );
		$this->assertSame( NotificationLog::CHANNEL_EMAIL, $log[0]['channel'] );
	}

	public function test_dispatch_resolves_tenants_of_a_unit_level_alert_at_send_time(): void {
		$leases_repo = new \ChrxRentalManager\Data\Lease( new Unit() );
		$lease_id    = $leases_repo->create( [
			'unit_id'        => $this->unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
		$this->assertGreaterThan( 0, $lease_id );

		$alert_id = $this->insert_alert( [
			'entity_type' => Alert::ENTITY_UNIT,
			'entity_id'   => $this->unit_id,
			'recipients'  => [ 'selectors' => [ Alert::RECIPIENT_TENANTS_OF_ENTITY ], 'user_ids' => [] ],
		] );

		$this->dispatcher->dispatch();

		$log = $this->notifications->for_type_and_entity( 'custom_alert', $alert_id );
		$this->assertNotEmpty( $log );
		$this->assertSame( 'alert-tenant@example.com', $log[0]['recipient'] );
	}

	public function test_dispatch_on_a_vacant_unit_logs_the_empty_recipient_set_without_erroring(): void {
		// No lease exists on $this->unit_id -- resolving tenants_of_entity yields nobody.
		$alert_id = $this->insert_alert( [
			'entity_type' => Alert::ENTITY_UNIT,
			'entity_id'   => $this->unit_id,
			'recipients'  => [ 'selectors' => [ Alert::RECIPIENT_TENANTS_OF_ENTITY ], 'user_ids' => [] ],
		] );

		$outcome = $this->dispatcher->dispatch();

		$this->assertSame( 1, $outcome['sent'], 'The alert is still counted as dispatched even with nobody to notify.' );

		$log = $this->notifications->for_type_and_entity( 'custom_alert', $alert_id );
		$this->assertCount( 1, $log );
		$this->assertSame( NotificationLog::STATUS_SKIPPED, $log[0]['status'] );
	}

	public function test_dispatch_deactivates_a_one_off_alert_after_sending(): void {
		$alert_id = $this->insert_alert( [ 'schedule_type' => Alert::SCHEDULE_ONCE ] );

		$this->dispatcher->dispatch();

		$alert = $this->alerts->find( $alert_id );
		$this->assertSame( 0, (int) $alert['active'] );
		$this->assertNotNull( $alert['last_sent_at'] );
	}

	public function test_dispatch_keeps_a_recurring_alert_active_after_sending(): void {
		$alert_id = $this->insert_alert( [
			'schedule_type' => Alert::SCHEDULE_DAILY,
			'scheduled_at'  => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -1 minute' ) ),
		] );

		$this->dispatcher->dispatch();

		$alert = $this->alerts->find( $alert_id );
		$this->assertSame( 1, (int) $alert['active'] );
	}

	public function test_dispatch_does_not_resend_an_already_due_alert_on_a_second_run(): void {
		$this->insert_alert( [ 'schedule_type' => Alert::SCHEDULE_ONCE ] );

		$first  = $this->dispatcher->dispatch();
		$second = $this->dispatcher->dispatch();

		$this->assertSame( 1, $first['sent'] );
		$this->assertSame( 0, $second['sent'], 'A one-off alert already sent (and deactivated) must not be picked up again.' );
	}

	public function test_a_concurrent_dispatch_run_is_blocked_by_the_mutex(): void {
		set_transient( 'chrx_rm_alert_dispatch_lock', 1, 120 );

		$this->insert_alert();

		$outcome = $this->dispatcher->dispatch();

		$this->assertTrue( $outcome['locked'] );
		$this->assertSame( 0, $outcome['sent'] );

		delete_transient( 'chrx_rm_alert_dispatch_lock' );
	}

	public function test_explicit_user_ids_are_notified_alongside_selectors(): void {
		$alert_id = $this->insert_alert( [
			'recipients' => [ 'selectors' => [], 'user_ids' => [ $this->staff_wp_user_id ] ],
		] );

		$this->dispatcher->dispatch();

		$log = $this->notifications->for_type_and_entity( 'custom_alert', $alert_id );
		$this->assertNotEmpty( $log );
	}

	public function test_banners_for_only_returns_alerts_with_the_portal_channel(): void {
		$this->insert_alert( [
			'channels'   => [ NotificationLog::CHANNEL_EMAIL ],
			'recipients' => [ 'selectors' => [ Alert::RECIPIENT_STAFF_OF_ENTITY ], 'user_ids' => [] ],
		] );

		$banners = $this->dispatcher->banners_for( [ $this->property_id ], null, $this->staff_wp_user_id );

		$this->assertSame( [], $banners, 'An email-only alert must never appear as a portal/dashboard banner.' );
	}

	public function test_banners_for_returns_an_active_portal_alert_addressed_to_the_viewer(): void {
		$this->insert_alert( [
			'channels'   => [ NotificationLog::CHANNEL_PORTAL ],
			'recipients' => [ 'selectors' => [ Alert::RECIPIENT_STAFF_OF_ENTITY ], 'user_ids' => [] ],
		] );

		$banners = $this->dispatcher->banners_for( [ $this->property_id ], null, $this->staff_wp_user_id );

		$this->assertCount( 1, $banners );
	}

	public function test_banners_for_excludes_account_wide_alerts_for_a_restricted_viewer(): void {
		$this->insert_alert( [
			'entity_type' => Alert::ENTITY_NONE,
			'entity_id'   => null,
			'channels'    => [ NotificationLog::CHANNEL_PORTAL ],
			'recipients'  => [ 'selectors' => [ Alert::RECIPIENT_SELF ], 'user_ids' => [] ],
			'created_by'  => $this->staff_wp_user_id,
		] );

		$restricted_banners   = $this->dispatcher->banners_for( [ $this->property_id ], null, $this->staff_wp_user_id );
		$unrestricted_banners = $this->dispatcher->banners_for( null, null, $this->staff_wp_user_id );

		$this->assertSame( [], $restricted_banners, 'Account-wide alerts must never surface for a property-restricted (Staff/Landlord) viewer.' );
		$this->assertCount( 1, $unrestricted_banners, 'An Administrator (null scope) must still see account-wide banners.' );
	}
}
