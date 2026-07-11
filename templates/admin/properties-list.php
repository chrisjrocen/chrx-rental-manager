<?php
/**
 * Properties list (designs/05-properties-list.html).
 * Variables in scope: $list_table (PropertiesListTable), $add_url (string),
 * $archived_url (string), $can_manage (bool), $is_empty (bool),
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
		<h1><?php esc_html_e( 'Properties', 'chrx-rental-manager' ); ?></h1>
		<?php if ( $can_manage ) : ?>
			<a href="<?php echo esc_url( $add_url ); ?>" class="button"><?php esc_html_e( 'Add Property', 'chrx-rental-manager' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( $is_empty ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-empty-state">
				<div class="chrx-rm-empty-state__title"><?php esc_html_e( 'No properties yet', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-empty-state__desc"><?php esc_html_e( 'Add your first property, then create its units, tenants and leases.', 'chrx-rental-manager' ); ?></div>
				<?php if ( $can_manage ) : ?>
					<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Property', 'chrx-rental-manager' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<form method="get">
			<input type="hidden" name="page" value="chrx-rm-properties">
			<div class="chrx-rm-list-toolbar">
				<?php $list_table->search_box( __( 'Search properties…', 'chrx-rental-manager' ), 'rm-properties' ); ?>
				<?php if ( $can_manage ) : ?>
					<a href="<?php echo esc_url( $archived_url ); ?>" style="margin-left:auto;font-size:13px;"><?php esc_html_e( 'View archived', 'chrx-rental-manager' ); ?></a>
				<?php endif; ?>
			</div>
			<?php $list_table->display(); ?>
		</form>
	<?php endif; ?>
</div>
