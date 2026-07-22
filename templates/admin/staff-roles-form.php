<?php
/**
 * Staff & Roles add/edit form.
 * Variables in scope: $action ('add'|'edit'), $user (?WP_User),
 * $current_role (?string), $assigned_ids (array<int,int>),
 * $all_properties (array<int,array<string,mixed>>), $list_url (string),
 * $notice (?string), $whatsapp_number (string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\StaffRolesController;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit = 'edit' === $action;
?>
<div class="wrap chrx-rm-admin">
	<h1>
		<?php echo $is_edit ? esc_html__( 'Edit User', 'chrx-rental-manager' ) : esc_html__( 'Add User', 'chrx-rental-manager' ); ?>
	</h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form">
		<?php wp_nonce_field( StaffRolesController::nonce_action(), 'rm_staff_roles_nonce' ); ?>
		<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) ( $user->ID ?? 0 ) ); ?>">

		<?php if ( ! $is_edit ) : ?>
			<table class="form-table">
				<tr>
					<th><label for="rm_display_name"><?php esc_html_e( 'Name', 'chrx-rental-manager' ); ?></label></th>
					<td><input type="text" id="rm_display_name" name="rm_display_name" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="rm_email"><?php esc_html_e( 'Email', 'chrx-rental-manager' ); ?></label></th>
					<td><input type="email" id="rm_email" name="rm_email" class="regular-text" required></td>
				</tr>
			</table>
		<?php else : ?>
			<p><strong><?php echo esc_html( $user->display_name ); ?></strong> — <?php echo esc_html( $user->user_email ); ?></p>
		<?php endif; ?>

		<table class="form-table">
			<tr>
				<th><label for="rm_whatsapp_number"><?php esc_html_e( 'WhatsApp number', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<input type="text" id="rm_whatsapp_number" name="rm_whatsapp_number" class="regular-text" value="<?php echo esc_attr( $whatsapp_number ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Local or international format — normalized automatically.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Role', 'chrx-rental-manager' ); ?></th>
				<td>
					<label>
						<input type="radio" name="rm_role" value="<?php echo esc_attr( RoleManager::ROLE_STAFF ); ?>" <?php checked( $current_role, RoleManager::ROLE_STAFF ); ?>>
						<?php esc_html_e( 'Property Manager / Staff', 'chrx-rental-manager' ); ?>
					</label><br>
					<label>
						<input type="radio" name="rm_role" value="<?php echo esc_attr( RoleManager::ROLE_LANDLORD_OWNER ); ?>" <?php checked( $current_role, RoleManager::ROLE_LANDLORD_OWNER ); ?>>
						<?php esc_html_e( 'Landlord-Owner', 'chrx-rental-manager' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Assigned properties', 'chrx-rental-manager' ); ?></th>
				<td>
					<?php if ( array() === $all_properties ) : ?>
						<p><?php esc_html_e( 'No properties exist yet.', 'chrx-rental-manager' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $all_properties as $property ) : ?>
						<label style="display:block;margin-bottom:4px;">
							<input
								type="checkbox"
								name="rm_property_ids[]"
								value="<?php echo esc_attr( (string) $property['id'] ); ?>"
								<?php checked( in_array( (int) $property['id'], $assigned_ids, true ) ); ?>
							>
							<?php echo esc_html( $property['name'] ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="rm_staff_roles_submit" value="1" class="button button-primary">
				<?php esc_html_e( 'Save', 'chrx-rental-manager' ); ?>
			</button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>
</div>
