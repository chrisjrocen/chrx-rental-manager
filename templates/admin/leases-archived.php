<?php
/**
 * Leases archived (restore) view.
 * Variables in scope: $archived (array<int,array>), $units (Unit),
 * $tenants (Tenant), $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Archived Leases', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( '← Back to Leases', 'chrx-rental-manager' ); ?></a>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( array() === $archived ) : ?>
		<p><?php esc_html_e( 'No archived leases.', 'chrx-rental-manager' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
					<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archived as $lease ) : ?>
					<?php
					$tenant = $tenants->find( (int) $lease['tenant_id'] );
					$unit   = $units->find( (int) $lease['unit_id'] );
					?>
					<tr>
						<td><?php echo esc_html( null === $tenant ? '' : $tenant['full_name'] ); ?></td>
						<td><?php echo esc_html( null === $unit ? '' : $unit['unit_label'] ); ?></td>
						<td>
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'page'      => 'chrx-rm-leases',
											'rm_action' => 'restore',
											'id'        => $lease['id'],
										),
										admin_url( 'admin.php' )
									),
									'rm_lease_restore'
								)
							);
							?>
										">
								<?php esc_html_e( 'Restore', 'chrx-rental-manager' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
