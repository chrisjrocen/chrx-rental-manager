<?php
/**
 * Custom Alerts list (SPEC.md §4.8).
 *
 * Variables in scope: $list_table (AlertsListTable), $add_url (string),
 * $is_empty (bool), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\AlertsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Custom Alerts', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Alert', 'chrx-rental-manager' ); ?></a>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( $is_empty ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-empty-state">
				<div class="chrx-rm-empty-state__title"><?php esc_html_e( 'No alerts yet', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-empty-state__desc"><?php esc_html_e( 'Compose a one-off or recurring alert for tenants, staff, or a landlord-owner.', 'chrx-rental-manager' ); ?></div>
				<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Alert', 'chrx-rental-manager' ); ?></a>
			</div>
		</div>
	<?php else : ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( AlertsController::page_slug() ); ?>">
			<?php $list_table->display(); ?>
		</form>
	<?php endif; ?>
</div>
