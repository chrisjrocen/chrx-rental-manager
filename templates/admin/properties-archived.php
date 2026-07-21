<?php
/**
 * Properties trash (restore / delete permanently) view (SPEC.md §3
 * soft-delete requirement).
 * Variables in scope: $archived (array<int,array<string,mixed>>),
 * $list_url (string), $notice (?string), $can_delete_permanently (bool).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Trash — Properties', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( '← Back to Properties', 'chrx-rental-manager' ); ?></a>
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
					<th><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></th>
					<th><?php esc_html_e( 'City', 'chrx-rental-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archived as $property ) : ?>
					<tr>
						<td><?php echo esc_html( $property['name'] ); ?></td>
						<td><?php echo esc_html( $property['city'] ); ?></td>
						<td>
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'page'      => 'chrx-rm-properties',
											'rm_action' => 'restore',
											'id'        => $property['id'],
										),
										admin_url( 'admin.php' )
									),
									'rm_property_restore'
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
												'page'      => 'chrx-rm-properties',
												'rm_action' => 'delete_permanently',
												'id'        => $property['id'],
											),
											admin_url( 'admin.php' )
										),
										'rm_property_delete_permanently'
									)
								);
								?>
											" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this property? This cannot be undone.', 'chrx-rental-manager' ) ); ?>');">
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
