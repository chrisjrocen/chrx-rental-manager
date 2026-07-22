<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expenses (SPEC.md §3.2/§3.3, §4.4) — void-not-delete, same pattern as
 * Payment::void()/Charge::mark_waived(): a mistaken expense is voided with
 * a reason and excluded from reports/totals, never removed. Recurring
 * expenses are materialized as ordinary rows linked back to their template
 * via recurring_parent_id (Phase V2-4 fills in the generation cron;
 * this repository only exposes the query/write surface it needs).
 */
final class Expense extends AbstractRepository {

	protected const TABLE = 'rm_expenses';

	public const SCOPE_ACCOUNT  = 'account';
	public const SCOPE_PROPERTY = 'property';
	public const SCOPE_UNIT     = 'unit';

	public const RECURRING_NONE      = 'none';
	public const RECURRING_MONTHLY   = 'monthly';
	public const RECURRING_QUARTERLY = 'quarterly';
	public const RECURRING_ANNUAL    = 'annual';

	public const CATEGORY_WATER       = 'water';
	public const CATEGORY_ELECTRICITY = 'electricity';
	public const CATEGORY_SALARY      = 'salary';
	public const CATEGORY_TAX         = 'tax';
	public const CATEGORY_CLEANING    = 'cleaning';
	public const CATEGORY_CUSTOM      = 'custom';

	/**
	 * Never removed — marked voided instead (SPEC.md §3.3), same
	 * append-only-with-status pattern as Payment::void()/Charge::mark_waived().
	 */
	public function void( int $expense_id, string $reason ): bool {
		return $this->update(
			$expense_id,
			array(
				'voided_at'   => current_time( 'mysql' ),
				'void_reason' => $reason,
			)
		);
	}

	public function is_voided( int $expense_id ): bool {
		$expense = $this->find( $expense_id );

		return null !== $expense && null !== $expense['voided_at'];
	}

	/**
	 * Recurring templates only (recurring != 'none' AND recurring_parent_id
	 * IS NULL) — the set the recurring-expense cron iterates to decide
	 * whether each period's instance still needs materializing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recurring_templates(): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
		return $this->results(
			"SELECT * FROM {$table} WHERE recurring != %s AND recurring_parent_id IS NULL AND voided_at IS NULL",
			array( self::RECURRING_NONE )
		);
	}

	/**
	 * Most recent instance date already materialized for a recurring
	 * template — the recurring-expense cron's anchor for computing the
	 * next period (mirrors ChargeGenerator's "latest existing period"
	 * approach). Null if no instance has been materialized yet, in which
	 * case the template's own expense_date is the anchor.
	 */
	public function latest_instance_date( int $recurring_parent_id ): ?string {
		$table = $this->table_name();

		$date = $this->wpdb()->get_var(
			$this->wpdb()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT MAX(expense_date) FROM {$table} WHERE recurring_parent_id = %d",
				$recurring_parent_id
			)
		);

		return null === $date ? null : (string) $date;
	}

	/**
	 * Dedupe guard for the recurring-expense cron: has this template
	 * already produced an instance for $expense_date's period.
	 */
	public function has_instance_for_period( int $recurring_parent_id, string $expense_date ): bool {
		$table = $this->table_name();

		$count = $this->wpdb()->get_var(
			$this->wpdb()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT COUNT(*) FROM {$table} WHERE recurring_parent_id = %d AND expense_date = %s",
				$recurring_parent_id,
				$expense_date
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Non-voided expenses in a date range, optionally scoped to a property
	 * or unit — the Expense Report's data source (SPEC.md §4.4).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function for_report( string $from_date, string $to_date, ?int $property_id = null, ?int $unit_id = null ): array {
		$table = $this->table_name();

		$where  = array( 'voided_at IS NULL', 'expense_date BETWEEN %s AND %s' );
		$params = array( $from_date, $to_date );

		if ( null !== $property_id ) {
			$where[]  = 'property_id = %d';
			$params[] = $property_id;
		}

		if ( null !== $unit_id ) {
			$where[]  = 'unit_id = %d';
			$params[] = $unit_id;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY expense_date ASC",
			$params
		);
	}

	/**
	 * Admin list screen search/filter (SPEC.md §4.4: "filter by
	 * property/unit/category/date range/recurring-or-not"). Includes
	 * voided rows on purpose — "voided expenses are excluded from reports
	 * but visible in an audit view" (SPEC.md §3.3), and this is that
	 * audit view; for_report() is the reports-only, voided-excluded query.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search(
		int $property_id = 0,
		int $unit_id = 0,
		string $category = '',
		string $from_date = '',
		string $to_date = '',
		string $recurring = '',
		int $limit = 20,
		int $offset = 0
	): array {
		$table  = $this->table_name();
		$where  = array( '1=1' );
		$params = array();

		if ( $property_id > 0 ) {
			$where[]  = 'property_id = %d';
			$params[] = $property_id;
		}

		if ( $unit_id > 0 ) {
			$where[]  = 'unit_id = %d';
			$params[] = $unit_id;
		}

		if ( '' !== $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		if ( '' !== $from_date ) {
			$where[]  = 'expense_date >= %s';
			$params[] = $from_date;
		}

		if ( '' !== $to_date ) {
			$where[]  = 'expense_date <= %s';
			$params[] = $to_date;
		}

		if ( '' !== $recurring ) {
			$where[]  = 'recurring = %s';
			$params[] = $recurring;
		}

		$params[] = $limit;
		$params[] = $offset;

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY expense_date DESC, id DESC LIMIT %d OFFSET %d",
			$params
		);
	}

	/**
	 * Count for search()'s filter set, without LIMIT/OFFSET — the list
	 * table's pagination needs the true total, not the current page size.
	 */
	public function count_search( int $property_id = 0, int $unit_id = 0, string $category = '', string $from_date = '', string $to_date = '', string $recurring = '' ): int {
		$table  = $this->table_name();
		$where  = array( '1=1' );
		$params = array();

		if ( $property_id > 0 ) {
			$where[]  = 'property_id = %d';
			$params[] = $property_id;
		}

		if ( $unit_id > 0 ) {
			$where[]  = 'unit_id = %d';
			$params[] = $unit_id;
		}

		if ( '' !== $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		if ( '' !== $from_date ) {
			$where[]  = 'expense_date >= %s';
			$params[] = $from_date;
		}

		if ( '' !== $to_date ) {
			$where[]  = 'expense_date <= %s';
			$params[] = $to_date;
		}

		if ( '' !== $recurring ) {
			$where[]  = 'recurring = %s';
			$params[] = $recurring;
		}

		$where_sql = implode( ' AND ', $where );

		if ( array() === $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input (empty filter set).
			return (int) $this->wpdb()->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		return (int) $this->wpdb()->get_var(
			$this->wpdb()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/where clause built from a fixed allow-list above, no raw user input.
				"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
				$params
			)
		);
	}
}
