<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\Badge;
use ChrxRentalManager\Data\Alert;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Custom Alerts list (SPEC.md §4.8). Every alert (active or not) is
 * shown — SPEC.md doesn't call for a separate trash/archive view the way
 * Units/Tenants/Leases have, only an active/inactive toggle.
 *
 * Account-level alerts (entity_type = none) are Administrator-only
 * visibility — restrict_to_property_ids !== null means a Staff/Landlord
 * caller, who never sees them, mirroring ExpensesListTable's identical
 * rule for account-scoped expenses.
 */
final class AlertsListTable extends \WP_List_Table {

	private Alert $alerts;
	private Property $properties;
	private Unit $units;

	/** @var array<int,int>|null */
	private ?array $restrict_to_property_ids;

	public function __construct( ?array $restrict_to_property_ids = null ) {
		parent::__construct(
			array(
				'singular' => 'alert',
				'plural'   => 'alerts',
				'ajax'     => false,
			)
		);

		$this->alerts                   = new Alert();
		$this->properties               = new Property();
		$this->units                    = new Unit();
		$this->restrict_to_property_ids = $restrict_to_property_ids;
	}

	public function get_columns(): array {
		return array(
			'title'        => __( 'Title', 'chrx-rental-manager' ),
			'entity'       => __( 'Attached to', 'chrx-rental-manager' ),
			'schedule'     => __( 'Schedule', 'chrx-rental-manager' ),
			'channels'     => __( 'Channels', 'chrx-rental-manager' ),
			'last_sent_at' => __( 'Last sent', 'chrx-rental-manager' ),
			'status'       => __( 'Status', 'chrx-rental-manager' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$all_rows = array_filter(
			$this->alerts->all(),
			function ( array $alert ): bool {
				if ( null === $this->restrict_to_property_ids ) {
					return true;
				}

				if ( Alert::ENTITY_NONE === $alert['entity_type'] ) {
					return false;
				}

				$property_id = $this->property_id_for_entity( (string) $alert['entity_type'], null !== $alert['entity_id'] ? (int) $alert['entity_id'] : null );

				return null !== $property_id && in_array( $property_id, $this->restrict_to_property_ids, true );
			}
		);

		$this->items = array_values( $all_rows );

		$this->set_pagination_args(
			array(
				'total_items' => count( $this->items ),
				'per_page'    => count( $this->items ) > 0 ? count( $this->items ) : 1,
				'total_pages' => 1,
			)
		);
	}

	private function property_id_for_entity( string $entity_type, ?int $entity_id ): ?int {
		if ( null === $entity_id ) {
			return null;
		}

		if ( Alert::ENTITY_PROPERTY === $entity_type ) {
			return $entity_id;
		}

		if ( Alert::ENTITY_UNIT === $entity_type ) {
			$unit = $this->units->find( $entity_id );

			return null === $unit ? null : (int) $unit['property_id'];
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_title( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'   => AlertsController::page_slug(),
				'action' => 'edit',
				'id'     => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s" style="font-weight:600">%s</a>', esc_url( $edit_url ), esc_html( $item['title'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_entity( $item ): string {
		if ( Alert::ENTITY_PROPERTY === $item['entity_type'] ) {
			$property = null !== $item['entity_id'] ? $this->properties->find( (int) $item['entity_id'] ) : null;

			return esc_html( null === $property ? __( 'Property', 'chrx-rental-manager' ) : $property['name'] );
		}

		if ( Alert::ENTITY_UNIT === $item['entity_type'] ) {
			$unit = null !== $item['entity_id'] ? $this->units->find( (int) $item['entity_id'] ) : null;

			return esc_html( null === $unit ? __( 'Unit', 'chrx-rental-manager' ) : $unit['unit_label'] );
		}

		return esc_html__( 'Account-wide', 'chrx-rental-manager' );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_schedule( $item ): string {
		$labels = array(
			Alert::SCHEDULE_ONCE    => __( 'One-off', 'chrx-rental-manager' ),
			Alert::SCHEDULE_DAILY   => __( 'Daily', 'chrx-rental-manager' ),
			Alert::SCHEDULE_WEEKLY  => __( 'Weekly', 'chrx-rental-manager' ),
			Alert::SCHEDULE_MONTHLY => __( 'Monthly', 'chrx-rental-manager' ),
		);

		$label = $labels[ $item['schedule_type'] ] ?? (string) $item['schedule_type'];

		if ( empty( $item['scheduled_at'] ) ) {
			return esc_html( $label );
		}

		return esc_html( $label . ' · ' . gmdate( Alert::SCHEDULE_ONCE === $item['schedule_type'] ? 'd M Y H:i' : 'H:i', strtotime( $item['scheduled_at'] ) ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_channels( $item ): string {
		return esc_html( implode( ', ', (array) $item['channels'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_last_sent_at( $item ): string {
		if ( empty( $item['last_sent_at'] ) ) {
			return '<span style="color:#8c8f94">—</span>';
		}

		return esc_html( gmdate( 'd M Y H:i', strtotime( $item['last_sent_at'] ) ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_status( $item ): string {
		return $item['active'] ? Badge::render( 'active' ) : Badge::render( 'inactive', __( 'Inactive', 'chrx-rental-manager' ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
