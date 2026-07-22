<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\GatewayTransaction;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Payments\GatewayPaymentService;
use ChrxRentalManager\Payments\NylonPayClient;

/**
 * A hand-rolled test double implementing NylonPayClient (no real network
 * call) — same "trivially mockable via a hand-rolled test double"
 * approach already used for Communications\MessageChannel in
 * NotifierTest, since there's no live Nylon Pay sandbox to hit here.
 */
final class FakeNylonPayClient implements NylonPayClient {

	public bool $collect_succeeds = true;
	public string $status_to_report = GatewayTransaction::STATUS_PROCESSING;

	/**
	 * @return array{success:bool,failure_reason:?string}
	 */
	public function collect( string $reference, float $amount, string $currency, string $phone, string $customer_name, array $metadata ): array {
		return array(
			'success'        => $this->collect_succeeds,
			'failure_reason' => $this->collect_succeeds ? null : 'sandbox failure',
		);
	}

	/**
	 * @return array{status:string,failure_reason:?string}
	 */
	public function check_status( string $reference ): array {
		return array(
			'status'         => $this->status_to_report,
			'failure_reason' => GatewayTransaction::STATUS_FAILED === $this->status_to_report ? 'declined' : null,
		);
	}
}

/**
 * GatewayPaymentService (SPEC.md §4.9) — the central initiate/settle/
 * expire/reconcile logic every Nylon Pay flow (webhook, portal Pay Now,
 * staff request, reconciliation cron) shares. Exercised against a real
 * DB and a FakeNylonPayClient rather than the live sandbox (no network
 * access in this environment; SPEC.md itself defers real sandbox/webhook
 * QA to manual testing).
 */
final class GatewayPaymentServiceTest extends IntegrationTestCase {

	private GatewayTransaction $transactions;
	private Charge $charges;
	private Payment $payments;
	private FakeNylonPayClient $client;
	private GatewayPaymentService $service;
	private int $lease_id;
	private int $tenant_id;

	protected function setUp(): void {
		parent::setUp();

		update_option( Settings::OPT_NYLONPAY_ENABLED, true );
		update_option( Settings::OPT_NYLONPAY_API_KEY, 'test-key' );
		update_option( Settings::OPT_NYLONPAY_API_SECRET, 'test-secret' );
		update_option( Settings::OPT_NYLONPAY_WEBHOOK_SECRET, 'test-webhook-secret' );
		update_option( Settings::OPT_CURRENCY_CODE, 'UGX' );
		wp_cache_flush();

		$this->transactions = new GatewayTransaction();
		$this->charges       = new Charge();
		$this->payments       = new Payment();
		$this->client         = new FakeNylonPayClient();
		$this->service        = new GatewayPaymentService( null, null, null, null, null, null, null, null, null, null, $this->client );

		$properties  = new Property();
		$property_id = $properties->insert( [ 'name' => 'Gateway Test Property', 'city' => 'Kampala' ] );

		$units   = new Unit();
		$unit_id = $units->insert( [
			'property_id' => $property_id,
			'unit_label'  => 'G1',
			'rent_amount' => 200000,
			'status'      => Unit::STATUS_VACANT,
		] );

		$tenants         = new Tenant();
		$this->tenant_id = $tenants->insert( [ 'full_name' => 'Gateway Tenant', 'email' => 'gateway-tenant@example.com' ] );

		$leases_repo    = new Lease( $units );
		$this->lease_id = $leases_repo->create( [
			'unit_id'        => $unit_id,
			'tenant_id'      => $this->tenant_id,
			'start_date'     => '2026-01-01',
			'end_date'       => '2026-12-31',
			'rent_amount'    => 200000,
			'billing_day'    => 1,
			'deposit_amount' => 0,
			'deposit_status' => 'unpaid',
		] );
	}

	private function insert_charge( float $amount_due ): int {
		return $this->charges->insert( [
			'lease_id'        => $this->lease_id,
			'period_start'    => '2026-03-01',
			'period_due_date' => '2026-03-01',
			'amount_due'      => $amount_due,
			'type'            => Charge::TYPE_RENT,
			'status'          => Charge::STATUS_UNPAID,
		] );
	}

	public function test_initiate_collection_writes_the_transaction_row_before_reporting_success(): void {
		$charge_id = $this->insert_charge( 200000.0 );

		$result = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $result['transaction_id'] );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertNotNull( $transaction );
		$this->assertSame( GatewayTransaction::STATUS_PROCESSING, $transaction['status'] );
		$this->assertSame( $result['reference'], $transaction['reference'] );
		$this->assertSame( 'UGX', $transaction['currency'] );
	}

	public function test_initiate_collection_marks_the_row_failed_when_the_api_call_fails(): void {
		$this->client->collect_succeeds = false;
		$charge_id                       = $this->insert_charge( 200000.0 );

		$result = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->assertFalse( $result['success'] );
		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_FAILED, $transaction['status'], 'The DB row must still exist (write-first) even when the API call itself fails.' );
	}

	public function test_initiate_collection_rejects_amounts_below_the_nylonpay_minimum(): void {
		$charge_id = $this->insert_charge( 100.0 );

		$result = $this->service->initiate_collection( $this->lease_id, $charge_id, 100.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['transaction_id'], 'Below the minimum, no DB row should be created at all.' );
	}

	public function test_initiate_collection_refuses_when_nylonpay_is_disabled(): void {
		update_option( Settings::OPT_NYLONPAY_ENABLED, false );
		wp_cache_flush();

		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->assertFalse( $result['success'] );
	}

	public function test_settle_successful_records_a_payment_with_null_recorded_by_and_gateway_link(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->service->settle_successful( $result['transaction_id'] );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_SUCCESSFUL, $transaction['status'] );

		$charge = $this->charges->find( $charge_id );
		$this->assertSame( Charge::STATUS_PAID, $charge['status'] );

		$payments = $this->payments->for_charge( $charge_id );
		$this->assertCount( 1, $payments );
		$this->assertNull( $payments[0]['recorded_by'] );
		$this->assertSame( (int) $result['transaction_id'], (int) $payments[0]['gateway_transaction_id'] );
		$this->assertSame( Payment::METHOD_NYLONPAY, $payments[0]['method'] );
	}

	public function test_settle_successful_is_idempotent_against_a_second_delivery(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->service->settle_successful( $result['transaction_id'] );
		$this->service->settle_successful( $result['transaction_id'] ); // At-least-once delivery — a second call must not double-record.

		$payments = $this->payments->for_charge( $charge_id );
		$this->assertCount( 1, $payments, 'A second settle_successful() call for the same transaction must be a silent no-op.' );
	}

	public function test_claim_for_settlement_is_the_db_level_guard_a_status_check_alone_cannot_provide(): void {
		// GatewayTransaction::claim_for_settlement() is the atomic UPDATE
		// settle_successful()/settle_failed()/expire() rely on to close the
		// race a plain find()-then-status-check leaves open between two
		// near-simultaneous webhook deliveries — this exercises the
		// guard directly, independent of the in-memory short-circuit
		// already covered by test_settle_successful_is_idempotent_against_a_second_delivery().
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$first_claim  = $this->transactions->claim_for_settlement( $result['transaction_id'], GatewayTransaction::STATUS_SUCCESSFUL );
		$second_claim = $this->transactions->claim_for_settlement( $result['transaction_id'], GatewayTransaction::STATUS_SUCCESSFUL );

		$this->assertTrue( $first_claim, 'The first claim on an unresolved transaction must win.' );
		$this->assertFalse( $second_claim, 'A second claim on an already-resolved transaction must lose.' );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_SUCCESSFUL, $transaction['status'] );
	}

	public function test_settle_failed_marks_the_transaction_failed_and_is_idempotent(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->service->settle_failed( $result['transaction_id'], 'declined by user' );
		$this->service->settle_failed( $result['transaction_id'], 'declined by user' );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_FAILED, $transaction['status'] );
		$this->assertSame( [], $this->payments->for_charge( $charge_id ), 'A failed transaction must never produce a payment row.' );
	}

	public function test_expire_marks_the_transaction_expired_not_failed(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->service->expire( $result['transaction_id'] );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_EXPIRED, $transaction['status'] );
	}

	public function test_reconcile_unresolved_settles_a_transaction_the_gateway_now_reports_successful(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		// Backdate so it's past the 15-minute "unresolved" threshold.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'rm_gateway_transactions', [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * 60 ) ], [ 'id' => $result['transaction_id'] ] );

		$this->client->status_to_report = GatewayTransaction::STATUS_SUCCESSFUL;

		$outcome = $this->service->reconcile_unresolved();

		$this->assertSame( 1, $outcome['settled'] );
		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_SUCCESSFUL, $transaction['status'] );
	}

	public function test_reconcile_unresolved_expires_a_transaction_past_24_hours_without_polling(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'rm_gateway_transactions', [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ], [ 'id' => $result['transaction_id'] ] );

		$outcome = $this->service->reconcile_unresolved();

		$this->assertSame( 1, $outcome['expired'] );
		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_EXPIRED, $transaction['status'] );
	}

	public function test_reconcile_unresolved_leaves_a_still_processing_transaction_alone(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'rm_gateway_transactions', [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * 60 ) ], [ 'id' => $result['transaction_id'] ] );

		$this->client->status_to_report = GatewayTransaction::STATUS_PROCESSING;

		$outcome = $this->service->reconcile_unresolved();

		$this->assertSame( 0, $outcome['settled'] );
		$this->assertSame( 0, $outcome['expired'] );
		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_PROCESSING, $transaction['status'] );
	}

	public function test_process_webhook_event_routes_completed_to_settle_successful(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->service->process_webhook_event( $result['transaction_id'], 'collection.completed', '{"event":"collection.completed"}' );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_SUCCESSFUL, $transaction['status'] );
		$this->assertSame( '{"event":"collection.completed"}', $transaction['raw_webhook_payload'] );
	}

	public function test_process_webhook_event_routes_failed_to_settle_failed(): void {
		$charge_id = $this->insert_charge( 200000.0 );
		$result    = $this->service->initiate_collection( $this->lease_id, $charge_id, 200000.0, '0700000000', GatewayTransaction::INITIATED_BY_TENANT, 1 );

		$this->service->process_webhook_event( $result['transaction_id'], 'collection.failed', '{"event":"collection.failed"}' );

		$transaction = $this->transactions->find( $result['transaction_id'] );
		$this->assertSame( GatewayTransaction::STATUS_FAILED, $transaction['status'] );
	}
}
