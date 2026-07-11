<?php
/**
 * Mobile "‹ Page title" banner for portal sub-pages (designs/30, /31, /32).
 *
 * Variables in scope: $page_title (string), $property_name (string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$portal_url = ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_PORTAL );
?>
<div class="chrx-rm-portal__mobile-header">
	<div class="chrx-rm-portal__mobile-header-top" style="margin-bottom:14px;justify-content:space-between;">
		<span></span>
		<span><?php echo esc_html( $property_name ); ?></span>
	</div>
	<div class="chrx-rm-portal__mobile-header-top">
		<a href="<?php echo esc_url( $portal_url ); ?>" class="chrx-rm-portal__back" aria-label="<?php esc_attr_e( 'Back', 'chrx-rental-manager' ); ?>">‹</a>
		<span class="chrx-rm-portal__mobile-header-title"><?php echo esc_html( $page_title ); ?></span>
	</div>
</div>
