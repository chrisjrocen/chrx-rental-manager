<?php
/**
 * Shared Documents panel used on Unit/Tenant/Lease detail screens
 * (SPEC.md §7). Variables in scope: $documents (array<int,array>),
 * $entity_type (string), $entity_id (int), $can_manage (bool).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\DocumentsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_url = home_url( add_query_arg( null, null ) );
?>
<div class="chrx-rm-panel">
	<div class="chrx-rm-panel__header"><?php esc_html_e( 'Documents', 'chrx-rental-manager' ); ?></div>
	<div class="chrx-rm-panel__body">
		<?php if ( array() === $documents ) : ?>
			<p style="color:#8c8f94;font-size:13px;margin:0 0 12px;"><?php esc_html_e( 'No documents yet.', 'chrx-rental-manager' ); ?></p>
		<?php else : ?>
			<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
				<?php foreach ( $documents as $document ) : ?>
					<?php
					$url        = wp_get_attachment_url( (int) $document['attachment_id'] );
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'      => 'rm_delete_document',
								'document_id' => $document['id'],
								'redirect'    => rawurlencode( $current_url ),
							),
							admin_url( 'admin-post.php' )
						),
						DocumentsController::delete_nonce_action()
					);
					?>
					<div style="display:flex;align-items:center;gap:9px;font-size:13px;justify-content:space-between;">
						<a href="<?php echo esc_url( (string) $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $document['label'] ); ?></a>
						<?php if ( $can_manage ) : ?>
							<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this document?', 'chrx-rental-manager' ) ); ?>');" style="color:#b32d2e;">
								<?php esc_html_e( 'Remove', 'chrx-rental-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $can_manage ) : ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rm_upload_document">
				<input type="hidden" name="entity_type" value="<?php echo esc_attr( $entity_type ); ?>">
				<input type="hidden" name="entity_id" value="<?php echo esc_attr( (string) $entity_id ); ?>">
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $current_url ); ?>">
				<?php wp_nonce_field( DocumentsController::upload_nonce_action() ); ?>
				<input type="file" name="rm_document" required>
				<button type="submit" class="button" style="margin-top:8px;"><?php esc_html_e( 'Upload', 'chrx-rental-manager' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
