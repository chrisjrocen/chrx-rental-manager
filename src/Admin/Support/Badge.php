<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the shared status/badge pill used across every wp-admin screen
 * (designs.html's "Status & badge system" legend) — one rendering point
 * so every entity's status column looks identical.
 */
final class Badge {

	/**
	 * @var array<string,string>
	 */
	private const LABELS = array(
		'occupied'    => 'Occupied',
		'vacant'      => 'Vacant',
		'maintenance' => 'Under Maintenance',
		'reserved'    => 'Reserved',
		'paid'        => 'Paid',
		'partial'     => 'Partial',
		'unpaid'      => 'Unpaid',
		'overdue'     => 'Overdue',
		'waived'      => 'Waived',
		'active'      => 'Active',
		'ended'       => 'Ended',
		'renewed'     => 'Renewed',
		'former'      => 'Former',
		'recorded'    => 'Recorded',
		'voided'      => 'Voided',
	);

	public static function render( string $status, ?string $label = null ): string {
		$label = $label ?? ( self::LABELS[ $status ] ?? ucfirst( $status ) );

		return sprintf(
			'<span class="chrx-rm-badge chrx-rm-badge--%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}
}
