<?php
/**
 * Add/Edit Tenant form (designs/13-add-edit-tenant.html).
 *
 * Deviation: the design shows an "Emergency contact" free-text field, but
 * rm_tenants (SPEC.md §3) has no column for it and SPEC's tenant
 * description never mentions one — omitted rather than adding an
 * unspecified schema column for a cosmetic field. Also, "Send portal
 * invite" is offered only on Add (not Edit), since an existing tenant's
 * invite is the "Invite to Portal"/"Resend invite" action on the detail
 * screen instead.
 *
 * Variables in scope: $action ('add'|'edit'), $tenant_id (int),
 * $tenant (?array), $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\TenantsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit = 'edit' === $action;
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Tenants', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo $is_edit ? esc_html__( 'Edit', 'chrx-rental-manager' ) : esc_html__( 'Add new', 'chrx-rental-manager' ); ?></div>
	<h1><?php echo $is_edit ? esc_html__( 'Edit Tenant', 'chrx-rental-manager' ) : esc_html__( 'Add Tenant', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:720px;">
		<?php wp_nonce_field( TenantsController::nonce_action(), 'rm_tenant_nonce' ); ?>
		<input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant_id ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="rm_full_name"><?php esc_html_e( 'Full name', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_full_name" name="rm_full_name" class="regular-text" value="<?php echo esc_attr( $tenant['full_name'] ?? '' ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_phone"><?php esc_html_e( 'Phone', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_phone" name="rm_phone" class="regular-text" value="<?php echo esc_attr( $tenant['phone'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><label for="rm_email"><?php esc_html_e( 'Email', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="email" id="rm_email" name="rm_email" class="regular-text" value="<?php echo esc_attr( $tenant['email'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><label for="rm_whatsapp_number"><?php esc_html_e( 'WhatsApp number', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<input type="text" id="rm_whatsapp_number" name="rm_whatsapp_number" class="regular-text" value="<?php echo esc_attr( $tenant['whatsapp_number'] ?? '' ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Local or international format — normalized automatically. Notifications also go here in addition to email.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rm_national_id"><?php esc_html_e( 'National ID', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_national_id" name="rm_national_id" class="regular-text" value="<?php echo esc_attr( $tenant['national_id'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Next of kin', 'chrx-rental-manager' ); ?></th>
				<td>
					<div style="display:flex;flex-direction:column;gap:8px;max-width:360px;">
						<input type="text" name="rm_next_of_kin_name" placeholder="<?php esc_attr_e( 'Name', 'chrx-rental-manager' ); ?>" value="<?php echo esc_attr( $tenant['next_of_kin_name'] ?? '' ); ?>">
						<input type="text" name="rm_next_of_kin_phone" placeholder="<?php esc_attr_e( 'Phone', 'chrx-rental-manager' ); ?>" value="<?php echo esc_attr( $tenant['next_of_kin_phone'] ?? '' ); ?>">
						<input type="text" name="rm_next_of_kin_relationship" placeholder="<?php esc_attr_e( 'Relationship', 'chrx-rental-manager' ); ?>" value="<?php echo esc_attr( $tenant['next_of_kin_relationship'] ?? '' ); ?>">
					</div>
					<p class="description"><?php esc_html_e( 'Optional. Display-only — the next of kin gets no portal access and no notifications.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<?php if ( ! $is_edit ) : ?>
				<tr>
					<th><?php esc_html_e( 'Portal', 'chrx-rental-manager' ); ?></th>
					<td>
						<label style="display:flex;align-items:center;gap:8px;">
							<input type="checkbox" name="rm_send_invite" value="1" checked>
							<?php esc_html_e( 'Send portal invite email after saving (requires an email address)', 'chrx-rental-manager' ); ?>
						</label>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<p class="submit">
			<button type="submit" name="rm_tenant_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save Tenant', 'chrx-rental-manager' ); ?></button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>
</div>
