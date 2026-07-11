<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Billing\ReceiptPdf;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Portal\PortalContext;
use ChrxRentalManager\Portal\PortalShortcode;
use ChrxRentalManager\Roles\RoleManager;

/**
 * SPEC.md §4.5's tenant-portal scoping requirement, enforced server-side:
 * a logged-in tenant must only ever be able to query their own
 * rm_tenants/rm_leases records, verified here by actually attempting to
 * access another tenant's lease/receipt by ID manipulation and asserting
 * it's blocked, not merely absent from the UI's own links.
 */
final class PortalScopingTest extends IntegrationTestCase {

	private PortalContext $context;
	private int $tenant_a_id;
	private int $tenant_b_id;
	private int $lease_a_id;
	private int $lease_b_id;
	private int $wp_user_a;
	private int $wp_user_b;
	private int $receipt_a_id;

	protected function setUp(): void {
		parent::setUp();

		( new RoleManager() )->register_roles();

		$this->context = new PortalContext();

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Portal Test Property', 'city' => 'Accra' ] );

		$units   = new Unit();
		$unit_a  = $units->insert( [ 'property_id' => $property_id, 'unit_label' => 'PA1', 'bedrooms' => 2, 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );
		$unit_b  = $units->insert( [ 'property_id' => $property_id, 'unit_label' => 'PB1', 'bedrooms' => 1, 'rent_amount' => 800, 'status' => Unit::STATUS_VACANT ] );

		$this->wp_user_a = wp_insert_user( [
			'user_login' => 'portal_tenant_a_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'portal_a_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );
		$this->wp_user_b = wp_insert_user( [
			'user_login' => 'portal_tenant_b_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'portal_b_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );

		$tenants           = new Tenant();
		$this->tenant_a_id = $tenants->insert( [ 'wp_user_id' => $this->wp_user_a, 'full_name' => 'Portal Tenant A' ] );
		$this->tenant_b_id = $tenants->insert( [ 'wp_user_id' => $this->wp_user_b, 'full_name' => 'Portal Tenant B' ] );

		$today = new \DateTimeImmutable( current_time( 'Y-m-d' ) );

		$leases_repo      = new Lease( $units );
		$this->lease_a_id = $leases_repo->create( [
			'unit_id'        => $unit_a,
			'tenant_id'      => $this->tenant_a_id,
			'start_date'     => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+11 months' )->format( 'Y-m-d' ),
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 2000,
			'deposit_status' => 'paid',
		] );
		$this->lease_b_id = $leases_repo->create( [
			'unit_id'        => $unit_b,
			'tenant_id'      => $this->tenant_b_id,
			'start_date'     => $today->modify( '-1 month' )->format( 'Y-m-d' ),
			'end_date'       => $today->modify( '+11 months' )->format( 'Y-m-d' ),
			'rent_amount'    => 800,
			'billing_day'    => 1,
			'deposit_amount' => 1600,
			'deposit_status' => 'paid',
		] );

		$payments      = new Payment();
		$payment_a_id  = $payments->insert( [
			'lease_id'       => $this->lease_a_id,
			'charge_id'      => null,
			'amount'         => 1000.0,
			'method'         => Payment::METHOD_CASH,
			'reference_note' => '',
			'recorded_by'    => 1,
			'receipt_id'     => null,
			'paid_at'        => current_time( 'mysql' ),
		] );

		$receipt = ( new ReceiptPdf() )->generate_for_payment( $payment_a_id );
		$this->receipt_a_id = (int) $receipt['id'];
	}

	protected function tearDown(): void {
		$receipts = new \ChrxRentalManager\Data\Receipt();
		$receipt  = $receipts->find( $this->receipt_a_id );

		if ( null !== $receipt ) {
			$path = ( new ReceiptPdf() )->absolute_path( $receipt );

			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		parent::tearDown();
	}

	public function test_tenant_for_wp_user_resolves_only_the_matching_tenant(): void {
		$tenant = $this->context->tenant_for_wp_user( $this->wp_user_a );

		$this->assertNotNull( $tenant );
		$this->assertSame( $this->tenant_a_id, (int) $tenant['id'] );
	}

	public function test_lease_belongs_to_tenant_is_true_for_own_lease_false_for_others(): void {
		$this->assertTrue( $this->context->lease_belongs_to_tenant( $this->lease_a_id, $this->tenant_a_id ) );
		$this->assertFalse( $this->context->lease_belongs_to_tenant( $this->lease_b_id, $this->tenant_a_id ) );
		$this->assertFalse( $this->context->lease_belongs_to_tenant( $this->lease_a_id, $this->tenant_b_id ) );
	}

	/**
	 * The exact ID-manipulation attack SPEC.md's deliverable calls out:
	 * tenant B, logged in, requests tenant A's receipt by guessing/
	 * incrementing the id in the query string.
	 */
	public function test_a_logged_in_tenant_cannot_view_another_tenants_receipt_via_query_string(): void {
		wp_set_current_user( $this->wp_user_b );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test simulates an attacker-controlled query string, not a real form submission.
		$_GET['rm_view']       = PortalShortcode::VIEW_RECEIPT;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['rm_receipt_id'] = $this->receipt_a_id;

		$html = ( new PortalShortcode() )->render();

		$this->assertStringNotContainsString( 'RC-', $html, "Tenant B's portal must never render tenant A's receipt number." );
		$this->assertStringContainsString( 'Receipt not found', $html );

		unset( $_GET['rm_view'], $_GET['rm_receipt_id'] );
		wp_set_current_user( 0 );
	}

	public function test_the_owning_tenant_can_view_their_own_receipt(): void {
		wp_set_current_user( $this->wp_user_a );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['rm_view']       = PortalShortcode::VIEW_RECEIPT;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['rm_receipt_id'] = $this->receipt_a_id;

		$html = ( new PortalShortcode() )->render();

		$this->assertStringContainsString( 'RC-', $html );
		$this->assertStringNotContainsString( 'Receipt not found', $html );

		unset( $_GET['rm_view'], $_GET['rm_receipt_id'] );
		wp_set_current_user( 0 );
	}

	public function test_home_view_never_shows_another_tenants_balance_or_name(): void {
		wp_set_current_user( $this->wp_user_a );

		$html = ( new PortalShortcode() )->render();

		$this->assertStringContainsString( 'Portal Tenant A', $html );
		$this->assertStringNotContainsString( 'Portal Tenant B', $html );
		$this->assertStringNotContainsString( 'PB1', $html, "Tenant A's home view must never mention tenant B's unit." );

		wp_set_current_user( 0 );
	}

	public function test_payment_history_never_leaks_another_tenants_payment(): void {
		wp_set_current_user( $this->wp_user_b );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['rm_view'] = PortalShortcode::VIEW_PAYMENTS;

		$html = ( new PortalShortcode() )->render();

		$this->assertStringContainsString( 'No payments recorded yet', $html, 'Tenant B has no payments of their own; tenant A\'s payment must not appear.' );

		unset( $_GET['rm_view'] );
		wp_set_current_user( 0 );
	}

	public function test_a_logged_out_visitor_sees_no_tenant_data(): void {
		wp_set_current_user( 0 );

		$html = ( new PortalShortcode() )->render();

		$this->assertStringNotContainsString( 'Portal Tenant A', $html );
		$this->assertStringContainsString( 'log', strtolower( $html ) );
	}
}
