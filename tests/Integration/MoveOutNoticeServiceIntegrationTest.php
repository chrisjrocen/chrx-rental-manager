<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\MoveOutNotice;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Leases\MoveOutNoticeService;
use ChrxRentalManager\Roles\RoleManager;

/**
 * DB-facing coverage for Leases\MoveOutNoticeService (SPEC.md §4.10):
 * the single-active-notice constraint, the lease-end cap, cancellation,
 * and notification dispatch — everything MoveOutNoticeServiceTest's pure
 * math can't exercise since it needs real inserted rows.
 */
final class MoveOutNoticeServiceIntegrationTest extends IntegrationTestCase {

	private MoveOutNotice $notices;
	private NotificationLog $notifications;
	private MoveOutNoticeService $service;
	private int $lease_id;
	private int $tenant_id;
	private int $staff_wp_user_id;

	protected function setUp(): void {
		parent::setUp();

		( new RoleManager() )->register_roles();

		$this->notices       = new MoveOutNotice();
		$this->notifications = new NotificationLog();
		$this->service        = new MoveOutNoticeService();

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Notice Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [ 'property_id' => $property_id, 'unit_label' => 'MN1', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );

		$this->staff_wp_user_id = wp_insert_user( [
			'user_login' => 'notice_staff_' . wp_generate_password( 8, false ),
			'user_email' => uniqid( 'notice_staff_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_STAFF,
		] );
		( new PropertyStaff() )->assign( $property_id, $this->staff_wp_user_id );

		$tenants         = new Tenant();
		$this->tenant_id = $tenants->insert( [ 'full_name' => 'Notice Tenant', 'email' => 'notice-tenant@example.com' ] );

		$leases_repo    = new Lease( $units );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2027-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	public function test_submit_notice_creates_an_active_notice_and_notifies_staff(): void {
		$result = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $result['notice_id'] );

		$notice = $this->notices->find( $result['notice_id'] );
		$this->assertSame( MoveOutNotice::STATUS_ACTIVE, $notice['status'] );
		$this->assertSame( MoveOutNotice::SUBMITTED_BY_TENANT, $notice['submitted_by'] );

		$log = $this->notifications->for_type_and_entity( 'move_out_notice_submitted', $result['notice_id'] );
		$this->assertNotEmpty( $log );
	}

	public function test_earliest_move_out_date_uses_the_account_default_notice_period(): void {
		$result = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );
		$notice = $this->notices->find( $result['notice_id'] );

		$expected = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +2 months' ) );
		$this->assertSame( $expected, $notice['earliest_move_out_date'], 'Default notice period is 2 months.' );
	}

	public function test_earliest_move_out_date_is_capped_at_lease_end(): void {
		$units       = new Unit();
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Short Lease Property', 'city' => 'Accra' ] );
		$unit_id     = $units->insert( [ 'property_id' => $property_id, 'unit_label' => 'MN2', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );

		$leases_repo  = new Lease( $units );
		$soon_end     = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +10 days' ) );
		$short_lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => $soon_end,
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$result = $this->service->submit_notice( $short_lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );
		$notice = $this->notices->find( $result['notice_id'] );

		$this->assertSame( $soon_end, $notice['earliest_move_out_date'], 'A notice must never extend liability beyond the lease term.' );
	}

	public function test_a_second_active_notice_on_the_same_lease_is_blocked(): void {
		$first = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );
		$this->assertTrue( $first['success'] );

		$second = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_STAFF, $this->staff_wp_user_id, null, '' );

		$this->assertFalse( $second['success'] );
		$this->assertNull( $second['notice_id'] );
	}

	public function test_cancelling_a_notice_allows_a_new_one_to_be_submitted(): void {
		$first = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );
		$this->service->cancel_notice( $first['notice_id'] );

		$notice = $this->notices->find( $first['notice_id'] );
		$this->assertSame( MoveOutNotice::STATUS_CANCELLED, $notice['status'] );

		$second = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );
		$this->assertTrue( $second['success'], 'Cancelling frees the lease up for a new notice.' );
	}

	public function test_cancel_notice_notifies_staff_and_is_idempotent_against_an_already_cancelled_notice(): void {
		$result = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );

		$first_cancel  = $this->service->cancel_notice( $result['notice_id'] );
		$second_cancel = $this->service->cancel_notice( $result['notice_id'] );

		$this->assertTrue( $first_cancel['success'] );
		$this->assertFalse( $second_cancel['success'], 'A notice that is not active cannot be cancelled again.' );

		$log = $this->notifications->for_type_and_entity( 'move_out_notice_cancelled', $result['notice_id'] );
		$this->assertNotEmpty( $log );
	}

	public function test_submit_notice_rejects_a_lease_that_is_not_active(): void {
		$leases = new Lease();
		$leases->change_status( $this->lease_id, Lease::STATUS_ENDED );

		$result = $this->service->submit_notice( $this->lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * SPEC.md §4.10: "settable per property as an override (hostels may
	 * differ from apartments)."
	 */
	public function test_a_propertys_own_notice_period_override_wins_over_the_account_default(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Hostel Override Property', 'city' => 'Accra', 'notice_period_months' => 1 ] );

		$units   = new Unit();
		$unit_id = $units->insert( [ 'property_id' => $property_id, 'unit_label' => 'MN3', 'rent_amount' => 500, 'status' => Unit::STATUS_VACANT ] );

		$leases_repo = new Lease( $units );
		$lease_id    = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2027-12-31',
			'rent_amount'    => 500,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$result = $this->service->submit_notice( $lease_id, MoveOutNotice::SUBMITTED_BY_TENANT, 1, null, '' );
		$notice = $this->notices->find( $result['notice_id'] );

		$expected = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 month' ) );
		$this->assertSame( $expected, $notice['earliest_move_out_date'], 'The property\'s 1-month override must win over the account-wide 2-month default.' );
	}
}
