<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cron;

use ChrxRentalManager\Data\Expense;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `rm_generate_recurring_expenses` (SPEC.md §4.4/§6, daily): materializes
 * each recurring expense template's next due instance. Mirrors
 * ChargeGenerator's shape — a pure static date-math method plus a thin
 * DB-facing generate() wrapper — but with two deliberate differences from
 * charge generation: no lead-time window (SPEC.md doesn't call for one,
 * unlike rent charges) and the template row itself counts as the first
 * occurrence (an expense template is a real, already-recorded expense,
 * unlike a lease which never gets an implicit "period 0" charge) — so the
 * cron only ever generates period 2 onward, anchored on the latest
 * materialized instance (or the template's own date if none exist yet).
 *
 * "Editing the template affects only future instances" (SPEC.md §4.4)
 * falls out naturally: instances are ordinary, independently editable
 * rows copied from the template at generation time, not references to it.
 */
final class RecurringExpenseGenerator {

	private Expense $expenses;

	public function __construct( ?Expense $expenses = null ) {
		$this->expenses = $expenses ?? new Expense();
	}

	/**
	 * @return int number of instances created
	 */
	public function generate(): int {
		$today   = current_time( 'Y-m-d' );
		$created = 0;

		foreach ( $this->expenses->recurring_templates() as $template ) {
			$anchor = $this->expenses->latest_instance_date( (int) $template['id'] ) ?? $template['expense_date'];
			$next   = self::compute_next_period_date( $anchor, $template['recurring'] );

			if ( $next > $today ) {
				continue;
			}

			if ( $this->expenses->has_instance_for_period( (int) $template['id'], $next ) ) {
				continue;
			}

			$this->expenses->insert(
				array(
					'scope'                 => $template['scope'],
					'property_id'           => $template['property_id'],
					'unit_id'               => $template['unit_id'],
					'category'              => $template['category'],
					'custom_category_label' => $template['custom_category_label'],
					'amount'                => $template['amount'],
					'expense_date'          => $next,
					'description'           => $template['description'],
					'recurring'             => $template['recurring'],
					'recurring_parent_id'   => (int) $template['id'],
					'recorded_by'           => (int) $template['recorded_by'],
				)
			);

			++$created;
		}

		return $created;
	}

	/**
	 * Pure date math: the next period's expense_date after $anchor_date,
	 * advancing by the interval $recurring implies (monthly=1, quarterly=3,
	 * annual=12 months).
	 */
	public static function compute_next_period_date( string $anchor_date, string $recurring ): string {
		$months = match ( $recurring ) {
			Expense::RECURRING_QUARTERLY => 3,
			Expense::RECURRING_ANNUAL => 12,
			default => 1,
		};

		$anchor = new \DateTimeImmutable( $anchor_date );

		return $anchor->modify( "+{$months} months" )->format( 'Y-m-d' );
	}
}
