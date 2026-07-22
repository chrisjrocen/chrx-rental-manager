<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WP-Cron jobs (SPEC.md §6). Scheduling happens on
 * activation and is cleared on deactivation; the hook callbacks
 * themselves are registered on every request via register() so they run
 * whether or not the site owner has set up a real system cron hitting
 * wp-cron.php (README documents that recommendation for low-traffic
 * sites, per SPEC.md §6).
 *
 * v2 (SPEC.md §10 item 10, §6): HOOK_GENERATE_CHARGES was renamed from
 * `rm_generate_monthly_charges` (the actual rename/reschedule of any
 * already-installed site's cron event happens in
 * Migrator::migrate_cron_hook(), not here — this class only defines what
 * *should* be scheduled going forward). Three new jobs were registered
 * as no-op stubs in V2-0; V2-4 filled in recurring expenses, V2-5 filled
 * in gateway reconciliation, V2-6 fills in custom alerts below.
 */
final class Scheduler {

	public const HOOK_GENERATE_CHARGES               = 'rm_generate_charges';
	public const HOOK_SEND_REMINDERS                 = 'rm_send_renewal_reminders';
	public const HOOK_APPLY_LATE_FEES                = 'rm_apply_late_fees';
	public const HOOK_GENERATE_RECURRING_EXPENSES    = 'rm_generate_recurring_expenses';
	public const HOOK_DISPATCH_CUSTOM_ALERTS         = 'rm_dispatch_custom_alerts';
	public const HOOK_RECONCILE_GATEWAY_TRANSACTIONS = 'rm_reconcile_gateway_transactions';

	private const SCHEDULE_FIFTEEN_MINUTES = 'chrx_rm_fifteen_minutes';

	private ChargeGenerator $charge_generator;
	private RenewalReminder $renewal_reminder;
	private LateFeeApplier $late_fee_applier;
	private RecurringExpenseGenerator $recurring_expense_generator;
	private GatewayReconciler $gateway_reconciler;
	private AlertDispatcher $alert_dispatcher;

	public function __construct(
		?ChargeGenerator $charge_generator = null,
		?RenewalReminder $renewal_reminder = null,
		?LateFeeApplier $late_fee_applier = null,
		?RecurringExpenseGenerator $recurring_expense_generator = null,
		?GatewayReconciler $gateway_reconciler = null,
		?AlertDispatcher $alert_dispatcher = null
	) {
		$this->charge_generator            = $charge_generator ?? new ChargeGenerator();
		$this->renewal_reminder            = $renewal_reminder ?? new RenewalReminder();
		$this->late_fee_applier            = $late_fee_applier ?? new LateFeeApplier();
		$this->recurring_expense_generator = $recurring_expense_generator ?? new RecurringExpenseGenerator();
		$this->gateway_reconciler          = $gateway_reconciler ?? new GatewayReconciler();
		$this->alert_dispatcher            = $alert_dispatcher ?? new AlertDispatcher();
	}

	/**
	 * Registers the 15-minute schedule WP-Cron doesn't ship by default —
	 * must run early (before wp_schedule_event() calls) on every request,
	 * not just activation, since cron_schedules is consulted whenever
	 * WP-Cron runs, not only when events are (re)scheduled.
	 */
	public function register_custom_schedules(): void {
		add_filter( 'cron_schedules', array( $this, 'add_fifteen_minute_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 *
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function add_fifteen_minute_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE_FIFTEEN_MINUTES ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'chrx-rental-manager' ),
		);

		return $schedules;
	}

	public function register(): void {
		add_action( self::HOOK_GENERATE_CHARGES, array( $this->charge_generator, 'generate' ) );
		add_action( self::HOOK_SEND_REMINDERS, array( $this->renewal_reminder, 'send_due_reminders' ) );
		add_action( self::HOOK_APPLY_LATE_FEES, array( $this->late_fee_applier, 'apply' ) );
		add_action( self::HOOK_GENERATE_RECURRING_EXPENSES, array( $this->recurring_expense_generator, 'generate' ) );
		add_action( self::HOOK_RECONCILE_GATEWAY_TRANSACTIONS, array( $this->gateway_reconciler, 'reconcile' ) );
		add_action( self::HOOK_DISPATCH_CUSTOM_ALERTS, array( $this->alert_dispatcher, 'dispatch' ) );
	}

	/**
	 * Called on plugin activation. wp_schedule_event() is a no-op if the
	 * hook is already scheduled, so this is safe to call repeatedly.
	 */
	public function schedule_events(): void {
		foreach ( $this->daily_hooks() as $hook ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), 'daily', $hook );
			}
		}

		if ( false === wp_next_scheduled( self::HOOK_DISPATCH_CUSTOM_ALERTS ) ) {
			wp_schedule_event( time(), self::SCHEDULE_FIFTEEN_MINUTES, self::HOOK_DISPATCH_CUSTOM_ALERTS );
		}

		if ( false === wp_next_scheduled( self::HOOK_RECONCILE_GATEWAY_TRANSACTIONS ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK_RECONCILE_GATEWAY_TRANSACTIONS );
		}
	}

	/**
	 * Called on plugin deactivation — deactivation leaves data intact
	 * (see chrx-rental-manager.php on_deactivate()) but scheduled cron
	 * events are process-level, not data, so they're cleared here rather
	 * than left to fire against an inactive plugin.
	 */
	public function unschedule_events(): void {
		foreach ( $this->hooks() as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * @return array<int,string>
	 */
	private function daily_hooks(): array {
		return array(
			self::HOOK_GENERATE_CHARGES,
			self::HOOK_SEND_REMINDERS,
			self::HOOK_APPLY_LATE_FEES,
			self::HOOK_GENERATE_RECURRING_EXPENSES,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function hooks(): array {
		return array(
			self::HOOK_GENERATE_CHARGES,
			self::HOOK_SEND_REMINDERS,
			self::HOOK_APPLY_LATE_FEES,
			self::HOOK_GENERATE_RECURRING_EXPENSES,
			self::HOOK_DISPATCH_CUSTOM_ALERTS,
			self::HOOK_RECONCILE_GATEWAY_TRANSACTIONS,
		);
	}
}
