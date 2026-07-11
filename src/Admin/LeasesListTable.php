<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Leases list (designs/14-leases-list.html): filter by status/property,
 * "expiring within 30 days" flag, pagination. Rows pre-scoped by the
 * controller to whatever property ids the current user may see.
 */
final class LeasesListTable extends \WP_List_Table {

	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Property $properties;
	private Ledger $ledger;

	/** @var array<int,int>|null */
	private ?array $restrict_to_property_ids;

	public function __construct( ?array $restrict_to_property_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'lease',
				'plural'   => 'leases',
				'ajax'     => false,
			)
		);

		$this->leases                   = new Lease();
		$this->units                    = new Unit();
		$this->tenants                  = new Tenant();
		$this->properties               = new Property();
		$this->ledger                   = new Ledger();
		$this->restrict_to_property_ids = $restrict_to_property_ids;
	}

	public function get_columns(): array {
		return array(
			'tenant'  => __( 'Tenant / Unit', 'chrx-rental-manager' ),
			'term'    => __( 'Term', 'chrx-rental-manager' ),
			'rent'    => __( 'Rent', 'chrx-rental-manager' ),
			'balance' => __( 'Balance', 'chrx-rental-manager' ),
			'status'  => __( 'Status', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination params, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination params, no state change.
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination params, no state change.
		$expiring_only = isset( $_GET['expiring'] ) && '1' === $_GET['expiring'];

		global $wpdb;
		$table = $wpdb->prefix . 'rm_leases';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$all_rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted_at IS NULL ORDER BY end_date ASC", ARRAY_A );

		if ( '' !== $status ) {
			$all_rows = array_values( array_filter( $all_rows, fn( array $r ): bool => $status === $r['status'] ) );
		}

		if ( $property_id > 0 ) {
			$all_rows = array_values(
				array_filter(
					$all_rows,
					function ( array $row ) use ( $property_id ): bool {
						$unit = $this->units->find( (int) $row['unit_id'] );

						return null !== $unit && $property_id === (int) $unit['property_id'];
					}
				)
			);
		}

		if ( $expiring_only ) {
			$cutoff   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
			$all_rows = array_values(
				array_filter(
					$all_rows,
					fn( array $r ): bool => Lease::STATUS_ACTIVE === $r['status'] && $r['end_date'] <= $cutoff
				)
			);
		}

		if ( null !== $this->restrict_to_property_ids ) {
			$all_rows = array_values(
				array_filter(
					$all_rows,
					function ( array $row ): bool {
						$unit = $this->units->find( (int) $row['unit_id'] );

						return null !== $unit && in_array( (int) $unit['property_id'], $this->restrict_to_property_ids, true );
					}
				)
			);
		}

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total        = count( $all_rows );
		$this->items  = array_slice( $all_rows, ( $current_page - 1 ) * $per_page, $per_page );

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
	public function column_tenant( $item ): string {
		$tenant   = $this->tenants->find( (int) $item['tenant_id'] );
		$unit     = $this->units->find( (int) $item['unit_id'] );
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		$detail_url = add_query_arg(
			array(
				'page' => 'chrx-rm-leases',
				'id'   => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$unit_line = ( null !== $unit ? $unit['unit_label'] : '' ) . ( null !== $property ? ' · ' . $property['name'] : '' );

		return sprintf(
			'<a href="%s" style="font-weight:600">%s</a><div style="color:#646970;font-size:12px">%s</div>',
			esc_url( $detail_url ),
			esc_html( null === $tenant ? '' : $tenant['full_name'] ),
			esc_html( $unit_line )
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_term( $item ): string {
		$term = gmdate( 'M Y', strtotime( $item['start_date'] ) ) . ' – ' . gmdate( 'M Y', strtotime( $item['end_date'] ) );

		if ( Lease::STATUS_ACTIVE !== $item['status'] ) {
			return esc_html( $term );
		}

		$days_left = (int) ceil( ( strtotime( $item['end_date'] ) - time() ) / DAY_IN_SECONDS );

		if ( $days_left <= 30 && $days_left >= 0 ) {
			$flag_color = $days_left <= 7 ? 'overdue' : 'maintenance';

			return esc_html( $term ) . ' ' . Badge::render(
				$flag_color,
				sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $days_left, 'chrx-rental-manager' ), $days_left )
			);
		}

		return esc_html( $term );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_rent( $item ): string {
		return Money::format( (float) $item['rent_amount'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_balance( $item ): string {
		$balance = $this->ledger->outstanding_balance_for_lease( (int) $item['id'] );

		if ( $balance > 0 ) {
			return '<strong style="color:#b32d2e">' . Money::format( $balance ) . '</strong>';
		}

		return Money::format( $balance );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_status( $item ): string {
		return Badge::render( $item['status'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
