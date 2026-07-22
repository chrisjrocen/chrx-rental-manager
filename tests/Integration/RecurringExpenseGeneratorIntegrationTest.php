<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Cron\RecurringExpenseGenerator;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Property;

/**
 * DB-facing coverage for RecurringExpenseGenerator::generate() — dedup and
 * template-vs-instance behavior that the pure unit tests can't exercise
 * since they need real inserted rows to check against (mirrors
 * ChargeGeneratorIntegrationTest's structure for ChargeGenerator).
 */
final class RecurringExpenseGeneratorIntegrationTest extends IntegrationTestCase {

	private Expense $expenses;
	private RecurringExpenseGenerator $generator;
	private int $property_id;

	protected function setUp(): void {
		parent::setUp();

		$this->expenses = new Expense();
		$this->generator = new RecurringExpenseGenerator( $this->expenses );

		$properties        = new Property();
		$this->property_id = $properties->insert( [ 'name' => 'Recurring Expense Test Property', 'city' => 'Accra' ] );
	}

	private function insert_template( string $expense_date, string $recurring = Expense::RECURRING_MONTHLY ): int {
		return $this->expenses->insert( [
			'scope'                 => Expense::SCOPE_PROPERTY,
			'property_id'           => $this->property_id,
			'unit_id'               => null,
			'category'              => Expense::CATEGORY_SALARY,
			'custom_category_label' => null,
			'amount'                => 200,
			'expense_date'          => $expense_date,
			'description'           => 'Recurring template',
			'recurring'             => $recurring,
			'recurring_parent_id'   => null,
			'recorded_by'           => 1,
		] );
	}

	public function test_generate_materializes_the_next_instance_once_its_period_is_due(): void {
		// Template dated one month ago -> the next monthly period is due today.
		$template_id = $this->insert_template( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 month' ) ) );

		$created = $this->generator->generate();

		$this->assertSame( 1, $created );

		$instances = array_filter(
			$this->expenses->search( $this->property_id ),
			static fn( array $row ): bool => null !== $row['recurring_parent_id']
		);

		$this->assertCount( 1, $instances );
		$instance = array_values( $instances )[0];
		$this->assertSame( $template_id, (int) $instance['recurring_parent_id'] );
		$this->assertSame( 200.0, (float) $instance['amount'] );
	}

	public function test_generate_does_not_create_a_duplicate_once_the_period_is_covered(): void {
		$this->insert_template( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 month' ) ) );

		$this->generator->generate();
		$first_run_count = count( $this->expenses->search( $this->property_id ) );

		$this->generator->generate();
		$second_run_count = count( $this->expenses->search( $this->property_id ) );

		$this->assertSame( $first_run_count, $second_run_count, 'Once the currently-due period is materialized, another run must not create a duplicate.' );
	}

	public function test_generate_does_not_materialize_a_period_that_is_not_yet_due(): void {
		// Template dated today -> the next monthly period isn't due for
		// another month, and recurring expenses have no lead-time window.
		$this->insert_template( current_time( 'Y-m-d' ) );

		$created = $this->generator->generate();

		$this->assertSame( 0, $created );
	}

	public function test_generate_skips_voided_templates(): void {
		$template_id = $this->insert_template( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 month' ) ) );
		$this->expenses->void( $template_id, 'Cancelled subscription' );

		$created = $this->generator->generate();

		$this->assertSame( 0, $created );
	}

	public function test_editing_the_template_does_not_touch_already_materialized_instances(): void {
		$template_id = $this->insert_template( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 month' ) ) );
		$this->generator->generate();

		$instances_before = array_filter(
			$this->expenses->search( $this->property_id ),
			static fn( array $row ): bool => null !== $row['recurring_parent_id']
		);
		$instance_id       = array_values( $instances_before )[0]['id'];

		$this->expenses->update( $template_id, [ 'amount' => 999 ] );

		$instance_after = $this->expenses->find( (int) $instance_id );
		$this->assertSame( 200.0, (float) $instance_after['amount'], 'Editing the template must only affect future instances, not ones already materialized (SPEC.md §4.4).' );
	}
}
