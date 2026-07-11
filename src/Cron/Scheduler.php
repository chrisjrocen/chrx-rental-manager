<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the three WP-Cron jobs (SPEC.md §6). Scheduling happens on
 * activation and is cleared on deactivation; the hook callbacks
 * themselves are registered on every request via register() so they run
 * whether or not the site owner has set up a real system cron hitting
 * wp-cron.php (README documents that recommendation for low-traffic
 * sites, per SPEC.md §6).
 */
final class Scheduler {

	public const HOOK_GENERATE_CHARGES = 'rm_generate_monthly_charges';
	public const HOOK_SEND_REMINDERS   = 'rm_send_renewal_reminders';
	public const HOOK_APPLY_LATE_FEES  = 'rm_apply_late_fees';

	private ChargeGenerator $charge_generator;
	private RenewalReminder $renewal_reminder;
	private LateFeeApplier $late_fee_applier;

	public function __construct(
		?ChargeGenerator $charge_generator = null,
		?RenewalReminder $renewal_reminder = null,
		?LateFeeApplier $late_fee_applier = null
	) {
		$this->charge_generator = $charge_generator ?? new ChargeGenerator();
		$this->renewal_reminder = $renewal_reminder ?? new RenewalReminder();
		$this->late_fee_applier = $late_fee_applier ?? new LateFeeApplier();
	}

	public function register(): void {
		add_action( self::HOOK_GENERATE_CHARGES, array( $this->charge_generator, 'generate' ) );
		add_action( self::HOOK_SEND_REMINDERS, array( $this->renewal_reminder, 'send_due_reminders' ) );
		add_action( self::HOOK_APPLY_LATE_FEES, array( $this->late_fee_applier, 'apply' ) );
	}

	/**
	 * Called on plugin activation. wp_schedule_event() is a no-op if the
	 * hook is already scheduled, so this is safe to call repeatedly.
	 */
	public function schedule_events(): void {
		foreach ( $this->hooks() as $hook ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), 'daily', $hook );
			}
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
	private function hooks(): array {
		return array( self::HOOK_GENERATE_CHARGES, self::HOOK_SEND_REMINDERS, self::HOOK_APPLY_LATE_FEES );
	}
}
