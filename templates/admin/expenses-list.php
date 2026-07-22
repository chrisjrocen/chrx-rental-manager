<?php
/**
 * Expenses list (SPEC.md §4.4): filter by property/unit/category/date
 * range/recurring, CSV export.
 *
 * Variables in scope: $list_table (ExpensesListTable), $properties
 * (array<int,array>), $category_labels (array<string,string>), $add_url
 * (string), $is_empty (bool), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\ExpensesController;
use ChrxRentalManager\Data\Expense;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_category = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter params, no state change.
$selected_recurring = isset( $_GET['recurring'] ) ? sanitize_key( wp_unslash( $_GET['recurring'] ) ) : '';

$recurring_options = array(
	Expense::RECURRING_NONE      => __( 'One-off', 'chrx-rental-manager' ),
	Expense::RECURRING_MONTHLY   => __( 'Monthly', 'chrx-rental-manager' ),
	Expense::RECURRING_QUARTERLY => __( 'Quarterly', 'chrx-rental-manager' ),
	Expense::RECURRING_ANNUAL    => __( 'Annual', 'chrx-rental-manager' ),
);

$export_url = wp_nonce_url(
	add_query_arg(
		array(
			'action'      => 'rm_expenses_export_csv',
			'property_id' => $selected_property_id,
			'category'    => $selected_category,
			'from'        => $selected_from,
			'to'          => $selected_to,
			'recurring'   => $selected_recurring,
		),
		admin_url( 'admin-post.php' )
	),
	'rm_expenses_export_csv'
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-admin__header">
		<h1><?php esc_html_e( 'Expenses', 'chrx-rental-manager' ); ?></h1>
		<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Expense', 'chrx-rental-manager' ); ?></a>
	</div>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( $is_empty && 0 === $selected_property_id && '' === $selected_category ) : ?>
		<div class="chrx-rm-panel">
			<div class="chrx-rm-empty-state">
				<div class="chrx-rm-empty-state__title"><?php esc_html_e( 'No expenses yet', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-empty-state__desc"><?php esc_html_e( 'Record a one-off or recurring expense against an account, property, or unit.', 'chrx-rental-manager' ); ?></div>
				<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Expense', 'chrx-rental-manager' ); ?></a>
			</div>
		</div>
	<?php else : ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( ExpensesController::page_slug() ); ?>">
			<div class="chrx-rm-list-toolbar">
				<select name="property_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( 'All properties', 'chrx-rental-manager' ); ?></option>
					<?php foreach ( $properties as $property ) : ?>
						<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $selected_property_id, $property['id'] ); ?>>
							<?php echo esc_html( $property['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select name="category" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any category', 'chrx-rental-manager' ); ?></option>
					<?php foreach ( $category_labels as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_category, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="recurring" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any recurrence', 'chrx-rental-manager' ); ?></option>
					<?php foreach ( $recurring_options as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_recurring, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#646970;">
					<?php esc_html_e( 'From', 'chrx-rental-manager' ); ?>
					<input type="date" name="from" value="<?php echo esc_attr( $selected_from ); ?>" onchange="this.form.submit()">
				</label>
				<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#646970;">
					<?php esc_html_e( 'To', 'chrx-rental-manager' ); ?>
					<input type="date" name="to" value="<?php echo esc_attr( $selected_to ); ?>" onchange="this.form.submit()">
				</label>
				<span class="chrx-rm-list-toolbar__count">
					<?php
					printf(
						/* translators: %d: expense count */
						esc_html( _n( '%d expense', '%d expenses', (int) $list_table->get_pagination_arg( 'total_items' ), 'chrx-rental-manager' ) ),
						(int) $list_table->get_pagination_arg( 'total_items' )
					);
					?>
				</span>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button" style="margin-left:auto;"><?php esc_html_e( 'Export CSV', 'chrx-rental-manager' ); ?></a>
			</div>
			<?php $list_table->display(); ?>
		</form>
	<?php endif; ?>
</div>
