<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Payments list (designs/20-payments-list.html): filter by
 * property/method/month, pagination. Rows pre-scoped by the controller to
 * whatever property ids the current user may see, same pattern as
 * LeasesListTable.
 */
final class PaymentsListTable extends \WP_List_Table {

	private Payment $payments;
	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Receipt $receipts;

	/** @var array<int,int>|null */
	private ?array $restrict_to_property_ids;

	private int $filtered_count   = 0;
	private float $filtered_total = 0.0;

	public function __construct( ?array $restrict_to_property_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'payment',
				'plural'   => 'payments',
				'ajax'     => false,
			)
		);

		$this->payments                 = new Payment();
		$this->leases                   = new Lease();
		$this->units                    = new Unit();
		$this->tenants                  = new Tenant();
		$this->receipts                 = new Receipt();
		$this->restrict_to_property_ids = $restrict_to_property_ids;
	}

	public function get_columns(): array {
		return array(
			'date'    => __( 'Date', 'chrx-rental-manager' ),
			'tenant'  => __( 'Tenant', 'chrx-rental-manager' ),
			'unit'    => __( 'Unit', 'chrx-rental-manager' ),
			'method'  => __( 'Method', 'chrx-rental-manager' ),
			'amount'  => __( 'Amount', 'chrx-rental-manager' ),
			'receipt' => __( 'Receipt', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination params, no state change.
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination params, no state change.
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination params, no state change.
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		$rows = self::apply_filters( $this->payments->all_ordered(), $this->leases, $this->units, $this->restrict_to_property_ids, $property_id, $method, $month );

		$this->filtered_count = count( $rows );
		$this->filtered_total = array_sum( array_map( static fn( array $r ): float => (float) $r['amount'], $rows ) );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$this->items  = array_slice( $rows, ( $current_page - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $this->filtered_count,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $this->filtered_count / $per_page ),
			)
		);
	}

	/**
	 * Pure-ish filter step shared with the CSV export so both surfaces
	 * apply identical scoping/filter logic.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,int>|null            $restrict_to_property_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function apply_filters( array $rows, Lease $leases, Unit $units, ?array $restrict_to_property_ids, int $property_id, string $method, string $month ): array {
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $leases, $units, $restrict_to_property_ids, $property_id, $method, $month ): bool {
					$lease = $leases->find( (int) $row['lease_id'] );
					$unit  = null !== $lease ? $units->find( (int) $lease['unit_id'] ) : null;

					if ( null === $unit ) {
						return false;
					}

					if ( null !== $restrict_to_property_ids && ! in_array( (int) $unit['property_id'], $restrict_to_property_ids, true ) ) {
						return false;
					}

					if ( $property_id > 0 && $property_id !== (int) $unit['property_id'] ) {
						return false;
					}

					if ( '' !== $method && $method !== $row['method'] ) {
						return false;
					}

					if ( '' !== $month && gmdate( 'Y-m', strtotime( $row['paid_at'] ) ) !== $month ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	public function filtered_count(): int {
		return $this->filtered_count;
	}

	public function filtered_total(): float {
		return $this->filtered_total;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_date( $item ): string {
		return esc_html( gmdate( 'd M', strtotime( $item['paid_at'] ) ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_tenant( $item ): string {
		$lease  = $this->leases->find( (int) $item['lease_id'] );
		$tenant = null !== $lease ? $this->tenants->find( (int) $lease['tenant_id'] ) : null;

		$detail_url = add_query_arg(
			array(
				'page' => LeasesController::page_slug(),
				'id'   => $item['lease_id'],
			),
			admin_url( 'admin.php' )
		);

		$closing_out_flag = null !== $lease && Lease::STATUS_ACTIVE !== $lease['status']
			? ' <span style="font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#8a6116;background:#fbf0dd;border:1px solid #ecd9ad;border-radius:3px;padding:1px 6px;">' . esc_html__( 'Closing out', 'chrx-rental-manager' ) . '</span>'
			: '';

		return sprintf( '<a href="%s" style="font-weight:600">%s</a>%s', esc_url( $detail_url ), esc_html( null === $tenant ? '' : $tenant['full_name'] ), $closing_out_flag );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_unit( $item ): string {
		$lease = $this->leases->find( (int) $item['lease_id'] );
		$unit  = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

		return esc_html( null === $unit ? '' : $unit['unit_label'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_method( $item ): string {
		return esc_html( self::method_label( $item['method'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_amount( $item ): string {
		return '<strong>' . Money::format( (float) $item['amount'] ) . '</strong>';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_receipt( $item ): string {
		if ( null === $item['receipt_id'] ) {
			return '&#8212;';
		}

		$receipt = $this->receipts->find( (int) $item['receipt_id'] );

		if ( null === $receipt ) {
			return '&#8212;';
		}

		$url = add_query_arg(
			array(
				'page'   => PaymentsController::page_slug(),
				'action' => 'receipt',
				'id'     => $receipt['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s">#%s</a>', esc_url( $url ), esc_html( $receipt['receipt_number'] ) );
	}

	public static function method_label( string $method ): string {
		$labels = array(
			'cash'          => __( 'Cash', 'chrx-rental-manager' ),
			'bank_transfer' => __( 'Bank transfer', 'chrx-rental-manager' ),
			'mtn_momo'      => __( 'MTN MoMo', 'chrx-rental-manager' ),
			'airtel_money'  => __( 'Airtel Money', 'chrx-rental-manager' ),
			'other'         => __( 'Other', 'chrx-rental-manager' ),
		);

		return $labels[ $method ] ?? ucfirst( str_replace( '_', ' ', $method ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
