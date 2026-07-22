<?php
/**
 * Tenants trash (restore / delete permanently) view.
 * Variables in scope: $archived (array<int,array>), $list_url (string),
 * $notice (?string), $can_delete_permanently (bool).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Trash — Tenants', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( '← Back to Tenants', 'chrx-rental-manager' ); ?></a>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( array() === $archived ) : ?>
		<p><?php esc_html_e( 'Trash is empty.', 'chrx-rental-manager' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tenant', 'chrx-rental-manager' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'chrx-rental-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archived as $tenant ) : ?>
					<tr>
						<td><?php echo esc_html( $tenant['full_name'] ); ?></td>
						<td><?php echo esc_html( $tenant['phone'] ); ?></td>
						<td>
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'page'      => 'chrx-rm-tenants',
											'rm_action' => 'restore',
											'id'        => $tenant['id'],
										),
										admin_url( 'admin.php' )
									),
									'rm_tenant_restore'
								)
							);
							?>
										">
								<?php esc_html_e( 'Restore', 'chrx-rental-manager' ); ?>
							</a>
							<?php if ( $can_delete_permanently ) : ?>
								<a class="button-link-delete" style="margin-left:8px;color:#b32d2e;" href="
								<?php
								echo esc_url(
									wp_nonce_url(
										add_query_arg(
											array(
												'page' => 'chrx-rm-tenants',
												'rm_action' => 'delete_permanently',
												'id'   => $tenant['id'],
											),
											admin_url( 'admin.php' )
										),
										'rm_tenant_delete_permanently'
									)
								);
								?>
											" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this tenant? This cannot be undone.', 'chrx-rental-manager' ) ); ?>');">
									<?php esc_html_e( 'Delete Permanently', 'chrx-rental-manager' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
