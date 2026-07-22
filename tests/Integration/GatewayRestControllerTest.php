<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Payments\GatewayRestController;
use ChrxRentalManager\Roles\RoleManager;

/**
 * The plugin's first REST routes (SPEC.md §4.9/§7) — dispatched as real
 * WP_REST_Request objects through rest_do_request()/the actual registered
 * WP_REST_Server, since tests/Integration/bootstrap.php boots a genuine
 * WordPress instance (not a lightweight test harness) and rest_api_init
 * fires naturally. No existing REST test precedent in this codebase to
 * follow — this establishes the pattern.
 */
final class GatewayRestControllerTest extends IntegrationTestCase {

	private GatewayTransaction $transactions;
	private int $lease_id;
	private int $tenant_id;
	private int $tenant_wp_user_id;

	protected function setUp(): void {
		parent::setUp();

		update_option( Settings::OPT_NYLONPAY_WEBHOOK_SECRET, 'test-webhook-secret' );
		wp_cache_flush();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->transactions = new GatewayTransaction();

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'REST Test Property', 'city' => 'Kampala' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [ 'property_id' => $property_id, 'unit_label' => 'R1', 'rent_amount' => 1000, 'status' => Unit::STATUS_VACANT ] );

		$tenants         = new Tenant();
		$this->tenant_id = $tenants->insert( [ 'full_name' => 'REST Tenant' ] );

		$this->tenant_wp_user_id = wp_insert_user( [
			'user_login' => 'rest_tenant_' . wp_generate_password( 8, false ),
			'user_email' => uniqid( 'rest_tenant_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );
		$tenants->update( $this->tenant_id, [ 'wp_user_id' => $this->tenant_wp_user_id ] );

		$leases_repo    = new Lease( $units );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 1000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	private function insert_transaction( string $status = GatewayTransaction::STATUS_PROCESSING, ?int $tenant_id = null ): array {
		$reference = wp_generate_uuid4();
		$id        = $this->transactions->insert( [
			'gateway'             => GatewayTransaction::GATEWAY_NYLONPAY,
			'reference'           => $reference,
			'lease_id'            => $this->lease_id,
			'charge_id'           => null,
			'tenant_id'           => $tenant_id ?? $this->tenant_id,
			'amount'              => 1000.0,
			'currency'            => 'UGX',
			'status'              => $status,
			'initiated_by'        => GatewayTransaction::INITIATED_BY_TENANT,
			'initiator_user_id'   => $this->tenant_wp_user_id,
			'phone_used'          => '0700000000',
			'raw_webhook_payload' => null,
		] );

		return [ 'id' => $id, 'reference' => $reference ];
	}

	private function sign( string $timestamp, string $payload ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $payload, 'test-webhook-secret' );
	}

	public function test_webhook_rejects_an_invalid_signature(): void {
		$transaction = $this->insert_transaction();
		$payload     = wp_json_encode( [ 'event' => 'collection.completed', 'reference' => $transaction['reference'] ] );

		$request = new \WP_REST_Request( 'POST', '/' . GatewayRestController::NAMESPACE_SLUG . '/nylonpay-webhook' );
		$request->set_header( 'X-Nylonpay-Signature', 'not-a-real-signature' );
		$request->set_header( 'X-Nylonpay-Timestamp', (string) time() );
		$request->set_body( $payload );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_webhook_accepts_a_correctly_signed_payload_and_schedules_processing(): void {
		$transaction = $this->insert_transaction();
		$payload     = wp_json_encode( [ 'event' => 'collection.completed', 'reference' => $transaction['reference'] ] );
		$timestamp   = (string) time();

		$request = new \WP_REST_Request( 'POST', '/' . GatewayRestController::NAMESPACE_SLUG . '/nylonpay-webhook' );
		$request->set_header( 'X-Nylonpay-Signature', $this->sign( $timestamp, $payload ) );
		$request->set_header( 'X-Nylonpay-Timestamp', $timestamp );
		$request->set_body( $payload );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotFalse( wp_next_scheduled( 'rm_process_gateway_webhook', [ $transaction['id'], 'collection.completed', $payload ] ), 'The webhook must defer heavy processing to a scheduled event rather than doing it inline.' );
	}

	public function test_webhook_dedupes_a_transaction_already_in_a_final_state(): void {
		$transaction = $this->insert_transaction( GatewayTransaction::STATUS_SUCCESSFUL );
		$payload     = wp_json_encode( [ 'event' => 'collection.completed', 'reference' => $transaction['reference'] ] );
		$timestamp   = (string) time();

		$request = new \WP_REST_Request( 'POST', '/' . GatewayRestController::NAMESPACE_SLUG . '/nylonpay-webhook' );
		$request->set_header( 'X-Nylonpay-Signature', $this->sign( $timestamp, $payload ) );
		$request->set_header( 'X-Nylonpay-Timestamp', $timestamp );
		$request->set_body( $payload );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( wp_next_scheduled( 'rm_process_gateway_webhook', [ $transaction['id'], 'collection.completed', $payload ] ), 'An already-resolved transaction must be a silent no-op, never re-scheduled for processing.' );
	}

	public function test_webhook_returns_404_for_an_unknown_reference(): void {
		$payload   = wp_json_encode( [ 'event' => 'collection.completed', 'reference' => 'no-such-reference' ] );
		$timestamp = (string) time();

		$request = new \WP_REST_Request( 'POST', '/' . GatewayRestController::NAMESPACE_SLUG . '/nylonpay-webhook' );
		$request->set_header( 'X-Nylonpay-Signature', $this->sign( $timestamp, $payload ) );
		$request->set_header( 'X-Nylonpay-Timestamp', $timestamp );
		$request->set_body( $payload );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_status_poll_requires_a_logged_in_portal_user(): void {
		$transaction = $this->insert_transaction();

		$request = new \WP_REST_Request( 'GET', '/' . GatewayRestController::NAMESPACE_SLUG . '/gateway-transactions/' . $transaction['reference'] . '/status' );

		$response = rest_do_request( $request );

		$this->assertNotSame( 200, $response->get_status(), 'An unauthenticated request must never receive transaction status data.' );
	}

	public function test_status_poll_returns_the_status_for_the_owning_tenant(): void {
		$transaction = $this->insert_transaction( GatewayTransaction::STATUS_PROCESSING );

		wp_set_current_user( $this->tenant_wp_user_id );

		$request  = new \WP_REST_Request( 'GET', '/' . GatewayRestController::NAMESPACE_SLUG . '/gateway-transactions/' . $transaction['reference'] . '/status' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( GatewayTransaction::STATUS_PROCESSING, $response->get_data()['status'] );

		wp_set_current_user( 0 );
	}

	public function test_status_poll_never_leaks_another_tenants_transaction(): void {
		$other_tenants        = new Tenant();
		$other_tenant_id      = $other_tenants->insert( [ 'full_name' => 'Other Tenant' ] );
		$other_wp_user_id     = wp_insert_user( [
			'user_login' => 'other_tenant_' . wp_generate_password( 8, false ),
			'user_email' => uniqid( 'other_tenant_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );
		$other_tenants->update( $other_tenant_id, [ 'wp_user_id' => $other_wp_user_id ] );

		$transaction = $this->insert_transaction( GatewayTransaction::STATUS_PROCESSING, $this->tenant_id );

		wp_set_current_user( $other_wp_user_id );

		$request  = new \WP_REST_Request( 'GET', '/' . GatewayRestController::NAMESPACE_SLUG . '/gateway-transactions/' . $transaction['reference'] . '/status' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status(), 'A transaction belonging to a different tenant must be indistinguishable from not found.' );

		wp_set_current_user( 0 );
	}
}
