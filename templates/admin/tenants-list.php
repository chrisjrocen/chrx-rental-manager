<?php
/**
 * Tenants list (designs/11-tenants-list.html).
 * Variables in scope: $list_table (TenantsListTable), $active_tab (string),
 * $active_count (int), $former_count (int), $add_url (string),
 * $can_manage (bool), $is_empty (bool), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Data\Tenant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_url = add_query_arg(
	array(
		'page'   => 'chrx-rm-tenants',
		'status' => Tenant::STATUS_ACTIVE,
	),
	admin_url( 'admin.php' )
);
$former_url = add_query_arg(
	array(
		'page'   => 'chrx-rm-tenants',
		'status' => Tenant::STATUS_FORMER,
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Tenants', 'chrx-rental-manager' ); ?></h1>
		<?php if ( $can_manage ) : ?>
			<a href="<?php echo esc_url( $add_url ); ?>" class="button"><?php esc_html_e( 'Add Tenant', 'chrx-rental-manager' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( $is_empty && Tenant::STATUS_ACTIVE === $active_tab && 0 === $active_count ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-empty-state">
				<div class="chrx-rm-empty-state__title"><?php esc_html_e( 'No tenants yet', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-empty-state__desc"><?php esc_html_e( 'Add a tenant, then create a lease to link them to a unit.', 'chrx-rental-manager' ); ?></div>
				<?php if ( $can_manage ) : ?>
					<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Tenant', 'chrx-rental-manager' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<div class="chrx-rm-tabs">
			<a href="<?php echo esc_url( $active_url ); ?>" class="<?php echo Tenant::STATUS_ACTIVE === $active_tab ? 'is-active' : ''; ?>">
				<?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Active (%d)', 'chrx-rental-manager' ), $active_count ) ); ?>
			</a>
			<a href="<?php echo esc_url( $former_url ); ?>" class="<?php echo Tenant::STATUS_FORMER === $active_tab ? 'is-active' : ''; ?>">
				<?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Former (%d)', 'chrx-rental-manager' ), $former_count ) ); ?>
			</a>
		</div>
		<form method="get">
			<input type="hidden" name="page" value="chrx-rm-tenants">
			<input type="hidden" name="status" value="<?php echo esc_attr( $active_tab ); ?>">
			<div class="chrx-rm-list-toolbar">
				<?php $list_table->search_box( __( 'Search tenants…', 'chrx-rental-manager' ), 'rm-tenants' ); ?>
			</div>
			<?php $list_table->display(); ?>
		</form>
	<?php endif; ?>
</div>
