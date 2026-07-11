<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-lived per-user transient flash message, set right before a
 * redirect and read once on the next page load. Extracted from the
 * pattern proven in StaffRolesController (Roles & Permissions phase) —
 * every entity controller's save handler needs this same shape.
 */
final class FlashNotice {

	public static function set( string $key, string $message ): void {
		set_transient( self::transient_key( $key ), $message, 60 );
	}

	public static function take( string $key ): ?string {
		$transient_key = self::transient_key( $key );
		$notice        = get_transient( $transient_key );

		if ( false === $notice ) {
			return null;
		}

		delete_transient( $transient_key );

		return (string) $notice;
	}

	private static function transient_key( string $key ): string {
		return 'rm_notice_' . $key . '_' . get_current_user_id();
	}
}
