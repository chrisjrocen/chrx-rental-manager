<?php
/**
 * Add/Edit Custom Alert (SPEC.md §4.8).
 *
 * Variables in scope: $action ('add'|'edit'), $alert_id (int), $alert
 * (?array), $properties (array<int,array>), $units (array<int,array>),
 * $is_administrator (bool), $list_url (string), $log_rows
 * (array<int,array>), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\AlertsController;
use ChrxRentalManager\Data\Alert;
use ChrxRentalManager\Data\NotificationLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit               = 'edit' === $action;
$current_title         = (string) ( $alert['title'] ?? '' );
$current_message       = (string) ( $alert['message'] ?? '' );
$current_entity_type   = (string) ( $alert['entity_type'] ?? Alert::ENTITY_NONE );
$current_entity_id     = null !== ( $alert['entity_id'] ?? null ) ? (int) $alert['entity_id'] : 0;
$current_schedule_type = (string) ( $alert['schedule_type'] ?? Alert::SCHEDULE_ONCE );
$current_scheduled_at  = ! empty( $alert['scheduled_at'] ) ? gmdate( 'Y-m-d\TH:i', strtotime( $alert['scheduled_at'] ) ) : '';
$current_selectors     = (array) ( $alert['recipients']['selectors'] ?? array() );
$current_user_ids      = implode( ',', (array) ( $alert['recipients']['user_ids'] ?? array() ) );
$current_channels      = (array) ( $alert['channels'] ?? array() );
$current_active        = (bool) ( $alert['active'] ?? true );

$schedule_labels = array(
	Alert::SCHEDULE_ONCE    => __( 'One-off (specific date & time)', 'chrx-rental-manager' ),
	Alert::SCHEDULE_DAILY   => __( 'Daily, at this time', 'chrx-rental-manager' ),
	Alert::SCHEDULE_WEEKLY  => __( 'Weekly, on this day/time', 'chrx-rental-manager' ),
	Alert::SCHEDULE_MONTHLY => __( 'Monthly, on this day/time', 'chrx-rental-manager' ),
);

$selector_labels = array(
	Alert::RECIPIENT_TENANTS_OF_ENTITY  => __( 'Tenants of the attached property/unit', 'chrx-rental-manager' ),
	Alert::RECIPIENT_STAFF_OF_ENTITY    => __( 'Staff assigned to the attached property', 'chrx-rental-manager' ),
	Alert::RECIPIENT_LANDLORD_OF_ENTITY => __( 'Landlord-owner of the attached property', 'chrx-rental-manager' ),
	Alert::RECIPIENT_SELF               => __( 'Myself', 'chrx-rental-manager' ),
);

$channel_labels = array(
	NotificationLog::CHANNEL_EMAIL    => __( 'Email', 'chrx-rental-manager' ),
	NotificationLog::CHANNEL_WHATSAPP => __( 'WhatsApp', 'chrx-rental-manager' ),
	NotificationLog::CHANNEL_PORTAL   => __( 'In-portal / dashboard banner', 'chrx-rental-manager' ),
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Custom Alerts', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo $is_edit ? esc_html__( 'Edit', 'chrx-rental-manager' ) : esc_html__( 'Add new', 'chrx-rental-manager' ); ?></div>
	<h1><?php echo $is_edit ? esc_html__( 'Edit Alert', 'chrx-rental-manager' ) : esc_html__( 'Add Alert', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:760px;">
		<?php wp_nonce_field( AlertsController::nonce_action(), 'rm_alert_nonce' ); ?>
		<input type="hidden" name="alert_id" value="<?php echo esc_attr( (string) $alert_id ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="rm_title"><?php esc_html_e( 'Title', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_title" name="rm_title" value="<?php echo esc_attr( $current_title ); ?>" style="width:100%;max-width:400px;" required></td>
			</tr>
			<tr>
				<th><label for="rm_message"><?php esc_html_e( 'Message', 'chrx-rental-manager' ); ?></label></th>
				<td><textarea id="rm_message" name="rm_message" rows="4" style="width:100%;max-width:400px;" required><?php echo esc_textarea( $current_message ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="rm_entity_type"><?php esc_html_e( 'Attach to', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_entity_type" name="rm_entity_type">
						<?php if ( $is_administrator ) : ?>
							<option value="<?php echo esc_attr( Alert::ENTITY_NONE ); ?>" <?php selected( $current_entity_type, Alert::ENTITY_NONE ); ?>><?php esc_html_e( 'Account-wide (no specific property)', 'chrx-rental-manager' ); ?></option>
						<?php endif; ?>
						<option value="<?php echo esc_attr( Alert::ENTITY_PROPERTY ); ?>" <?php selected( $current_entity_type, Alert::ENTITY_PROPERTY ); ?>><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></option>
						<option value="<?php echo esc_attr( Alert::ENTITY_UNIT ); ?>" <?php selected( $current_entity_type, Alert::ENTITY_UNIT ); ?>><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr id="rm_property_row">
				<th><label for="rm_property_id"><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_property_id" name="rm_property_id">
						<option value="0"><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
						<?php foreach ( $properties as $property ) : ?>
							<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $current_entity_id, (int) $property['id'] ); ?>><?php echo esc_html( $property['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr id="rm_unit_row">
				<th><label for="rm_unit_id"><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_unit_id" name="rm_unit_id">
						<option value="0"><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
						<?php foreach ( $units as $unit ) : ?>
							<option value="<?php echo esc_attr( (string) $unit['id'] ); ?>" <?php selected( $current_entity_id, (int) $unit['id'] ); ?>><?php echo esc_html( $unit['unit_label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_schedule_type"><?php esc_html_e( 'Schedule', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_schedule_type" name="rm_schedule_type">
						<?php foreach ( $schedule_labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_schedule_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="datetime-local" id="rm_scheduled_at" name="rm_scheduled_at" value="<?php echo esc_attr( $current_scheduled_at ); ?>" required>
					<p class="description"><?php esc_html_e( 'For recurring schedules, only the day-of-week/day-of-month and time of this value are used going forward.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Recipients', 'chrx-rental-manager' ); ?></th>
				<td>
					<?php foreach ( $selector_labels as $key => $label ) : ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="rm_recipient_selectors[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $current_selectors, true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<label for="rm_recipient_user_ids" style="display:block;margin-top:8px;"><?php esc_html_e( 'Additional recipient user IDs (comma-separated, optional)', 'chrx-rental-manager' ); ?></label>
					<input type="text" id="rm_recipient_user_ids" name="rm_recipient_user_ids" value="<?php echo esc_attr( $current_user_ids ); ?>" style="width:100%;max-width:400px;">
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Channels', 'chrx-rental-manager' ); ?></th>
				<td>
					<?php foreach ( $channel_labels as $key => $label ) : ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="rm_channels[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $current_channels, true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Active', 'chrx-rental-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="rm_active" value="1" <?php checked( $current_active ); ?>>
						<?php esc_html_e( 'Yes, this alert is live', 'chrx-rental-manager' ); ?>
					</label>
					<?php if ( $is_edit && Alert::SCHEDULE_ONCE === $current_schedule_type && ! empty( $alert['last_sent_at'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'One-off alerts deactivate themselves automatically once sent.', 'chrx-rental-manager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="rm_alert_submit" value="1" class="button button-primary">
				<?php echo $is_edit ? esc_html__( 'Save Alert', 'chrx-rental-manager' ) : esc_html__( 'Create Alert', 'chrx-rental-manager' ); ?>
			</button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>

	<?php if ( $is_edit ) : ?>
		<div class="chrx-rm-panel" style="max-width:760px;margin-top:20px;">
			<div class="chrx-rm-panel__header"><span><?php esc_html_e( 'Actions', 'chrx-rental-manager' ); ?></span></div>
			<div class="chrx-rm-panel__body" style="display:flex;gap:10px;">
				<a href="
				<?php
				echo esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'      => AlertsController::page_slug(),
								'rm_action' => 'toggle',
								'id'        => $alert_id,
							),
							admin_url( 'admin.php' )
						),
						'rm_alert_toggle'
					)
				);
				?>
							" class="button">
					<?php echo $current_active ? esc_html__( 'Deactivate', 'chrx-rental-manager' ) : esc_html__( 'Activate', 'chrx-rental-manager' ); ?>
				</a>
				<a href="
				<?php
				echo esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'      => AlertsController::page_slug(),
								'rm_action' => 'delete',
								'id'        => $alert_id,
							),
							admin_url( 'admin.php' )
						),
						'rm_alert_delete'
					)
				);
				?>
							" class="button" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Delete this alert? This cannot be undone.', 'chrx-rental-manager' ) ); ?>');">
					<?php esc_html_e( 'Delete', 'chrx-rental-manager' ); ?>
				</a>
			</div>
		</div>

		<?php if ( array() !== $log_rows ) : ?>
			<div class="chrx-rm-panel" style="max-width:760px;margin-top:20px;">
				<div class="chrx-rm-panel__header"><span><?php esc_html_e( 'Delivery log', 'chrx-rental-manager' ); ?></span></div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sent', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Channel', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Recipient', 'chrx-rental-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'chrx-rental-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log_rows as $log_row ) : ?>
							<tr>
								<td><?php echo esc_html( gmdate( 'd M Y H:i', strtotime( $log_row['sent_at'] ) ) ); ?></td>
								<td><?php echo esc_html( $log_row['channel'] ); ?></td>
								<td><?php echo esc_html( '' !== (string) $log_row['recipient'] ? $log_row['recipient'] : '—' ); ?></td>
								<td><?php echo esc_html( $log_row['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
<script>
(function () {
	var entitySelect = document.getElementById( 'rm_entity_type' );
	var propertyRow = document.getElementById( 'rm_property_row' );
	var unitRow = document.getElementById( 'rm_unit_row' );

	function update() {
		var value = entitySelect.value;
		propertyRow.style.display = 'property' === value ? '' : 'none';
		unitRow.style.display = 'unit' === value ? '' : 'none';
	}

	entitySelect.addEventListener( 'change', update );
	update();
})();
</script>
