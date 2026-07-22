<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Property;

/**
 * Repository-level coverage for Expense (SPEC.md §3.2/§3.3/§4.4):
 * void-not-delete, the admin list screen's search()/count_search()
 * filters, and the recurring-expense cron's latest_instance_date()/
 * has_instance_for_period() dedupe helpers.
 */
final class ExpenseTest extends IntegrationTestCase {

	private Expense $expenses;
	private int $property_id;

	protected function setUp(): void {
		parent::setUp();

		$this->expenses = new Expense();

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Expense Test Property', 'city' => 'Accra' ] );
	}

	private function insert_expense( array $overrides = [] ): int {
		return $this->expenses->insert( array_merge(
			[
				'scope'                 => Expense::SCOPE_PROPERTY,
				'property_id'           => $this->property_id,
				'unit_id'               => null,
				'category'              => Expense::CATEGORY_WATER,
				'custom_category_label' => null,
				'amount'                => 100,
				'expense_date'          => current_time( 'Y-m-d' ),
				'description'           => 'Test expense',
				'recurring'             => Expense::RECURRING_NONE,
				'recurring_parent_id'   => null,
				'recorded_by'           => 1,
			],
			$overrides
		) );
	}

	public function test_void_sets_voided_at_and_reason_without_removing_the_row(): void {
		$expense_id = $this->insert_expense();

		$this->assertFalse( $this->expenses->is_voided( $expense_id ) );

		$this->expenses->void( $expense_id, 'Recorded in error' );

		$this->assertTrue( $this->expenses->is_voided( $expense_id ) );

		$row = $this->expenses->find( $expense_id );
		$this->assertNotNull( $row, 'A voided expense must still exist as a row, never removed.' );
		$this->assertSame( 'Recorded in error', $row['void_reason'] );
	}

	public function test_for_report_excludes_voided_expenses(): void {
		$active_id = $this->insert_expense();
		$voided_id = $this->insert_expense();
		$this->expenses->void( $voided_id, 'Mistake' );

		$today = current_time( 'Y-m-d' );
		$rows  = $this->expenses->for_report( $today, $today, $this->property_id );
		$ids   = array_map( static fn( array $row ): int => (int) $row['id'], $rows );

		$this->assertContains( $active_id, $ids );
		$this->assertNotContains( $voided_id, $ids, 'A voided expense must never appear in report output.' );
	}

	public function test_for_report_excludes_account_scoped_rows_when_filtered_by_property(): void {
		$this->insert_expense( [ 'scope' => Expense::SCOPE_ACCOUNT, 'property_id' => null ] );
		$property_expense_id = $this->insert_expense();

		$today = current_time( 'Y-m-d' );
		$rows  = $this->expenses->for_report( $today, $today, $this->property_id );
		$ids   = array_map( static fn( array $row ): int => (int) $row['id'], $rows );

		$this->assertSame( [ $property_expense_id ], $ids, 'property_id = %d in the WHERE clause can never match an account-scoped row\'s NULL property_id.' );
	}

	public function test_search_filters_by_category_and_includes_voided_rows_for_the_audit_view(): void {
		$water_id = $this->insert_expense( [ 'category' => Expense::CATEGORY_WATER ] );
		$this->insert_expense( [ 'category' => Expense::CATEGORY_ELECTRICITY ] );
		$this->expenses->void( $water_id, 'Duplicate entry' );

		$results = $this->expenses->search( 0, 0, Expense::CATEGORY_WATER );
		$ids     = array_map( static fn( array $row ): int => (int) $row['id'], $results );

		$this->assertContains( $water_id, $ids, 'search() is the audit view — voided rows must still be visible in it.' );
	}

	public function test_search_filters_by_date_range(): void {
		$today     = current_time( 'Y-m-d' );
		$in_range  = $this->insert_expense( [ 'expense_date' => $today ] );
		$out_range = $this->insert_expense( [ 'expense_date' => gmdate( 'Y-m-d', strtotime( $today . ' -1 year' ) ) ] );

		$results = $this->expenses->search( 0, 0, '', $today, $today );
		$ids     = array_map( static fn( array $row ): int => (int) $row['id'], $results );

		$this->assertContains( $in_range, $ids );
		$this->assertNotContains( $out_range, $ids );
	}

	public function test_count_search_matches_search_result_count(): void {
		$this->insert_expense();
		$this->insert_expense();

		$count = $this->expenses->count_search( $this->property_id );
		$rows  = $this->expenses->search( $this->property_id );

		$this->assertSame( count( $rows ), $count );
		$this->assertGreaterThanOrEqual( 2, $count );
	}

	public function test_latest_instance_date_is_null_until_an_instance_exists(): void {
		$template_id = $this->insert_expense( [ 'recurring' => Expense::RECURRING_MONTHLY ] );

		$this->assertNull( $this->expenses->latest_instance_date( $template_id ) );

		$this->insert_expense( [ 'recurring_parent_id' => $template_id, 'expense_date' => '2026-03-01' ] );
		$this->insert_expense( [ 'recurring_parent_id' => $template_id, 'expense_date' => '2026-04-01' ] );

		$this->assertSame( '2026-04-01', $this->expenses->latest_instance_date( $template_id ) );
	}

	public function test_has_instance_for_period(): void {
		$template_id = $this->insert_expense( [ 'recurring' => Expense::RECURRING_MONTHLY ] );

		$this->assertFalse( $this->expenses->has_instance_for_period( $template_id, '2026-05-01' ) );

		$this->insert_expense( [ 'recurring_parent_id' => $template_id, 'expense_date' => '2026-05-01' ] );

		$this->assertTrue( $this->expenses->has_instance_for_period( $template_id, '2026-05-01' ) );
	}

	public function test_recurring_templates_excludes_instances_and_voided_templates(): void {
		$template_id = $this->insert_expense( [ 'recurring' => Expense::RECURRING_MONTHLY ] );
		$this->insert_expense( [ 'recurring_parent_id' => $template_id, 'recurring' => Expense::RECURRING_MONTHLY, 'expense_date' => '2026-05-01' ] );
		$voided_template_id = $this->insert_expense( [ 'recurring' => Expense::RECURRING_MONTHLY ] );
		$this->expenses->void( $voided_template_id, 'Cancelled' );

		$templates = $this->expenses->recurring_templates();
		$ids       = array_map( static fn( array $row ): int => (int) $row['id'], $templates );

		$this->assertContains( $template_id, $ids );
		$this->assertNotContains( $voided_template_id, $ids, 'A voided template must not still be iterated by the cron.' );
	}
}
