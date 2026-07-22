<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Admin\StaffRolesController;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\Notifier;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `rm_send_renewal_reminders` (SPEC.md §4.2/§5/§6, daily): scans active
 * leases against the configured thresholds (default 30/14/7 days) and
 * emails staff + the property's landlord-owner, plus the tenant if the
 * account-wide "notify tenant" setting is on. Each threshold is deduped
 * independently via rm_notifications_log so a lease that crosses several
 * thresholds between cron runs (e.g. site was offline a few days) still
 * gets every reminder it missed, but never the same one twice.
 *
 * days_until_expiry() is a pure static method (no DB) so the boundary
 * case — "same-day renewal suppresses the reminder" — is testable by
 * asserting on lease status directly, without needing to simulate cron
 * timing.
 */
final class RenewalReminder {

	private Lease $leases;
	private Unit $units;
	private Property $properties;
	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private Tenant $tenants;
	private NotificationLog $notifications;
	private Notifier $notifier;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?Property $properties = null,
		?PropertyStaff $property_staff = null,
		?PropertyLandlord $property_landlords = null,
		?Tenant $tenants = null,
		?NotificationLog $notifications = null,
		?Notifier $notifier = null
	) {
		$this->leases             = $leases ?? new Lease();
		$this->units              = $units ?? new Unit();
		$this->properties         = $properties ?? new Property();
		$this->property_staff     = $property_staff ?? new PropertyStaff();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
		$this->tenants            = $tenants ?? new Tenant();
		$this->notifications      = $notifications ?? new NotificationLog();
		$this->notifier           = $notifier ?? new Notifier( $this->notifications );
	}

	/**
	 * @return int number of reminder emails sent (across all recipients/thresholds)
	 */
	public function send_due_reminders(): int {
		$today = current_time( 'Y-m-d' ); // WP site timezone, per SPEC.md §4.2/§7.
		$sent  = 0;

		// A cron run that started before a same-day renewal must not act
		// on a stale in-memory copy of the lease — re-fetching per lease
		// (rather than looping a snapshot) keeps this correct even for a
		// long-running cron pass, matching SPEC.md §4.2's "cron checks
		// status = active before sending" rule.
		foreach ( $this->leases->all_with_status( Lease::STATUS_ACTIVE ) as $lease_snapshot ) {
			$lease = $this->leases->find( (int) $lease_snapshot['id'] );

			if ( null === $lease || Lease::STATUS_ACTIVE !== $lease['status'] ) {
				continue; // Renewed/ended since the snapshot was taken.
			}

			$days_until_expiry = self::days_until_expiry( $lease['end_date'], $today );

			foreach ( Settings::reminder_thresholds() as $threshold ) {
				if ( ! self::is_due( $days_until_expiry, $threshold ) ) {
					continue;
				}

				$type = 'lease_expiring_' . $threshold;

				if ( $this->notifications->already_sent( $type, (int) $lease['id'] ) ) {
					continue;
				}

				$sent += $this->notify( $lease, $type, $threshold );
			}
		}

		return $sent;
	}

	/**
	 * Pure: how many whole days remain until $end_date, from $today.
	 * Negative once the lease has already expired.
	 */
	public static function days_until_expiry( string $end_date, string $today ): int {
		$end = new \DateTimeImmutable( $end_date );
		$now = new \DateTimeImmutable( $today );

		return (int) $now->diff( $end )->format( '%r%a' );
	}

	/**
	 * Pure: a threshold is "due" once the lease is at or inside that
	 * window, but the lease must not have already expired outright —
	 * expired-and-not-renewed is the dashboard's "flag it" case (SPEC.md
	 * §4.2 edge case), not another reminder email.
	 */
	public static function is_due( int $days_until_expiry, int $threshold ): bool {
		return $days_until_expiry >= 0 && $days_until_expiry <= $threshold;
	}

	/**
	 * @param array<string,mixed> $lease
	 */
	private function notify( array $lease, string $type, int $threshold ): int {
		$unit     = $this->units->find( (int) $lease['unit_id'] );
		$tenant   = $this->tenants->find( (int) $lease['tenant_id'] );
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		$sent = 0;

		$recipients = array();

		if ( null !== $unit ) {
			foreach ( $this->property_staff->user_ids_for_property( (int) $unit['property_id'] ) as $user_id ) {
				$recipients[] = get_userdata( $user_id );
			}

			foreach ( $this->property_landlords->user_ids_for_property( (int) $unit['property_id'] ) as $user_id ) {
				$recipients[] = get_userdata( $user_id );
			}
		}

		foreach ( $recipients as $user ) {
			if ( false === $user || '' === $user->user_email ) {
				continue;
			}

			$sent += $this->send_email(
				array(
					'email'           => $user->user_email,
					'whatsapp_number' => get_user_meta( $user->ID, StaffRolesController::WHATSAPP_META_KEY, true ),
				),
				$lease,
				$tenant,
				$unit,
				$property,
				$threshold,
				false,
				$type
			);
		}

		if ( Settings::reminder_notify_tenant() && null !== $tenant && '' !== (string) $tenant['email'] ) {
			$sent += $this->send_email(
				array(
					'email'           => $tenant['email'],
					'whatsapp_number' => $tenant['whatsapp_number'] ?? '',
				),
				$lease,
				$tenant,
				$unit,
				$property,
				$threshold,
				true,
				$type
			);
		}

		if ( array() === $recipients && ( ! Settings::reminder_notify_tenant() || null === $tenant ) ) {
			// Nobody to notify, but still record the threshold as
			// "handled" so a property with no assigned staff/landlord
			// doesn't get re-evaluated (and fail to notify) every day.
			$this->notifications->record( $type, '(no recipients)', (int) $lease['id'], NotificationLog::STATUS_SKIPPED );
		}

		return $sent;
	}

	/**
	 * @param array<string,mixed>      $recipient ['email' => string, 'whatsapp_number' => string]
	 * @param array<string,mixed>      $lease
	 * @param array<string,mixed>|null $tenant
	 * @param array<string,mixed>|null $unit
	 * @param array<string,mixed>|null $property
	 */
	private function send_email( array $recipient, array $lease, ?array $tenant, ?array $unit, ?array $property, int $threshold, bool $is_tenant_copy, string $type ): int {
		$tenant_name   = null === $tenant ? '' : $tenant['full_name'];
		$unit_label    = null === $unit ? '' : $unit['unit_label'];
		$property_name = null === $property ? '' : $property['name'];
		$end_date      = gmdate( 'j F Y', strtotime( $lease['end_date'] ) );

		$subject = sprintf(
			/* translators: 1: days, 2: tenant name */
			__( 'Lease expiring in %1$d days — %2$s', 'chrx-rental-manager' ),
			$threshold,
			$tenant_name
		);

		$message = $is_tenant_copy
			? sprintf(
				/* translators: 1: days, 2: end date */
				__( "Your lease is expiring in %1\$d days, on %2\$s. Please contact your property manager if you'd like to discuss renewal.", 'chrx-rental-manager' ),
				$threshold,
				$end_date
			)
			: sprintf(
				/* translators: 1: tenant name, 2: unit label, 3: property name, 4: days, 5: end date */
				__( "%1\$s's lease for %2\$s, %3\$s is expiring in %4\$d days, on %5\$s.", 'chrx-rental-manager' ),
				$tenant_name,
				$unit_label,
				$property_name,
				$threshold,
				$end_date
			);

		// This method's int return (used only to accumulate a "how many
		// sent" count for the cron's caller) previously came from
		// wp_mail()'s own return regardless of what NotificationLog
		// recorded; the v1 code recorded STATUS_SENT unconditionally
		// instead, a pre-existing bug — Notifier::notify() below now logs
		// the real per-channel result, so this return value and the logged
		// status finally agree.
		$sent = $this->notifier->notify(
			$type,
			(int) $lease['id'],
			$recipient,
			$subject,
			$message,
			Settings::TEMPLATE_KEY_RENEWAL_REMINDER,
			array( $tenant_name, $unit_label, $property_name, (string) $threshold, $end_date )
		);

		return $sent ? 1 : 0;
	}
}
