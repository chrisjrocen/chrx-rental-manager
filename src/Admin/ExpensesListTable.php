<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\ExpenseCategory;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Expenses list (SPEC.md §4.4): filter by property/unit/category/date
 * range/recurring-or-not. Includes voided rows on purpose — "voided
 * expenses are ... visible in an audit view" (SPEC.md §3.3) — badge-marked
 * rather than hidden in a separate trash screen.
 */
final class ExpensesListTable extends \WP_List_Table {

	private Expense $expenses;
	private Property $properties;
	private Unit $units;

	/** @var array<int,int>|null */
	private ?array $restrict_to_property_ids;

	public function __construct( ?array $restrict_to_property_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'expense',
				'plural'   => 'expenses',
				'ajax'     => false,
			)
		);

		$this->expenses                 = new Expense();
		$this->properties               = new Property();
		$this->units                    = new Unit();
		$this->restrict_to_property_ids = $restrict_to_property_ids;
	}

	public function get_columns(): array {
		return array(
			'expense_date' => __( 'Date', 'chrx-rental-manager' ),
			'scope'        => __( 'Scope', 'chrx-rental-manager' ),
			'category'     => __( 'Category', 'chrx-rental-manager' ),
			'amount'       => __( 'Amount', 'chrx-rental-manager' ),
			'recurring'    => __( 'Recurring', 'chrx-rental-manager' ),
			'status'       => __( 'Status', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$unit_id = isset( $_GET['unit_id'] ) ? absint( $_GET['unit_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$category = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$from_date = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$to_date = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$recurring = isset( $_GET['recurring'] ) ? sanitize_key( wp_unslash( $_GET['recurring'] ) ) : '';

		if ( $property_id > 0 && null !== $this->restrict_to_property_ids && ! in_array( $property_id, $this->restrict_to_property_ids, true ) ) {
			$property_id = 0;
		}

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$all_rows = $this->expenses->search( $property_id, $unit_id, $category, $from_date, $to_date, $recurring, PHP_INT_MAX, 0 );

		if ( null !== $this->restrict_to_property_ids ) {
			// Account-scoped rows (property_id null) are admin-only visibility
			// (SPEC.md §4.4) — a Staff/Landlord-restricted view never sees them.
			$all_rows = array_values(
				array_filter(
					$all_rows,
					fn( array $row ): bool => null !== $row['property_id'] && in_array( (int) $row['property_id'], $this->restrict_to_property_ids, true )
				)
			);
		}

		$total       = count( $all_rows );
		$this->items = array_slice( $all_rows, ( $current_page - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_expense_date( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page'   => 'chrx-rm-expenses',
				'action' => 'edit',
				'id'     => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s" style="font-weight:600">%s</a>', esc_url( $detail_url ), esc_html( gmdate( 'd M Y', strtotime( $item['expense_date'] ) ) ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_scope( $item ): string {
		if ( Expense::SCOPE_UNIT === $item['scope'] ) {
			$unit = null !== $item['unit_id'] ? $this->units->find( (int) $item['unit_id'] ) : null;

			return esc_html( null === $unit ? __( 'Unit', 'chrx-rental-manager' ) : $unit['unit_label'] );
		}

		if ( Expense::SCOPE_PROPERTY === $item['scope'] ) {
			$property = null !== $item['property_id'] ? $this->properties->find( (int) $item['property_id'] ) : null;

			return esc_html( null === $property ? __( 'Property', 'chrx-rental-manager' ) : $property['name'] );
		}

		return esc_html__( 'Account-wide', 'chrx-rental-manager' );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_category( $item ): string {
		return esc_html( ExpenseCategory::label_for( $item['category'], $item['custom_category_label'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_amount( $item ): string {
		return Money::format( (float) $item['amount'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_recurring( $item ): string {
		if ( Expense::RECURRING_NONE === $item['recurring'] ) {
			return '<span style="color:#8c8f94">—</span>';
		}

		if ( null !== $item['recurring_parent_id'] ) {
			return esc_html(
				sprintf(
					/* translators: %s: recurrence, e.g. "monthly" */
					__( '%s (instance)', 'chrx-rental-manager' ),
					$item['recurring']
				)
			);
		}

		return esc_html(
			sprintf(
				/* translators: %s: recurrence, e.g. "monthly" */
				__( '%s (template)', 'chrx-rental-manager' ),
				$item['recurring']
			)
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_status( $item ): string {
		return null !== $item['voided_at'] ? Badge::render( 'voided' ) : Badge::render( 'active' );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
