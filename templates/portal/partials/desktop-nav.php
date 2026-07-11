<?php
/**
 * Desktop/tablet top nav (designs/29's "desktop responsive" reference) —
 * hidden on phone via portal.css, shown at the 768px breakpoint.
 *
 * Variables in scope: $active (string: 'home'|'lease'|'payments'|''),
 * $property_name (string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Portal\PortalShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$portal_url = ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_PORTAL );

$nav_links = array(
	PortalShortcode::VIEW_HOME     => array( __( 'Home', 'chrx-rental-manager' ), $portal_url ),
	PortalShortcode::VIEW_LEASE    => array( __( 'My Lease', 'chrx-rental-manager' ), add_query_arg( 'rm_view', PortalShortcode::VIEW_LEASE, $portal_url ) ),
	PortalShortcode::VIEW_PAYMENTS => array( __( 'Payments', 'chrx-rental-manager' ), add_query_arg( 'rm_view', PortalShortcode::VIEW_PAYMENTS, $portal_url ) ),
);
?>
<div class="chrx-rm-portal__desktop-nav">
	<div class="chrx-rm-portal__desktop-nav-brand"><?php echo esc_html( '' !== $property_name ? $property_name : get_bloginfo( 'name' ) ); ?></div>
	<div class="chrx-rm-portal__desktop-nav-links">
		<?php foreach ( $nav_links as $key => list( $label, $url ) ) : ?>
			<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $active === $key ? 'chrx-rm-portal__desktop-nav-link--active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
		<a href="<?php echo esc_url( wp_logout_url( $portal_url ) ); ?>"><?php esc_html_e( 'Log out', 'chrx-rental-manager' ); ?></a>
	</div>
</div>
