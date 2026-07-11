<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Badge;
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
 * Units list (designs/08-units-list.html): search, filter by property &
 * status, pagination. Rows are pre-scoped by the controller to whatever
 * property ids the current user may see.
 */
final class UnitsListTable extends \WP_List_Table {

	private Unit $units;
	private Property $properties;
	private Lease $leases;
	private Tenant $tenants;

	/** @var array<int,int>|null */
	private ?array $restrict_to_property_ids;

	public function __construct( ?array $restrict_to_property_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'unit',
				'plural'   => 'units',
				'ajax'     => false,
			)
		);

		$this->units                    = new Unit();
		$this->properties               = new Property();
		$this->leases                   = new Lease();
		$this->tenants                  = new Tenant();
		$this->restrict_to_property_ids = $restrict_to_property_ids;
	}

	public function get_columns(): array {
		return array(
			'unit'     => __( 'Unit', 'chrx-rental-manager' ),
			'property' => __( 'Property', 'chrx-rental-manager' ),
			'type'     => __( 'Type', 'chrx-rental-manager' ),
			'tenant'   => __( 'Tenant', 'chrx-rental-manager' ),
			'rent'     => __( 'Rent', 'chrx-rental-manager' ),
			'status'   => __( 'Status', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$all_rows = $this->units->search( $search, $status, $property_id, PHP_INT_MAX, 0 );

		if ( null !== $this->restrict_to_property_ids ) {
			$all_rows = array_values(
				array_filter(
					$all_rows,
					fn( array $row ): bool => in_array( (int) $row['property_id'], $this->restrict_to_property_ids, true )
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
	public function column_unit( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page' => 'chrx-rm-units',
				'id'   => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s" style="font-weight:600">%s</a>', esc_url( $detail_url ), esc_html( $item['unit_label'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_property( $item ): string {
		$property = $this->properties->find( (int) $item['property_id'] );

		return null === $property ? '' : esc_html( $property['name'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_type( $item ): string {
		$bedrooms = (int) $item['bedrooms'];

		if ( 0 === $bedrooms ) {
			return esc_html__( 'Studio', 'chrx-rental-manager' );
		}

		return esc_html( sprintf( '%d-bed', $bedrooms ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_tenant( $item ): string {
		$lease = $this->leases->active_lease_for_unit( (int) $item['id'] );

		if ( null === $lease ) {
			return '<span style="color:#8c8f94">—</span>';
		}

		$tenant = $this->tenants->find( (int) $lease['tenant_id'] );

		return null === $tenant ? '' : esc_html( $tenant['full_name'] );
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
