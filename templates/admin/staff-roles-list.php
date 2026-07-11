<?php
/**
 * Staff & Roles list screen (designs/25-staff-roles.html).
 * Variables in scope: $list_table (StaffRolesListTable), $add_url (string),
 * $notice (?string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Staff & Roles', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $add_url ); ?>" class="button"><?php esc_html_e( 'Add user', 'chrx-rental-manager' ); ?></a>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="chrx-rm-admin__info-banner">
		<?php esc_html_e( 'Staff and Landlord-Owners only ever see data for the properties assigned to them here.', 'chrx-rental-manager' ); ?>
	</div>

	<form method="get">
		<input type="hidden" name="page" value="chrx-rm-staff-roles">
		<?php $list_table->display(); ?>
	</form>
</div>
