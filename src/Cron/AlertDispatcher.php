<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Admin\StaffRolesController;
use ChrxRentalManager\Admin\Support\Settings;
use ChrxRentalManager\Communications\EmailChannel;
use ChrxRentalManager\Communications\MessageChannel;
use ChrxRentalManager\Communications\PortalChannel;
use ChrxRentalManager\Communications\WhatsAppChannel;
use ChrxRentalManager\Data\Alert;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `rm_dispatch_custom_alerts` (SPEC.md §4.8/§6, every 15 minutes — the
 * hook itself and its custom schedule were already wired as a no-op stub
 * in V2-0). Recipients are resolved fresh on every dispatch, never a
 * creation-time snapshot (SPEC.md §4.8: "current tenants of the unit/
 * property — not a snapshot from creation time").
 *
 * Overlap safety: unlike the daily crons, a 15-minute interval leaves no
 * slack for a slow run to finish before the next one starts, so dispatch()
 * takes a short transient-based mutex around the whole run — the first
 * precedent for cron-overlap locking in this codebase (no prior job needed
 * one). Per-alert dedupe is claim-then-send: mark_sent() is called before
 * resolving/notifying recipients, so a second overlapping run's is_due()
 * check (which re-reads last_sent_at) already sees the alert as handled
 * even if the mutex were somehow bypassed.
 */
final class AlertDispatcher {

	private const LOCK_KEY = 'chrx_rm_alert_dispatch_lock';
	private const LOCK_TTL = 120; // Seconds — a safety net well under the 15-minute interval, not the primary dedupe mechanism.

	private Alert $alerts;
	private Unit $units;
	private Tenant $tenants;
	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private NotificationLog $notifications;
	private MessageChannel $email_channel;
	private MessageChannel $whatsapp_channel;
	private MessageChannel $portal_channel;

	public function __construct(
		?Alert $alerts = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?PropertyStaff $property_staff = null,
		?PropertyLandlord $property_landlords = null,
		?NotificationLog $notifications = null,
		?MessageChannel $email_channel = null,
		?MessageChannel $whatsapp_channel = null,
		?MessageChannel $portal_channel = null
	) {
		$this->alerts             = $alerts ?? new Alert();
		$this->units              = $units ?? new Unit();
		$this->tenants            = $tenants ?? new Tenant();
		$this->property_staff     = $property_staff ?? new PropertyStaff();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
		$this->notifications      = $notifications ?? new NotificationLog();
		$this->email_channel      = $email_channel ?? new EmailChannel();
		$this->whatsapp_channel   = $whatsapp_channel ?? new WhatsAppChannel();
		$this->portal_channel     = $portal_channel ?? new PortalChannel();
	}

	/**
	 * Active, portal-channel alerts that have started and are addressed to
	 * this viewer — the single query both Admin\DashboardController and
	 * Portal\PortalShortcode call for their banner display (SPEC.md §4.8),
	 * so the scoping/recipient-matching logic exists in exactly one place.
	 *
	 * @param array<int,int>|null $restrict_to_property_ids Access::accessiblePropertyIds()'s
	 *               result — null (Administrator) sees account-wide alerts
	 *               too; a restricted array never does, mirroring every
	 *               other account-scoped-entity rule in this codebase.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function banners_for( ?array $restrict_to_property_ids, ?int $tenant_id, ?int $wp_user_id ): array {
		$now     = current_time( 'mysql' );
		$banners = array();

		foreach ( $this->alerts->active() as $alert ) {
			if ( ! in_array( NotificationLog::CHANNEL_PORTAL, (array) $alert['channels'], true ) ) {
				continue;
			}

			if ( ! self::has_occurred( $alert, $now ) ) {
				continue;
			}

			if ( Alert::ENTITY_NONE === $alert['entity_type'] ) {
				if ( null !== $restrict_to_property_ids ) {
					continue; // Account-wide alerts are Administrator-only visibility.
				}
			} else {
				$property_id = $this->property_id_of( $alert );

				if ( null !== $restrict_to_property_ids && ( null === $property_id || ! in_array( $property_id, $restrict_to_property_ids, true ) ) ) {
					continue;
				}
			}

			if ( ! $this->is_recipient_of( $alert, $tenant_id, $wp_user_id ) ) {
				continue;
			}

			$banners[] = $alert;
		}

		return $banners;
	}

	/**
	 * @return array{sent:int,locked:bool}
	 */
	public function dispatch(): array {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return array(
				'sent'   => 0,
				'locked' => true,
			);
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$now  = current_time( 'mysql' );
			$sent = 0;

			foreach ( $this->alerts->active() as $alert ) {
				if ( ! self::is_due( $alert, $now ) ) {
					continue;
				}

				// Claim before sending (see class docblock) — the atomic
				// step that makes a concurrent overlapping run's due-ness
				// check see this alert as already handled.
				$this->alerts->mark_sent( (int) $alert['id'] );

				$this->send_alert( $alert );

				if ( Alert::SCHEDULE_ONCE === $alert['schedule_type'] ) {
					$this->alerts->deactivate( (int) $alert['id'] );
				}

				++$sent;
			}

			return array(
				'sent'   => $sent,
				'locked' => false,
			);
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Whether $alert's schedule has reached its first/most recent
	 * occurrence at or before $now — used by the portal/dashboard banner
	 * renderers (SPEC.md §4.8: "due alerts with the portal channel render
	 * as banners"), which need "has this started" rather than is_due()'s
	 * "is there unsent work right now" (a banner should keep showing
	 * between cron runs, not disappear the instant last_sent_at is
	 * stamped).
	 *
	 * @param array<string,mixed> $alert
	 */
	public static function has_occurred( array $alert, string $now_mysql ): bool {
		if ( empty( $alert['scheduled_at'] ) ) {
			return false;
		}

		$now          = new \DateTimeImmutable( $now_mysql );
		$scheduled_at = new \DateTimeImmutable( $alert['scheduled_at'] );
		$occurrence   = self::last_occurrence_at_or_before( (string) $alert['schedule_type'], $scheduled_at, $now );

		return null !== $occurrence && $occurrence <= $now;
	}

	/**
	 * Whether the resolved recipient set for $alert includes this tenant
	 * and/or WP user — the banner renderers' viewer-scoping check, reusing
	 * the exact same recipient-resolution logic dispatch() uses so a
	 * banner never shows to someone who wouldn't have received the actual
	 * notification.
	 *
	 * @param array<string,mixed> $alert
	 */
	public function is_recipient_of( array $alert, ?int $tenant_id, ?int $wp_user_id ): bool {
		foreach ( $this->resolve_recipients( $alert ) as $recipient ) {
			if ( null !== $tenant_id && $recipient['tenant_id'] === $tenant_id ) {
				return true;
			}

			if ( null !== $wp_user_id && $recipient['user_id'] === $wp_user_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The property id $alert is scoped to (null for ENTITY_NONE or an
	 * unresolvable unit) — used by the banner renderers to apply the same
	 * Access::accessiblePropertyIds() restriction every other scoped query
	 * in this codebase uses.
	 *
	 * @param array<string,mixed> $alert
	 */
	public function property_id_of( array $alert ): ?int {
		$ids = $this->property_ids_of_entity( (string) $alert['entity_type'], null !== $alert['entity_id'] ? (int) $alert['entity_id'] : null );

		return $ids[0] ?? null;
	}

	/**
	 * Pure schedule due-ness math (no DB) — mirrors ChargeGenerator's split
	 * between date logic and its DB-facing wrapper, so this is
	 * unit-testable without a database.
	 *
	 * @param array<string,mixed> $alert
	 */
	public static function is_due( array $alert, string $now_mysql ): bool {
		if ( empty( $alert['scheduled_at'] ) ) {
			return false;
		}

		$now          = new \DateTimeImmutable( $now_mysql );
		$scheduled_at = new \DateTimeImmutable( $alert['scheduled_at'] );
		$occurrence   = self::last_occurrence_at_or_before( (string) $alert['schedule_type'], $scheduled_at, $now );

		if ( null === $occurrence || $occurrence > $now ) {
			return false;
		}

		$last_sent_at = $alert['last_sent_at'] ?? null;

		if ( Alert::SCHEDULE_ONCE === $alert['schedule_type'] ) {
			return null === $last_sent_at || '' === $last_sent_at;
		}

		if ( null === $last_sent_at || '' === $last_sent_at ) {
			return true;
		}

		return new \DateTimeImmutable( (string) $last_sent_at ) < $occurrence;
	}

	/**
	 * The most recent occurrence of $schedule_type's recurrence pattern
	 * (anchored on $scheduled_at's time-of-day, and for weekly/monthly its
	 * weekday/day-of-month) that falls at or before $now. Null for an
	 * unrecognized schedule type.
	 */
	private static function last_occurrence_at_or_before( string $schedule_type, \DateTimeImmutable $scheduled_at, \DateTimeImmutable $now ): ?\DateTimeImmutable {
		switch ( $schedule_type ) {
			case Alert::SCHEDULE_ONCE:
				return $scheduled_at;

			case Alert::SCHEDULE_DAILY:
				$candidate = self::at_time( $now, $scheduled_at );

				return $candidate > $now ? $candidate->modify( '-1 day' ) : $candidate;

			case Alert::SCHEDULE_WEEKLY:
				$target_weekday  = (int) $scheduled_at->format( 'N' );
				$current_weekday = (int) $now->format( 'N' );
				$days_back       = $current_weekday - $target_weekday;

				if ( $days_back < 0 ) {
					$days_back += 7;
				}

				$candidate = self::at_time( $now->modify( "-{$days_back} days" ), $scheduled_at );

				return $candidate > $now ? $candidate->modify( '-7 days' ) : $candidate;

			case Alert::SCHEDULE_MONTHLY:
				$candidate = self::at_time( self::clamp_day( $now, (int) $scheduled_at->format( 'j' ) ), $scheduled_at );

				if ( $candidate > $now ) {
					$previous_month = $now->modify( 'first day of last month' );
					$candidate      = self::at_time( self::clamp_day( $previous_month, (int) $scheduled_at->format( 'j' ) ), $scheduled_at );
				}

				return $candidate;

			default:
				return null;
		}
	}

	private static function at_time( \DateTimeImmutable $date, \DateTimeImmutable $time_source ): \DateTimeImmutable {
		return $date->setTime( (int) $time_source->format( 'H' ), (int) $time_source->format( 'i' ), 0 );
	}

	/**
	 * Clamps a target day-of-month to $month's actual length — same
	 * approach as ChargeGenerator::clamp_to_month(), for a monthly alert
	 * anchored on e.g. the 31st in a 30-day month.
	 */
	private static function clamp_day( \DateTimeImmutable $month, int $day ): \DateTimeImmutable {
		$last_day = (int) $month->format( 't' );

		return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), min( $day, $last_day ) );
	}

	/**
	 * @param array<string,mixed> $alert
	 */
	private function send_alert( array $alert ): void {
		$recipients = $this->resolve_recipients( $alert );
		$channels   = (array) $alert['channels'];

		if ( array() === $recipients ) {
			// SPEC.md §4.8 edge case: "alert attached to a unit that becomes
			// vacant... the alert still sends to any staff/landlord
			// recipients and logs the empty tenant set rather than
			// erroring" — generalized here to "no eligible recipients at
			// all resolved," logged so the alert doesn't look silently
			// broken, not treated as a failure.
			$this->notifications->record( 'custom_alert', '', (int) $alert['id'], NotificationLog::STATUS_SKIPPED, NotificationLog::CHANNEL_EMAIL, 'No eligible recipients resolved.' );

			return;
		}

		foreach ( $recipients as $recipient ) {
			foreach ( $channels as $channel_key ) {
				$this->send_one( $alert, $recipient, (string) $channel_key );
			}
		}
	}

	/**
	 * @param array<string,mixed> $alert
	 * @param array<string,mixed> $recipient
	 */
	private function send_one( array $alert, array $recipient, string $channel_key ): void {
		$channel = $this->channel_for( $channel_key );

		if ( null === $channel ) {
			return;
		}

		if ( NotificationLog::CHANNEL_WHATSAPP === $channel_key ) {
			$identifier = (string) ( $recipient['whatsapp_number'] ?? '' );

			if ( '' === $identifier ) {
				return; // No number on file — not a failure, not logged, same convention as Communications\Notifier.
			}
		} elseif ( NotificationLog::CHANNEL_PORTAL === $channel_key ) {
			$identifier = null !== $recipient['tenant_id'] ? 'tenant:' . $recipient['tenant_id'] : 'user:' . $recipient['user_id'];
		} else {
			$identifier = (string) ( $recipient['email'] ?? '' );

			if ( '' === $identifier ) {
				$this->notifications->record( 'custom_alert', '', (int) $alert['id'], NotificationLog::STATUS_SKIPPED, $channel_key );

				return;
			}
		}

		try {
			$channel->send(
				'custom_alert',
				$recipient,
				array(
					'subject'      => $alert['title'],
					'body'         => $alert['message'],
					'attachments'  => array(),
					'template_key' => Settings::TEMPLATE_KEY_CUSTOM_ALERT,
					'context'      => array( $alert['message'] ),
				)
			);

			$this->notifications->record( 'custom_alert', $identifier, (int) $alert['id'], NotificationLog::STATUS_SENT, $channel_key );
		} catch ( \Throwable $e ) {
			$this->notifications->record( 'custom_alert', $identifier, (int) $alert['id'], NotificationLog::STATUS_FAILED, $channel_key, $e->getMessage() );
		}
	}

	private function channel_for( string $channel_key ): ?MessageChannel {
		return match ( $channel_key ) {
			NotificationLog::CHANNEL_EMAIL => $this->email_channel,
			NotificationLog::CHANNEL_WHATSAPP => $this->whatsapp_channel,
			NotificationLog::CHANNEL_PORTAL => $this->portal_channel,
			default => null,
		};
	}

	/**
	 * @param array<string,mixed> $alert
	 *
	 * @return array<int,array{email:?string,whatsapp_number:?string,user_id:?int,tenant_id:?int}>
	 */
	private function resolve_recipients( array $alert ): array {
		$selectors         = (array) ( $alert['recipients']['selectors'] ?? array() );
		$explicit_user_ids = array_map( 'intval', (array) ( $alert['recipients']['user_ids'] ?? array() ) );
		$entity_type       = (string) $alert['entity_type'];
		$entity_id         = null !== $alert['entity_id'] ? (int) $alert['entity_id'] : null;
		$recipients        = array();

		if ( in_array( Alert::RECIPIENT_TENANTS_OF_ENTITY, $selectors, true ) ) {
			foreach ( $this->tenants_of_entity( $entity_type, $entity_id ) as $tenant ) {
				$recipients[] = $this->tenant_recipient( $tenant );
			}
		}

		if ( in_array( Alert::RECIPIENT_STAFF_OF_ENTITY, $selectors, true ) ) {
			foreach ( $this->property_ids_of_entity( $entity_type, $entity_id ) as $property_id ) {
				foreach ( $this->property_staff->user_ids_for_property( $property_id ) as $user_id ) {
					$recipients[] = $this->user_recipient( $user_id );
				}
			}
		}

		if ( in_array( Alert::RECIPIENT_LANDLORD_OF_ENTITY, $selectors, true ) ) {
			foreach ( $this->property_ids_of_entity( $entity_type, $entity_id ) as $property_id ) {
				foreach ( $this->property_landlords->user_ids_for_property( $property_id ) as $user_id ) {
					$recipients[] = $this->user_recipient( $user_id );
				}
			}
		}

		if ( in_array( Alert::RECIPIENT_SELF, $selectors, true ) ) {
			$recipients[] = $this->user_recipient( (int) $alert['created_by'] );
		}

		foreach ( $explicit_user_ids as $user_id ) {
			$recipients[] = $this->user_recipient( $user_id );
		}

		return $this->dedupe_recipients( $recipients );
	}

	/**
	 * @return array<int,int>
	 */
	private function property_ids_of_entity( string $entity_type, ?int $entity_id ): array {
		if ( Alert::ENTITY_PROPERTY === $entity_type && null !== $entity_id ) {
			return array( $entity_id );
		}

		if ( Alert::ENTITY_UNIT === $entity_type && null !== $entity_id ) {
			$unit = $this->units->find( $entity_id );

			return null === $unit ? array() : array( (int) $unit['property_id'] );
		}

		return array(); // ENTITY_NONE (account-level): no property to derive staff/landlord from.
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function tenants_of_entity( string $entity_type, ?int $entity_id ): array {
		if ( null === $entity_id ) {
			return array();
		}

		if ( Alert::ENTITY_UNIT === $entity_type ) {
			return $this->tenants_of_unit( $entity_id );
		}

		if ( Alert::ENTITY_PROPERTY === $entity_type ) {
			$tenants = array();

			foreach ( $this->units->for_property( $entity_id ) as $unit ) {
				$tenants = array_merge( $tenants, $this->tenants_of_unit( (int) $unit['id'] ) );
			}

			return $tenants;
		}

		return array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function tenants_of_unit( int $unit_id ): array {
		$leases  = new \ChrxRentalManager\Data\Lease( $this->units );
		$tenants = array();

		foreach ( $leases->active_leases_for_unit( $unit_id ) as $lease ) {
			$tenant = $this->tenants->find( (int) $lease['tenant_id'] );

			if ( null !== $tenant ) {
				$tenants[] = $tenant;
			}
		}

		return $tenants;
	}

	/**
	 * @param array<string,mixed> $tenant
	 *
	 * @return array{email:?string,whatsapp_number:?string,user_id:?int,tenant_id:?int}
	 */
	private function tenant_recipient( array $tenant ): array {
		return array(
			'email'           => '' !== (string) ( $tenant['email'] ?? '' ) ? (string) $tenant['email'] : null,
			'whatsapp_number' => '' !== (string) ( $tenant['whatsapp_number'] ?? '' ) ? (string) $tenant['whatsapp_number'] : null,
			'user_id'         => null !== ( $tenant['wp_user_id'] ?? null ) ? (int) $tenant['wp_user_id'] : null,
			'tenant_id'       => (int) $tenant['id'],
		);
	}

	/**
	 * @return array{email:?string,whatsapp_number:?string,user_id:?int,tenant_id:?int}
	 */
	private function user_recipient( int $user_id ): array {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return array(
				'email'           => null,
				'whatsapp_number' => null,
				'user_id'         => $user_id,
				'tenant_id'       => null,
			);
		}

		$whatsapp_number = (string) get_user_meta( $user_id, StaffRolesController::WHATSAPP_META_KEY, true );

		return array(
			'email'           => '' !== $user->user_email ? $user->user_email : null,
			'whatsapp_number' => '' !== $whatsapp_number ? $whatsapp_number : null,
			'user_id'         => $user_id,
			'tenant_id'       => null,
		);
	}

	/**
	 * @param array<int,array{email:?string,whatsapp_number:?string,user_id:?int,tenant_id:?int}> $recipients
	 *
	 * @return array<int,array{email:?string,whatsapp_number:?string,user_id:?int,tenant_id:?int}>
	 */
	private function dedupe_recipients( array $recipients ): array {
		$seen   = array();
		$result = array();

		foreach ( $recipients as $recipient ) {
			$key = null !== $recipient['tenant_id']
				? 't' . $recipient['tenant_id']
				: ( null !== $recipient['user_id'] ? 'u' . $recipient['user_id'] : 'e' . ( $recipient['email'] ?? '' ) );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$result[]     = $recipient;
		}

		return $result;
	}
}
