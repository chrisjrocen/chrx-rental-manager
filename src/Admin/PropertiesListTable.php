<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Properties list (designs/05-properties-list.html): search, pagination,
 * occupancy bar, landlord-owner + assigned staff, monthly rent roll.
 * Rows are pre-scoped by the controller to whatever property ids the
 * current user (Staff/Landlord-Owner) may see — this table never queries
 * unscoped.
 */
final class PropertiesListTable extends \WP_List_Table {

	private Property $properties;
	private Unit $units;
	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;

	/** @var array<int,int>|null */
	private ?array $restrict_to_ids;

	public function __construct( ?array $restrict_to_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'property',
				'plural'   => 'properties',
				'ajax'     => false,
			)
		);

		$this->properties         = new Property();
		$this->units              = new Unit();
		$this->property_staff     = new PropertyStaff();
		$this->property_landlords = new PropertyLandlord();
		$this->restrict_to_ids    = $restrict_to_ids;
	}

	public function get_columns(): array {
		return array(
			'name'      => __( 'Property', 'chrx-rental-manager' ),
			'units'     => __( 'Units', 'chrx-rental-manager' ),
			'occupancy' => __( 'Occupancy', 'chrx-rental-manager' ),
			'landlord'  => __( 'Landlord-Owner', 'chrx-rental-manager' ),
			'staff'     => __( 'Assigned staff', 'chrx-rental-manager' ),
			'rent_roll' => __( 'Monthly rent roll', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/pagination params, no state change.
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$all_rows = $this->properties->search( $search, PHP_INT_MAX, 0 );

		if ( null !== $this->restrict_to_ids ) {
			$all_rows = array_values(
				array_filter(
					$all_rows,
					fn( array $row ): bool => in_array( (int) $row['id'], $this->restrict_to_ids, true )
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
	public function column_name( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page' => 'chrx-rm-properties',
				'id'   => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<a href="%s" style="font-weight:600">%s</a><div style="font-size:12px;color:#646970">%s</div>',
			esc_url( $detail_url ),
			esc_html( $item['name'] ),
			esc_html( trim( $item['address'] . ( '' !== $item['city'] ? ', ' . $item['city'] : '' ), ', ' ) )
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_units( $item ): string {
		return (string) count( $this->units_for( (int) $item['id'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_occupancy( $item ): string {
		$units = $this->units_for( (int) $item['id'] );
		$total = count( $units );

		if ( 0 === $total ) {
			return '<span style="color:#8c8f94">—</span>';
		}

		$occupied = count( array_filter( $units, fn( array $u ): bool => Unit::STATUS_OCCUPIED === $u['status'] ) );
		$percent  = (int) round( ( $occupied / $total ) * 100 );

		return sprintf(
			'<div style="display:flex;align-items:center;gap:8px"><div style="width:70px;height:6px;background:#dcdcde;border-radius:3px;overflow:hidden"><div style="width:%1$d%%;height:100%%;background:#0a7d34"></div></div><span style="font-size:12px;color:#646970">%1$d%%</span></div>',
			$percent
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_landlord( $item ): string {
		$user_ids = $this->property_landlords->user_ids_for_property( (int) $item['id'] );

		return $this->user_names( $user_ids );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_staff( $item ): string {
		$user_ids = $this->property_staff->user_ids_for_property( (int) $item['id'] );

		return $this->user_names( $user_ids );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_rent_roll( $item ): string {
		$units = $this->units_for( (int) $item['id'] );
		$total = array_sum( array_column( $units, 'rent_amount' ) );

		return '<strong>' . Money::format( (float) $total ) . '</strong>';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function units_for( int $property_id ): array {
		static $cache = array();

		if ( ! isset( $cache[ $property_id ] ) ) {
			$cache[ $property_id ] = $this->units->for_property( $property_id );
		}

		return $cache[ $property_id ];
	}

	/**
	 * @param array<int,int> $user_ids
	 */
	private function user_names( array $user_ids ): string {
		if ( array() === $user_ids ) {
			return '<span style="color:#8c8f94">—</span>';
		}

		$names = array_map(
			function ( int $id ): string {
				$user = get_userdata( $id );

				return false === $user ? '' : $user->display_name;
			},
			$user_ids
		);

		return esc_html( implode( ', ', array_filter( $names ) ) );
	}
}
