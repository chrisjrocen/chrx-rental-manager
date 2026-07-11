<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small display-formatting helpers shared by the portal templates
 * (designs/29, /30 both show a billing day as "1st", "2nd", etc.).
 */
final class PortalFormat {

	public static function ordinal( int $day ): string {
		if ( in_array( $day % 100, array( 11, 12, 13 ), true ) ) {
			return $day . 'th';
		}

		return $day . ( array( 'th', 'st', 'nd', 'rd' )[ $day % 10 ] ?? 'th' );
	}
}
