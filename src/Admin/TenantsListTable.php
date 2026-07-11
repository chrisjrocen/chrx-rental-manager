<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Admin\Support\PortalStatus;
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
 * Tenants list (designs/11-tenants-list.html): Active/Former tabs,
 * search, pagination. Staff only ever see tenants tied to a property
 * they're assigned to (a tenant with no lease yet is visible to any
 * staff with manage-tenants, matching TenantInviteController's
 * can_invite() scoping rule from the Roles & Permissions phase).
 */
final class TenantsListTable extends \WP_List_Table {

	private Tenant $tenants;
	private Lease $leases;
	private Unit $units;
	private Ledger $ledger;

	/** @var array<int,int>|null */
	private ?array $restrict_to_property_ids;

	public function __construct( ?array $restrict_to_property_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'tenant',
				'plural'   => 'tenants',
				'ajax'     => false,
			)
		);

		$this->tenants                  = new Tenant();
		$this->leases                   = new Lease();
		$this->units                    = new Unit();
		$this->ledger                   = new Ledger();
		$this->restrict_to_property_ids = $restrict_to_property_ids;
	}

	public function get_columns(): array {
		return array(
			'name'    => __( 'Tenant', 'chrx-rental-manager' ),
			'contact' => __( 'Contact', 'chrx-rental-manager' ),
			'unit'    => __( 'Unit', 'chrx-rental-manager' ),
			'portal'  => __( 'Portal', 'chrx-rental-manager' ),
			'balance' => __( 'Balance', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search/filter/pagination params, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Tenant::STATUS_ACTIVE;

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$all_rows = $this->tenants->search( $search, $status, PHP_INT_MAX, 0 );

		if ( null !== $this->restrict_to_property_ids ) {
			$all_rows = array_values(
				array_filter(
					$all_rows,
					fn( array $row ): bool => $this->tenant_visible( (int) $row['id'] )
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

	private function tenant_visible( int $tenant_id ): bool {
		$leases = $this->leases->for_tenant( $tenant_id );

		if ( array() === $leases ) {
			return true;
		}

		foreach ( $leases as $lease ) {
			$unit = $this->units->find( (int) $lease['unit_id'] );

			if ( null !== $unit && in_array( (int) $unit['property_id'], $this->restrict_to_property_ids ?? array(), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_name( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page' => 'chrx-rm-tenants',
				'id'   => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s" style="font-weight:600">%s</a>', esc_url( $detail_url ), esc_html( $item['full_name'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_contact( $item ): string {
		return esc_html( $item['phone'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_unit( $item ): string {
		$lease = $this->active_lease_for_tenant( (int) $item['id'] );

		if ( null === $lease ) {
			return '<span style="color:#8c8f94">—</span>';
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		return null === $unit ? '' : esc_html( $unit['unit_label'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_portal( $item ): string {
		$status = PortalStatus::for_tenant( $item );

		return Badge::render( PortalStatus::badge_key( $status ), PortalStatus::label( $status ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_balance( $item ): string {
		$balance = $this->ledger->outstanding_balance_for_tenant( (int) $item['id'] );

		if ( $balance > 0 ) {
			return '<strong style="color:#b32d2e">' . Money::format( $balance ) . '</strong>';
		}

		return Money::format( $balance );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function active_lease_for_tenant( int $tenant_id ): ?array {
		foreach ( $this->leases->for_tenant( $tenant_id ) as $lease ) {
			if ( Lease::STATUS_ACTIVE === $lease['status'] ) {
				return $lease;
			}
		}

		return null;
	}
}
