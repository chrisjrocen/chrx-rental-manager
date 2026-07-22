<?php
/**
 * Tenant detail (designs/12-tenant-detail.html).
 * Variables in scope: $tenant (array), $leases (array<int,array> with
 * 'unit_label' added), $documents (array<int,array>), $can_manage (bool),
 * $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Admin\Support\PortalStatus;
use ChrxRentalManager\Admin\TenantInviteController;
use ChrxRentalManager\Data\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url      = add_query_arg( 'page', 'chrx-rm-tenants', admin_url( 'admin.php' ) );
$edit_url      = add_query_arg(
	array(
		'page'   => 'chrx-rm-tenants',
		'action' => 'edit',
		'id'     => $tenant['id'],
	),
	admin_url( 'admin.php' )
);
$entity_type   = Document::ENTITY_TENANT;
$entity_id     = (int) $tenant['id'];
$portal_status = PortalStatus::for_tenant( $tenant );

$initials = '';
foreach ( array_slice( explode( ' ', trim( $tenant['full_name'] ) ), 0, 2 ) as $part ) {
	$initials .= mb_substr( $part, 0, 1 );
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Tenants', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo esc_html( $tenant['full_name'] ); ?></div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="chrx-rm-detail-header">
		<div style="display:flex;align-items:center;gap:12px;">
			<div style="width:44px;height:44px;border-radius:50%;background:#3858a8;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;"><?php echo esc_html( strtoupper( $initials ) ); ?></div>
			<div>
				<h1 style="margin:0;font-size:22px;font-weight:600;"><?php echo esc_html( $tenant['full_name'] ); ?></h1>
				<div style="font-size:13px;color:#646970;">
					<?php echo esc_html( sprintf( /* translators: %s: month year */ __( 'Tenant since %s', 'chrx-rental-manager' ), gmdate( 'M Y', strtotime( $tenant['created_at'] ) ) ) ); ?>
				</div>
			</div>
		</div>
		<?php if ( $can_manage ) : ?>
			<?php
			$trash_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'      => 'chrx-rm-tenants',
						'rm_action' => 'archive',
						'id'        => $tenant['id'],
					),
					admin_url( 'admin.php' )
				),
				'rm_tenant_archive'
			);
			?>
			<div class="chrx-rm-detail-header__actions">
				<a href="<?php echo esc_url( $edit_url ); ?>" class="button"><?php esc_html_e( 'Edit tenant', 'chrx-rental-manager' ); ?></a>
				<a href="<?php echo esc_url( $trash_url ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Move this tenant to trash?', 'chrx-rental-manager' ) ); ?>');"><?php esc_html_e( 'Move to Trash', 'chrx-rental-manager' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:18px;">
		<div>
			<div class="chrx-rm-panel">
				<div class="chrx-rm-panel__header"><?php esc_html_e( 'Contact', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-panel__body" style="display:flex;flex-direction:column;gap:11px;font-size:13px;">
					<div><div style="color:#646970;font-size:11px;text-transform:uppercase;margin-bottom:2px;"><?php esc_html_e( 'Phone', 'chrx-rental-manager' ); ?></div><?php echo esc_html( $tenant['phone'] ); ?></div>
					<div><div style="color:#646970;font-size:11px;text-transform:uppercase;margin-bottom:2px;"><?php esc_html_e( 'Email', 'chrx-rental-manager' ); ?></div><?php echo esc_html( $tenant['email'] ); ?></div>
					<div><div style="color:#646970;font-size:11px;text-transform:uppercase;margin-bottom:2px;"><?php esc_html_e( 'National ID', 'chrx-rental-manager' ); ?></div><?php echo esc_html( '' !== $tenant['national_id'] ? $tenant['national_id'] : '—' ); ?></div>
				</div>
			</div>

			<?php if ( '' !== (string) ( $tenant['next_of_kin_name'] ?? '' ) ) : ?>
				<div class="chrx-rm-panel">
					<div class="chrx-rm-panel__header"><?php esc_html_e( 'Next of kin', 'chrx-rental-manager' ); ?></div>
					<div class="chrx-rm-panel__body" style="display:flex;flex-direction:column;gap:11px;font-size:13px;">
						<div><div style="color:#646970;font-size:11px;text-transform:uppercase;margin-bottom:2px;"><?php esc_html_e( 'Name', 'chrx-rental-manager' ); ?></div><?php echo esc_html( $tenant['next_of_kin_name'] ); ?></div>
						<div><div style="color:#646970;font-size:11px;text-transform:uppercase;margin-bottom:2px;"><?php esc_html_e( 'Phone', 'chrx-rental-manager' ); ?></div><?php echo esc_html( '' !== (string) ( $tenant['next_of_kin_phone'] ?? '' ) ? $tenant['next_of_kin_phone'] : '—' ); ?></div>
						<div><div style="color:#646970;font-size:11px;text-transform:uppercase;margin-bottom:2px;"><?php esc_html_e( 'Relationship', 'chrx-rental-manager' ); ?></div><?php echo esc_html( '' !== (string) ( $tenant['next_of_kin_relationship'] ?? '' ) ? $tenant['next_of_kin_relationship'] : '—' ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<div style="background:#e4eefa;border:1px solid #b9d3ef;border-radius:6px;padding:18px;margin-bottom:18px;">
				<div style="font-weight:700;font-size:14px;margin-bottom:6px;"><?php esc_html_e( 'Portal access', 'chrx-rental-manager' ); ?></div>
				<div style="margin-bottom:12px;">
					<?php echo wp_kses_post( Badge::render( PortalStatus::badge_key( $portal_status ), PortalStatus::label( $portal_status ) ) ); ?>
				</div>
				<?php if ( $can_manage && PortalStatus::NOT_INVITED === $portal_status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rm_invite_tenant">
						<input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( home_url( add_query_arg( null, null ) ) ); ?>">
						<?php wp_nonce_field( TenantInviteController::nonce_action() ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Invite to Portal', 'chrx-rental-manager' ); ?></button>
					</form>
				<?php elseif ( $can_manage && PortalStatus::INVITED === $portal_status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rm_invite_tenant">
						<input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( home_url( add_query_arg( null, null ) ) ); ?>">
						<?php wp_nonce_field( TenantInviteController::nonce_action() ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Resend invite', 'chrx-rental-manager' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div>
			<div class="chrx-rm-panel">
				<div class="chrx-rm-panel__header"><?php esc_html_e( 'Leases', 'chrx-rental-manager' ); ?></div>
				<?php if ( array() === $leases ) : ?>
					<div class="chrx-rm-panel__body"><p style="color:#8c8f94;font-size:13px;margin:0;"><?php esc_html_e( 'No leases yet.', 'chrx-rental-manager' ); ?></p></div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></th>
								<th><?php esc_html_e( 'Term', 'chrx-rental-manager' ); ?></th>
								<th style="text-align:right;"><?php esc_html_e( 'Rent', 'chrx-rental-manager' ); ?></th>
								<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $leases as $lease ) : ?>
								<?php
								$lease_url = add_query_arg(
									array(
										'page' => 'chrx-rm-leases',
										'id'   => $lease['id'],
									),
									admin_url( 'admin.php' )
								);
								?>
								<tr>
									<td><a href="<?php echo esc_url( $lease_url ); ?>" style="font-weight:600;"><?php echo esc_html( $lease['unit_label'] ); ?></a></td>
									<td><?php echo esc_html( gmdate( 'M Y', strtotime( $lease['start_date'] ) ) . ' – ' . gmdate( 'M Y', strtotime( $lease['end_date'] ) ) ); ?></td>
									<td style="text-align:right;"><?php echo esc_html( Money::format( (float) $lease['rent_amount'] ) ); ?></td>
									<td><?php echo wp_kses_post( Badge::render( $lease['status'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/partials/documents-panel.php'; ?>
		</div>
	</div>
</div>
