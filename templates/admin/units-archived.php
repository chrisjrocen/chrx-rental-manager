<?php
/**
 * Units trash (restore / delete permanently) view.
 * Variables in scope: $archived (array<int,array>), $properties (Property),
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
		<h1><?php esc_html_e( 'Trash — Units', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( '← Back to Units', 'chrx-rental-manager' ); ?></a>
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
					<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
					<th><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archived as $unit ) : ?>
					<?php $property = $properties->find( (int) $unit['property_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $unit['unit_label'] ); ?></td>
						<td><?php echo esc_html( null === $property ? '' : $property['name'] ); ?></td>
						<td>
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'page'      => 'chrx-rm-units',
											'rm_action' => 'restore',
											'id'        => $unit['id'],
										),
										admin_url( 'admin.php' )
									),
									'rm_unit_restore'
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
												'page' => 'chrx-rm-units',
												'rm_action' => 'delete_permanently',
												'id'   => $unit['id'],
											),
											admin_url( 'admin.php' )
										),
										'rm_unit_delete_permanently'
									)
								);
								?>
											" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this unit? This cannot be undone.', 'chrx-rental-manager' ) ); ?>');">
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
