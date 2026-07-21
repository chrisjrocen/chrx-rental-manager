<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

/**
 * Guard methods that gate the "Delete Permanently" hard-delete action
 * (only reachable from the Trash view): a record with any lease/financial
 * history must stay blocked, since hard-deleting it would orphan rows that
 * still need to stay queryable for reporting/reconciliation.
 */
final class PermanentDeleteTest extends IntegrationTestCase {

	public function test_unit_with_no_lease_history_can_be_permanently_deleted(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property A', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD1',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$this->assertFalse( $units->has_lease_history( $unit_id ) );

		$units->soft_delete( $unit_id );
		$this->assertTrue( $units->delete_permanently( $unit_id ) );
		$this->assertNull( $units->find( $unit_id ) );
	}

	public function test_unit_with_lease_history_is_blocked_even_if_lease_is_trashed(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property B', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD2',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'PD Tenant' ] );

		$leases   = new Lease( $units );
		$lease_id = $leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$leases->soft_delete( $lease_id );

		$this->assertTrue( $units->has_lease_history( $unit_id ), 'A trashed lease still counts as lease history.' );
	}

	public function test_tenant_with_no_lease_history_can_be_permanently_deleted(): void {
		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'PD Lone Tenant' ] );

		$this->assertFalse( $tenants->has_lease_history( $tenant_id ) );

		$tenants->soft_delete( $tenant_id );
		$this->assertTrue( $tenants->delete_permanently( $tenant_id ) );
		$this->assertNull( $tenants->find( $tenant_id ) );
	}

	public function test_tenant_with_lease_history_is_blocked(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property C', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD3',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'PD Tenant With Lease' ] );

		$leases = new Lease( $units );
		$leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$this->assertTrue( $tenants->has_lease_history( $tenant_id ) );
	}

	public function test_property_with_no_units_can_be_permanently_deleted(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Empty Property', 'city' => 'Accra' ] );

		$this->assertFalse( $properties->has_any_units( $property_id ) );

		$properties->soft_delete( $property_id );
		$this->assertTrue( $properties->delete_permanently( $property_id ) );
		$this->assertNull( $properties->find( $property_id ) );
	}

	public function test_property_with_a_trashed_unit_is_blocked(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property D', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD4',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$units->soft_delete( $unit_id );

		$this->assertTrue( $properties->has_any_units( $property_id ), 'A trashed unit still counts against the property.' );
	}

	public function test_lease_with_no_financial_history_can_be_permanently_deleted(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property E', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD5',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'PD Lease Tenant' ] );

		$leases   = new Lease( $units );
		$lease_id = $leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$this->assertFalse( $leases->has_financial_history( $lease_id ) );

		$leases->soft_delete( $lease_id );
		$this->assertTrue( $leases->delete_permanently( $lease_id ) );
		$this->assertNull( $leases->find( $lease_id ) );
	}

	public function test_lease_with_a_charge_is_blocked(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property F', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD6',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'PD Lease Tenant 2' ] );

		$leases   = new Lease( $units );
		$lease_id = $leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$charges = new Charge();
		$charges->insert( [
			'lease_id'        => $lease_id,
			'period_start'    => '2026-01-01',
			'period_due_date' => '2026-01-01',
			'amount_due'      => 1000,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );

		$this->assertTrue( $leases->has_financial_history( $lease_id ) );
	}

	public function test_lease_with_a_payment_is_blocked(): void {
		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'PD Property G', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'PD7',
			'rent_amount' => 1000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants   = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'PD Lease Tenant 3' ] );

		$leases   = new Lease( $units );
		$lease_id = $leases->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );

		$payments = new Payment();
		$payments->insert( [
			'lease_id'    => $lease_id,
			'charge_id'   => null,
			'amount'      => 500,
			'method'      => Payment::METHOD_CASH,
			'recorded_by' => 1,
			'paid_at'     => current_time( 'mysql' ),
		] );

		$this->assertTrue( $leases->has_financial_history( $lease_id ) );
	}
}
