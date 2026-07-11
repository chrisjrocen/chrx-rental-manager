<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipts — append-only (SPEC.md §3), generated once per payment in the
 * Payments & Receipts phase.
 */
final class Receipt extends AbstractRepository {

	protected const TABLE = 'rm_receipts';

	public function find_by_payment( int $payment_id ): ?array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->row( "SELECT * FROM {$table} WHERE payment_id = %d", array( $payment_id ) );
	}

	public function find_by_receipt_number( string $receipt_number ): ?array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->row( "SELECT * FROM {$table} WHERE receipt_number = %s", array( $receipt_number ) );
	}

	public function mark_emailed( int $receipt_id ): bool {
		return $this->update( $receipt_id, array( 'emailed_at' => current_time( 'mysql' ) ) );
	}
}
