<?php
/**
 * [rental_portal] rendered for a logged-in user who isn't a Tenant (or
 * has no linked rm_tenants record) — e.g. an Admin/Staff/Landlord-Owner
 * who navigates to the portal page directly.
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="chrx-rm-portal">
	<div class="chrx-rm-portal__not-a-tenant">
		<p><?php esc_html_e( 'This page is for tenant accounts only.', 'chrx-rental-manager' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Go to the dashboard', 'chrx-rental-manager' ); ?></a></p>
	</div>
</div>
