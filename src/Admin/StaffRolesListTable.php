<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists every WP user holding an Administrator, Staff, or Landlord-Owner
 * role, their role badge, and assigned properties (designs/25-staff-roles.html).
 * Tenants are excluded — they're managed via the "Invite to Portal" flow
 * on the (Phase 3) tenant screens, not here.
 */
final class StaffRolesListTable extends \WP_List_Table {

	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private Property $properties;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'staff_member',
				'plural'   => 'staff_members',
				'ajax'     => false,
			)
		);

		$this->property_staff     = new PropertyStaff();
		$this->property_landlords = new PropertyLandlord();
		$this->properties         = new Property();
	}

	public function get_columns(): array {
		return array(
			'user'       => __( 'User', 'chrx-rental-manager' ),
			'role'       => __( 'Role', 'chrx-rental-manager' ),
			'properties' => __( 'Assigned properties', 'chrx-rental-manager' ),
			'actions'    => '',
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$users = get_users(
			array(
				'role__in' => array( 'administrator', RoleManager::ROLE_STAFF, RoleManager::ROLE_LANDLORD_OWNER ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$this->items = array_map( array( $this, 'row_for_user' ), $users );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_for_user( \WP_User $user ): array {
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return array(
				'user_id'    => $user->ID,
				'name'       => $user->display_name,
				'role_key'   => 'administrator',
				'role_label' => __( 'Administrator', 'chrx-rental-manager' ),
				'properties' => __( 'All properties', 'chrx-rental-manager' ),
			);
		}

		if ( in_array( RoleManager::ROLE_STAFF, $user->roles, true ) ) {
			return array(
				'user_id'    => $user->ID,
				'name'       => $user->display_name,
				'role_key'   => RoleManager::ROLE_STAFF,
				'role_label' => __( 'Property Manager', 'chrx-rental-manager' ),
				'properties' => $this->property_names( $this->property_staff->property_ids_for_user( $user->ID ) ),
			);
		}

		return array(
			'user_id'    => $user->ID,
			'name'       => $user->display_name,
			'role_key'   => RoleManager::ROLE_LANDLORD_OWNER,
			'role_label' => __( 'Landlord-Owner', 'chrx-rental-manager' ),
			'properties' => $this->property_names( $this->property_landlords->property_ids_for_user( $user->ID ) ),
		);
	}

	/**
	 * @param array<int,int> $property_ids
	 */
	private function property_names( array $property_ids ): string {
		if ( array() === $property_ids ) {
			return __( 'None assigned', 'chrx-rental-manager' );
		}

		$names = array_map(
			function ( int $id ): string {
				$property = $this->properties->find( $id );

				return null === $property ? '' : $property['name'];
			},
			$property_ids
		);

		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_user( $item ): string {
		return esc_html( $item['name'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_role( $item ): string {
		$badge_class = 'chrx-rm-badge chrx-rm-badge--' . esc_attr( $item['role_key'] );

		return sprintf( '<span class="%s">%s</span>', $badge_class, esc_html( $item['role_label'] ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_properties( $item ): string {
		return esc_html( $item['properties'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_actions( $item ): string {
		if ( 'administrator' === $item['role_key'] ) {
			return '';
		}

		$edit_url = add_query_arg(
			array(
				'page'    => 'chrx-rm-staff-roles',
				'action'  => 'edit',
				'user_id' => $item['user_id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'chrx-rental-manager' ) );
	}
}
