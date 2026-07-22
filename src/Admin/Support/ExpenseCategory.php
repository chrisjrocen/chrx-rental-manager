<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin\Support;

use ChrxRentalManager\Data\Expense;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presentation-layer label map for Expense::CATEGORY_* (SPEC.md §3.2) —
 * kept out of the Data layer the same way Badge.php centralizes status
 * labels rather than duplicating them per-template.
 */
final class ExpenseCategory {

	/**
	 * @return array<string,string> category key => display label
	 */
	public static function labels(): array {
		return array(
			Expense::CATEGORY_WATER       => __( 'Water', 'chrx-rental-manager' ),
			Expense::CATEGORY_ELECTRICITY => __( 'Electricity', 'chrx-rental-manager' ),
			Expense::CATEGORY_SALARY      => __( 'Salary', 'chrx-rental-manager' ),
			Expense::CATEGORY_TAX         => __( 'Tax', 'chrx-rental-manager' ),
			Expense::CATEGORY_CLEANING    => __( 'Cleaning', 'chrx-rental-manager' ),
			Expense::CATEGORY_CUSTOM      => __( 'Custom', 'chrx-rental-manager' ),
		);
	}

	/**
	 * Display label for a single expense row: the custom label when the
	 * category is "custom" and one was given, otherwise the fixed
	 * category's display label.
	 */
	public static function label_for( string $category, ?string $custom_category_label ): string {
		if ( Expense::CATEGORY_CUSTOM === $category && null !== $custom_category_label && '' !== $custom_category_label ) {
			return $custom_category_label;
		}

		return self::labels()[ $category ] ?? ucfirst( $category );
	}
}
