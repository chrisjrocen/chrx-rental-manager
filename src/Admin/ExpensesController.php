<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Csv;
use ChrxRentalManager\Admin\Support\ExpenseCategory;
use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expenses (SPEC.md §4.4): one-off and recurring expenses at account,
 * property, or unit scope. Void-not-delete throughout (Expense::void()),
 * matching Payment::void()/Charge::mark_waived() — there is no trash/
 * restore/delete-permanently flow here, unlike Units/Tenants/Leases,
 * since a voided expense stays in this same list (filterable, badge-
 * marked) rather than moving to a separate archive screen.
 *
 * Landlord-Owner never reaches this screen at all (SPEC.md §4.4: "Staff/
 * Admin record expenses... Landlord-Owner cannot") — gated on
 * CAP_MANAGE_EXPENSES alone, with no CAP_VIEW_DASHBOARD-style read-only
 * branch the way Units/Leases/etc. have, since there is no read-only
 * audience for this screen by design.
 *
 * Account-scoped expenses (no property_id) are Administrator-only to
 * create: SPEC.md §2 grants Staff expense CRUD only "on properties they're
 * assigned to," so an account-wide expense — which by definition isn't
 * scoped to any property Staff could be assigned to — is treated as an
 * Administrator action, checked via Access::is_administrator().
 */
final class ExpensesController {

	private const NONCE_ACTION  = 'rm_expense_save';
	private const PAGE_SLUG     = 'chrx-rm-expenses';
	private const EXPORT_ACTION = 'rm_expenses_export_csv';

	private Expense $expenses;
	private Property $properties;
	private Unit $units;
	private Document $documents;
	private Access $access;

	public function __construct(
		?Expense $expenses = null,
		?Property $properties = null,
		?Unit $units = null,
		?Document $documents = null,
		?Access $access = null
	) {
		$this->expenses   = $expenses ?? new Expense();
		$this->properties = $properties ?? new Property();
		$this->units      = $units ?? new Unit();
		$this->documents  = $documents ?? new Document();
		$this->access     = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export_csv' ) );
	}

	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's request this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_EXPENSES ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_expense_submit'] ) ) {
			$this->handle_save();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_void_action().
		if ( isset( $_GET['rm_action'] ) && 'void' === $_GET['rm_action'] ) {
			$this->handle_void_action();
		}
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_EXPENSES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage expenses.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'expenses' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$expense_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_form( $action, $expense_id, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table               = new ExpensesListTable( $restrict_to_property_ids );
		$list_table->prepare_items();

		$properties = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_values(
				array_filter(
					$this->properties->all_active(),
					fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
				)
			);

		$category_labels = ExpenseCategory::labels();
		$add_url         = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		$is_empty        = 0 === $list_table->get_pagination_arg( 'total_items' );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/expenses-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, int $expense_id, ?string $notice ): void {
		$expense = null;

		if ( 'edit' === $action ) {
			$expense = $this->expenses->find( $expense_id );

			if ( null === $expense ) {
				wp_die( esc_html__( 'Expense not found.', 'chrx-rental-manager' ), 404 );
			}

			if ( null !== $expense['property_id'] && ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $expense['property_id'] ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this expense.', 'chrx-rental-manager' ), 403 );
			}

			if ( null === $expense['property_id'] && ! $this->access->is_administrator( get_current_user_id() ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this expense.', 'chrx-rental-manager' ), 403 );
			}
		}

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$properties               = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_values(
				array_filter(
					$this->properties->all_active(),
					fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
				)
			);

		$units = null === $restrict_to_property_ids
			? $this->units->all_active()
			: array_values(
				array_filter(
					$this->units->all_active(),
					fn( array $u ): bool => in_array( (int) $u['property_id'], $restrict_to_property_ids, true )
				)
			);

		$is_administrator = $this->access->is_administrator( get_current_user_id() );
		$category_labels  = ExpenseCategory::labels();
		$list_url         = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$documents        = 0 === $expense_id ? array() : $this->documents->for_entity( Document::ENTITY_EXPENSE, $expense_id );
		$can_manage       = true; // Reaching this point already required CAP_MANAGE_EXPENSES plus the per-row access check above.
		$is_instance      = null !== $expense && null !== $expense['recurring_parent_id'];

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/expense-form.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_expense_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$expense_id = isset( $_POST['expense_id'] ) ? absint( $_POST['expense_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$scope = isset( $_POST['rm_scope'] ) ? sanitize_key( wp_unslash( $_POST['rm_scope'] ) ) : Expense::SCOPE_ACCOUNT;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_POST['rm_property_id'] ) ? absint( $_POST['rm_property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_POST['rm_unit_id'] ) ? absint( $_POST['rm_unit_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$category = isset( $_POST['rm_category'] ) ? sanitize_key( wp_unslash( $_POST['rm_category'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$custom_category_label = isset( $_POST['rm_custom_category_label'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_custom_category_label'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$amount = isset( $_POST['rm_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$expense_date = isset( $_POST['rm_expense_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_expense_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$description = isset( $_POST['rm_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_description'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$recurring = isset( $_POST['rm_recurring'] ) ? sanitize_key( wp_unslash( $_POST['rm_recurring'] ) ) : Expense::RECURRING_NONE;

		$back_to_form = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 0 === $expense_id ? 'add' : 'edit',
				'id'     => $expense_id,
			),
			admin_url( 'admin.php' )
		);

		$valid_scopes     = array( Expense::SCOPE_ACCOUNT, Expense::SCOPE_PROPERTY, Expense::SCOPE_UNIT );
		$valid_categories = array_keys( ExpenseCategory::labels() );
		$valid_recurring  = array( Expense::RECURRING_NONE, Expense::RECURRING_MONTHLY, Expense::RECURRING_QUARTERLY, Expense::RECURRING_ANNUAL );

		if (
			! in_array( $scope, $valid_scopes, true )
			|| ( Expense::SCOPE_PROPERTY === $scope && 0 === $property_id )
			|| ( Expense::SCOPE_UNIT === $scope && 0 === $unit_id )
			|| ! in_array( $category, $valid_categories, true )
			|| ( Expense::CATEGORY_CUSTOM === $category && '' === $custom_category_label )
			|| $amount <= 0
			|| false === strtotime( $expense_date )
			|| ! in_array( $recurring, $valid_recurring, true )
		) {
			FlashNotice::set( 'expenses', __( 'Please fill in a valid scope, category, amount, and date.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		// Scope determines the actual property_id/unit_id stored, regardless
		// of stray values submitted for the other scope's fields.
		if ( Expense::SCOPE_UNIT === $scope ) {
			$unit = $this->units->find( $unit_id );

			if ( null === $unit ) {
				FlashNotice::set( 'expenses', __( 'Please choose a valid unit.', 'chrx-rental-manager' ) );
				wp_safe_redirect( $back_to_form );
				exit;
			}

			$property_id = (int) $unit['property_id'];
		} elseif ( Expense::SCOPE_ACCOUNT === $scope ) {
			$property_id = 0;
			$unit_id     = 0;
		} else {
			$unit_id = 0;
		}

		if ( Expense::SCOPE_ACCOUNT === $scope ) {
			// SPEC.md §4.4/§2: account-scoped expenses are an Administrator
			// action — Staff's expense capability is property-scoped only.
			if ( ! $this->access->is_administrator( get_current_user_id() ) ) {
				wp_die( esc_html__( 'Only an Administrator can record an account-level expense.', 'chrx-rental-manager' ), 403 );
			}
		} elseif ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to record expenses on this property.', 'chrx-rental-manager' ), 403 );
		}

		$data = array(
			'scope'                 => $scope,
			'property_id'           => 0 === $property_id ? null : $property_id,
			'unit_id'               => 0 === $unit_id ? null : $unit_id,
			'category'              => $category,
			'custom_category_label' => Expense::CATEGORY_CUSTOM === $category ? $custom_category_label : null,
			'amount'                => $amount,
			'expense_date'          => $expense_date,
			'description'           => $description,
			'recurring'             => $recurring,
		);

		if ( 0 === $expense_id ) {
			$data['recorded_by'] = get_current_user_id();
			$expense_id          = $this->expenses->insert( $data );
		} else {
			$this->expenses->update( $expense_id, $data );
		}

		FlashNotice::set( 'expenses', __( 'Expense saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'edit',
					'id'     => $expense_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_void_action(): void {
		check_admin_referer( 'rm_expense_void' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$expense_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$reason  = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : '';
		$expense = $this->expenses->find( $expense_id );

		if ( null === $expense ) {
			wp_die( esc_html__( 'Expense not found.', 'chrx-rental-manager' ), 404 );
		}

		if ( null !== $expense['property_id'] && ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $expense['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to void this expense.', 'chrx-rental-manager' ), 403 );
		}

		if ( null === $expense['property_id'] && ! $this->access->is_administrator( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to void this expense.', 'chrx-rental-manager' ), 403 );
		}

		if ( '' === $reason ) {
			FlashNotice::set( 'expenses', __( 'Please provide a reason for voiding this expense.', 'chrx-rental-manager' ) );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => self::PAGE_SLUG,
						'action' => 'edit',
						'id'     => $expense_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->expenses->void( $expense_id, $reason );

		FlashNotice::set( 'expenses', __( 'Expense voided.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_export_csv(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_EXPENSES ) ) {
			wp_die( esc_html__( 'You do not have permission to export expenses.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_GET['unit_id'] ) ? absint( $_GET['unit_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$category = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$from_date = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$to_date = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$recurring = isset( $_GET['recurring'] ) ? sanitize_key( wp_unslash( $_GET['recurring'] ) ) : '';

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );

		if ( $property_id > 0 && null !== $restrict_to_property_ids && ! in_array( $property_id, $restrict_to_property_ids, true ) ) {
			$property_id = 0;
		}

		$rows = $this->expenses->search( $property_id, $unit_id, $category, $from_date, $to_date, $recurring, PHP_INT_MAX, 0 );

		if ( null !== $restrict_to_property_ids ) {
			$rows = array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => null !== $row['property_id'] && in_array( (int) $row['property_id'], $restrict_to_property_ids, true )
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="expenses-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Date', 'Scope', 'Property', 'Unit', 'Category', 'Amount', 'Recurring', 'Status', 'Description' ) );

		foreach ( $rows as $row ) {
			$property = null !== $row['property_id'] ? $this->properties->find( (int) $row['property_id'] ) : null;
			$unit     = null !== $row['unit_id'] ? $this->units->find( (int) $row['unit_id'] ) : null;

			fputcsv(
				$out,
				Csv::safe_row(
					array(
						$row['expense_date'],
						$row['scope'],
						null === $property ? '' : $property['name'],
						null === $unit ? '' : $unit['unit_label'],
						ExpenseCategory::label_for( $row['category'], $row['custom_category_label'] ),
						number_format( (float) $row['amount'], 2, '.', '' ),
						$row['recurring'],
						null !== $row['voided_at'] ? 'Voided' : 'Active',
						(string) $row['description'],
					)
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a generated CSV directly to the HTTP response body (php://output).
		fclose( $out );
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
