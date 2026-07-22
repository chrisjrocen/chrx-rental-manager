<?php
/**
 * Add/Edit Expense (SPEC.md §4.4). Recurring templates and their
 * materialized instances share this one form — an instance is just an
 * ordinary row with recurring_parent_id set (shown read-only for
 * context, not editable).
 *
 * Variables in scope: $action ('add'|'edit'), $expense_id (int),
 * $expense (?array), $properties (array<int,array>), $units
 * (array<int,array>), $category_labels (array<string,string>),
 * $is_administrator (bool), $documents (array<int,array>), $can_manage
 * (bool), $is_instance (bool), $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\ExpensesController;
use ChrxRentalManager\Data\Expense;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit             = 'edit' === $action;
$current_scope       = (string) ( $expense['scope'] ?? Expense::SCOPE_ACCOUNT );
$current_property_id = (int) ( $expense['property_id'] ?? 0 );
$current_unit_id     = (int) ( $expense['unit_id'] ?? 0 );
$current_category    = (string) ( $expense['category'] ?? '' );
$current_recurring   = (string) ( $expense['recurring'] ?? Expense::RECURRING_NONE );

$recurring_options = array(
	Expense::RECURRING_NONE      => __( 'One-off (does not repeat)', 'chrx-rental-manager' ),
	Expense::RECURRING_MONTHLY   => __( 'Monthly', 'chrx-rental-manager' ),
	Expense::RECURRING_QUARTERLY => __( 'Quarterly', 'chrx-rental-manager' ),
	Expense::RECURRING_ANNUAL    => __( 'Annual', 'chrx-rental-manager' ),
);
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb"><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Expenses', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo $is_edit ? esc_html__( 'Edit', 'chrx-rental-manager' ) : esc_html__( 'Add new', 'chrx-rental-manager' ); ?></div>
	<h1><?php echo $is_edit ? esc_html__( 'Edit Expense', 'chrx-rental-manager' ) : esc_html__( 'Add Expense', 'chrx-rental-manager' ); ?></h1>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( null !== $expense && null !== $expense['voided_at'] ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: void reason */
					esc_html__( 'This expense was voided: %s', 'chrx-rental-manager' ),
					esc_html( (string) $expense['void_reason'] )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $is_instance ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'This is a materialized instance of a recurring expense template. Editing it only affects this one occurrence — edit the template to change future instances.', 'chrx-rental-manager' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" class="chrx-rm-admin__form" style="max-width:760px;">
		<?php wp_nonce_field( ExpensesController::nonce_action(), 'rm_expense_nonce' ); ?>
		<input type="hidden" name="expense_id" value="<?php echo esc_attr( (string) $expense_id ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="rm_scope"><?php esc_html_e( 'Scope', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_scope" name="rm_scope">
						<?php if ( $is_administrator ) : ?>
							<option value="<?php echo esc_attr( Expense::SCOPE_ACCOUNT ); ?>" <?php selected( $current_scope, Expense::SCOPE_ACCOUNT ); ?>><?php esc_html_e( 'Account-wide', 'chrx-rental-manager' ); ?></option>
						<?php endif; ?>
						<option value="<?php echo esc_attr( Expense::SCOPE_PROPERTY ); ?>" <?php selected( $current_scope, Expense::SCOPE_PROPERTY ); ?>><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></option>
						<option value="<?php echo esc_attr( Expense::SCOPE_UNIT ); ?>" <?php selected( $current_scope, Expense::SCOPE_UNIT ); ?>><?php esc_html_e( 'Unit', 'chrx-rental-manager' ); ?></option>
					</select>
					<?php if ( ! $is_administrator ) : ?>
						<p class="description"><?php esc_html_e( 'Only an Administrator can record an account-wide expense.', 'chrx-rental-manager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr id="rm_property_row">
				<th><label for="rm_property_id"><?php esc_html_e( 'Property', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_property_id" name="rm_property_id">
						<option value="0"><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
						<?php foreach ( $properties as $property ) : ?>
							<option value="<?php echo esc_attr( (string) $property['id'] ); ?>" <?php selected( $current_property_id, $property['id'] ); ?>><?php echo esc_html( $property['name'] ); ?></option>
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
							<option value="<?php echo esc_attr( (string) $unit['id'] ); ?>" data-property-id="<?php echo esc_attr( (string) $unit['property_id'] ); ?>" <?php selected( $current_unit_id, $unit['id'] ); ?>><?php echo esc_html( $unit['unit_label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_category"><?php esc_html_e( 'Category', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_category" name="rm_category">
						<option value=""><?php esc_html_e( '— Select —', 'chrx-rental-manager' ); ?></option>
						<?php foreach ( $category_labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_category, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr id="rm_custom_category_row">
				<th><label for="rm_custom_category_label"><?php esc_html_e( 'Custom category label', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_custom_category_label" name="rm_custom_category_label" value="<?php echo esc_attr( (string) ( $expense['custom_category_label'] ?? '' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="rm_amount"><?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="text" id="rm_amount" name="rm_amount" value="<?php echo esc_attr( (string) ( $expense['amount'] ?? '' ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_expense_date"><?php esc_html_e( 'Date', 'chrx-rental-manager' ); ?></label></th>
				<td><input type="date" id="rm_expense_date" name="rm_expense_date" value="<?php echo esc_attr( (string) ( $expense['expense_date'] ?? current_time( 'Y-m-d' ) ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="rm_description"><?php esc_html_e( 'Description', 'chrx-rental-manager' ); ?></label></th>
				<td><textarea id="rm_description" name="rm_description" rows="3" style="width:100%;max-width:400px;"><?php echo esc_textarea( (string) ( $expense['description'] ?? '' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="rm_recurring"><?php esc_html_e( 'Recurring', 'chrx-rental-manager' ); ?></label></th>
				<td>
					<select id="rm_recurring" name="rm_recurring" <?php disabled( $is_instance ); ?>>
						<?php foreach ( $recurring_options as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_recurring, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A daily job materializes each period from this template. Editing the template only affects future instances.', 'chrx-rental-manager' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="rm_expense_submit" value="1" class="button button-primary">
				<?php echo $is_edit ? esc_html__( 'Save Expense', 'chrx-rental-manager' ) : esc_html__( 'Create Expense', 'chrx-rental-manager' ); ?>
			</button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'chrx-rental-manager' ); ?></a>
		</p>
	</form>

	<?php if ( $is_edit && null === $expense['voided_at'] ) : ?>
		<div class="chrx-rm-panel" style="max-width:760px;margin-top:20px;">
			<div class="chrx-rm-panel__header"><span><?php esc_html_e( 'Void this expense', 'chrx-rental-manager' ); ?></span></div>
			<div class="chrx-rm-panel__body">
				<p class="description"><?php esc_html_e( 'A mistaken expense is voided with a reason, never deleted — it stays visible in this list for audit purposes.', 'chrx-rental-manager' ); ?></p>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Void this expense? This cannot be undone.', 'chrx-rental-manager' ) ); ?>');">
					<input type="hidden" name="page" value="<?php echo esc_attr( ExpensesController::page_slug() ); ?>">
					<input type="hidden" name="rm_action" value="void">
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $expense_id ); ?>">
					<?php wp_nonce_field( 'rm_expense_void' ); ?>
					<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason for voiding', 'chrx-rental-manager' ); ?>" required style="width:100%;max-width:360px;">
					<button type="submit" class="button" style="margin-top:8px;"><?php esc_html_e( 'Void Expense', 'chrx-rental-manager' ); ?></button>
				</form>
			</div>
		</div>

		<?php
		$entity_type = \ChrxRentalManager\Data\Document::ENTITY_EXPENSE;
		$entity_id   = $expense_id;
		require \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/partials/documents-panel.php';
		?>
	<?php endif; ?>
</div>
<script>
(function () {
	var scopeSelect = document.getElementById( 'rm_scope' );
	var propertyRow = document.getElementById( 'rm_property_row' );
	var unitRow = document.getElementById( 'rm_unit_row' );
	var categorySelect = document.getElementById( 'rm_category' );
	var customCategoryRow = document.getElementById( 'rm_custom_category_row' );

	function updateScopeRows() {
		var scope = scopeSelect.value;
		propertyRow.style.display = ( 'property' === scope || 'unit' === scope ) ? '' : 'none';
		unitRow.style.display = 'unit' === scope ? '' : 'none';
	}

	function updateCategoryRow() {
		customCategoryRow.style.display = 'custom' === categorySelect.value ? '' : 'none';
	}

	scopeSelect.addEventListener( 'change', updateScopeRows );
	categorySelect.addEventListener( 'change', updateCategoryRow );
	updateScopeRows();
	updateCategoryRow();
})();
</script>
