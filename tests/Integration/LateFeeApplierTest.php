<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Cron\LateFeeApplier;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\RoleManager;

/**
 * `rm_apply_late_fees` (SPEC.md §4.3/§6) — the grace-period boundary is
 * the highest-risk part of this cron per SPEC.md's own framing, so it's
 * tested against real DB rows/dates rather than mocked.
 */
final class LateFeeApplierTest extends IntegrationTestCase {

	private Charge $charges;
	private Lease $leases;
	private LateFeeApplier $applier;
	private int $lease_id;
	private int $property_id;
	private int $staff_user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->charges = new Charge();
		$this->leases    = new Lease();
		$this->applier  = new LateFeeApplier( $this->charges, $this->leases );

		update_option( Settings::OPT_LATE_FEE_GRACE_DAYS, 5 );
		update_option( Settings::OPT_LATE_FEE_AMOUNT, 50 );
		update_option( Settings::OPT_LATE_FEE_TYPE, Settings::LATE_FEE_TYPE_FLAT );

		$properties        = new Property();
		$property_id       = $properties->insert( [ 'name' => 'Late Fee Test Property', 'city' => 'Accra' ] );
		$this->property_id = $property_id;

		$this->staff_user_id = wp_insert_user( [
			'user_login' => 'late_fee_staff_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'late_fee_staff_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_STAFF,
		] );

		( new PropertyStaff() )->assign( $property_id, $this->staff_user_id );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'LF1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Late Fee Tenant' ] );

		$leases_repo    = new Lease( $units );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	private function insert_charge_due( string $due_date ): int {
		return $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => $due_date,
			'period_due_date' => $due_date,
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );
	}

	public function test_charge_exactly_at_the_grace_period_boundary_is_not_yet_late(): void {
		// due_date + 5 days == now: still inside the grace period. Asserts
		// against this test's own lease rather than apply()'s global
		// return count — apply() scans every overdue charge site-wide
		// (SPEC.md's late-fee cron isn't lease-scoped), so a dev DB with
		// other seeded/overdue data would otherwise make this test flaky.
		$due_date = current_time( 'Y-m-d' );
		$due_date = gmdate( 'Y-m-d', strtotime( $due_date . ' -5 days' ) );
		$this->insert_charge_due( $due_date );

		$this->applier->apply();

		$late_fees = array_filter( $this->charges->for_lease( $this->lease_id ), fn( array $c ): bool => Charge::TYPE_LATE_FEE === $c['type'] );
		$this->assertCount( 0, $late_fees );
	}

	public function test_charge_one_day_past_the_grace_period_gets_a_late_fee(): void {
		$due_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -6 days' ) );
		$this->insert_charge_due( $due_date );

		$this->applier->apply();

		$charges  = $this->charges->for_lease( $this->lease_id );
		$late_fee = array_values( array_filter( $charges, fn( array $c ): bool => Charge::TYPE_LATE_FEE === $c['type'] ) );

		$this->assertCount( 1, $late_fee );
		$this->assertSame( 50.0, (float) $late_fee[0]['amount_due'] );
	}

	public function test_a_late_fee_is_never_applied_twice_for_the_same_period(): void {
		$due_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -10 days' ) );
		$this->insert_charge_due( $due_date );

		$this->applier->apply();
		$first_count = count( $this->charges->for_lease( $this->lease_id ) );

		$this->applier->apply();
		$second_count = count( $this->charges->for_lease( $this->lease_id ) );

		$this->assertSame( $first_count, $second_count );
	}

	public function test_a_waived_late_fee_is_not_regenerated(): void {
		$due_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -10 days' ) );
		$this->insert_charge_due( $due_date );

		$this->applier->apply();

		$charges  = $this->charges->for_lease( $this->lease_id );
		$late_fee = array_values( array_filter( $charges, fn( array $c ): bool => Charge::TYPE_LATE_FEE === $c['type'] ) );
		$this->charges->mark_waived( (int) $late_fee[0]['id'] );

		$this->applier->apply();

		$charges_after = $this->charges->for_lease( $this->lease_id );
		$late_fees_after = array_filter( $charges_after, fn( array $c ): bool => Charge::TYPE_LATE_FEE === $c['type'] );

		$this->assertCount( 1, $late_fees_after, 'A waived late fee must not be regenerated.' );
	}

	public function test_percent_based_late_fee_is_calculated_from_rent(): void {
		update_option( Settings::OPT_LATE_FEE_TYPE, Settings::LATE_FEE_TYPE_PERCENT );
		update_option( Settings::OPT_LATE_FEE_AMOUNT, 10 ); // 10% of 1000 rent = 100.

		$due_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -10 days' ) );
		$this->insert_charge_due( $due_date );

		$this->applier->apply();

		$charges  = $this->charges->for_lease( $this->lease_id );
		$late_fee = array_values( array_filter( $charges, fn( array $c ): bool => Charge::TYPE_LATE_FEE === $c['type'] ) );

		$this->assertSame( 100.0, (float) $late_fee[0]['amount_due'] );
	}

	/**
	 * SPEC.md §5 (v2): "Charge overdue past grace → Assigned staff →
	 * Email + WhatsApp" — this notification did not exist in v1 at all;
	 * asserts it now fires and logs against the assigned staff member.
	 */
	public function test_assigned_staff_is_notified_when_a_late_fee_is_applied(): void {
		$due_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -6 days' ) );
		$this->insert_charge_due( $due_date );

		$this->applier->apply();

		global $wpdb;

		$staff_user = get_userdata( $this->staff_user_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rm_notifications_log WHERE type = %s AND recipient = %s AND channel = %s",
				'charge_overdue',
				$staff_user->user_email,
				NotificationLog::CHANNEL_EMAIL
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Assigned staff should be notified of the overdue charge.' );
	}
}
