<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derives a tenant's portal-access status for display (designs/11's
 * "Portal" column, designs/12's "Portal access" panel) — there's no
 * dedicated status column for this; it's inferred from the linked WP
 * user's state. wp_set_password() (called by WP core's reset_password(),
 * which both the invite and forgot-password flows use) clears
 * user_activation_key to an empty string, so a non-empty key reliably
 * means "invited but never completed setup".
 */
final class PortalStatus {

	public const NOT_INVITED = 'not_invited';
	public const INVITED     = 'invited';
	public const ACTIVE      = 'active';

	/**
	 * @param array<string,mixed> $tenant
	 */
	public static function for_tenant( array $tenant ): string {
		if ( null === $tenant['wp_user_id'] || '' === (string) $tenant['wp_user_id'] ) {
			return self::NOT_INVITED;
		}

		$user = get_userdata( (int) $tenant['wp_user_id'] );

		if ( false === $user ) {
			return self::NOT_INVITED;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading the users table column directly; no repository exists for WP core's own users table.
		global $wpdb;
		$key_column = $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $user->ID ) );

		return '' !== (string) $key_column ? self::INVITED : self::ACTIVE;
	}

	public static function label( string $status ): string {
		return match ( $status ) {
			self::ACTIVE => __( 'Active', 'chrx-rental-manager' ),
			self::INVITED => __( 'Invited', 'chrx-rental-manager' ),
			default => __( 'Not invited', 'chrx-rental-manager' ),
		};
	}

	public static function badge_key( string $status ): string {
		return match ( $status ) {
			self::ACTIVE => 'active',
			self::INVITED => 'maintenance',
			default => 'vacant',
		};
	}
}
