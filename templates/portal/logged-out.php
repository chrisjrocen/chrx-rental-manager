<?php
/**
 * [rental_portal] rendered for a visitor who isn't logged in at all.
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$login_url = ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_LOGIN );
?>
<div class="chrx-rm-portal">
	<div class="chrx-rm-portal__logged-out">
		<p><?php esc_html_e( 'Please log in to view your tenant portal.', 'chrx-rental-manager' ); ?></p>
		<p><a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'chrx-rental-manager' ); ?></a></p>
	</div>
</div>
