<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Data\PropertyStaff;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Properties: list (designs/05), detail (designs/06), add/edit
 * (designs/07). Staff are scoped to their assigned properties (SPEC.md
 * §4.1/§2) via Access::accessiblePropertyIds()/userCanAccessProperty() —
 * every method here checks it server-side, never relying on the menu
 * simply not showing a link.
 */
final class PropertiesController {

	private const NONCE_ACTION = 'rm_property_save';
	private const PAGE_SLUG    = 'chrx-rm-properties';

	private Property $properties;
	private Unit $units;
	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private Access $access;

	public function __construct(
		?Property $properties = null,
		?Unit $units = null,
		?PropertyStaff $property_staff = null,
		?PropertyLandlord $property_landlords = null,
		?Access $access = null
	) {
		$this->properties         = $properties ?? new Property();
		$this->units              = $units ?? new Unit();
		$this->property_staff     = $property_staff ?? new PropertyStaff();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
		$this->access             = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
	}

	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's request this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_property_submit'] ) ) {
			$this->handle_save();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action().
		if ( isset( $_GET['rm_action'] ) && 'archive' === $_GET['rm_action'] ) {
			$this->handle_trash_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action().
		if ( isset( $_GET['rm_action'] ) && 'restore' === $_GET['rm_action'] ) {
			$this->handle_restore_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action().
		if ( isset( $_GET['rm_action'] ) && 'delete_permanently' === $_GET['rm_action'] ) {
			$this->handle_delete_permanently_action();
		}
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) && ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to view properties.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'properties' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$property_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
				wp_die( esc_html__( 'You do not have permission to manage properties.', 'chrx-rental-manager' ), 403 );
			}

			$this->render_form( $action, $property_id, $notice );

			return;
		}

		if ( 'archived' === $action ) {
			$this->render_archived( $notice );

			return;
		}

		if ( $property_id > 0 ) {
			$this->render_detail( $property_id, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table      = new PropertiesListTable( $restrict_to_ids );
		$list_table->prepare_items();

		$add_url      = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		$archived_url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'archived',
			),
			admin_url( 'admin.php' )
		);
		$can_manage   = current_user_can( RoleManager::CAP_MANAGE_PROPERTIES );
		$is_empty     = 0 === $list_table->get_pagination_arg( 'total_items' );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/properties-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_archived( ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage properties.', 'chrx-rental-manager' ), 403 );
		}

		$archived               = $this->properties->all_archived();
		$list_url               = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$can_delete_permanently = $this->access->is_administrator( get_current_user_id() );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/properties-archived.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_detail( int $property_id, ?string $notice ): void {
		$property = $this->properties->find( $property_id );

		if ( null === $property || null !== $property['deleted_at'] ) {
			wp_die( esc_html__( 'Property not found.', 'chrx-rental-manager' ), 404 );
		}

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view this property.', 'chrx-rental-manager' ), 403 );
		}

		$units          = $this->units->for_property( $property_id );
		$occupied_count = count( array_filter( $units, fn( array $u ): bool => Unit::STATUS_OCCUPIED === $u['status'] ) );
		$vacant_count   = count( $units ) - $occupied_count;

		$landlord_ids = $this->property_landlords->user_ids_for_property( $property_id );
		$staff_ids    = $this->property_staff->user_ids_for_property( $property_id );

		$can_manage = current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) && $this->access->userCanAccessProperty( get_current_user_id(), $property_id );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/property-detail.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, int $property_id, ?string $notice ): void {
		$property = null;

		if ( 'edit' === $action ) {
			$property = $this->properties->find( $property_id );

			if ( null === $property ) {
				wp_die( esc_html__( 'Property not found.', 'chrx-rental-manager' ), 404 );
			}

			if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this property.', 'chrx-rental-manager' ), 403 );
			}
		}

		$landlord_ids = null !== $property ? $this->property_landlords->user_ids_for_property( $property_id ) : array();
		$staff_ids    = null !== $property ? $this->property_staff->user_ids_for_property( $property_id ) : array();

		$landlord_users = get_users( array( 'role' => RoleManager::ROLE_LANDLORD_OWNER ) );
		$staff_users    = get_users( array( 'role' => RoleManager::ROLE_STAFF ) );

		$list_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/property-form.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_property_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_POST['property_id'] ) ? absint( $_POST['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$name = isset( $_POST['rm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$address = isset( $_POST['rm_address'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_address'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$city = isset( $_POST['rm_city'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_city'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notes = isset( $_POST['rm_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_notes'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$landlord_id = isset( $_POST['rm_landlord_id'] ) ? absint( $_POST['rm_landlord_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$staff_ids = isset( $_POST['rm_staff_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rm_staff_ids'] ) ) : array();
		// v2 (SPEC.md §4.10): blank means "use the account-wide default" —
		// stored as NULL, not 0, so Settings::notice_period_months_for_property()'s
		// null-check can tell "no override" apart from a (nonsensical) zero.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notice_period_months_raw = isset( $_POST['rm_notice_period_months'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_notice_period_months'] ) ) : '';
		$notice_period_months     = '' === $notice_period_months_raw ? null : max( 1, absint( $notice_period_months_raw ) );

		$back_to_form = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 0 === $property_id ? 'add' : 'edit',
				'id'     => $property_id,
			),
			admin_url( 'admin.php' )
		);

		if ( '' === $name || '' === $address ) {
			FlashNotice::set( 'properties', __( 'Property name and address are required.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		if ( $property_id > 0 && ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this property.', 'chrx-rental-manager' ), 403 );
		}

		$data = array(
			'name'                 => $name,
			'address'              => $address,
			'city'                 => $city,
			'notes'                => $notes,
			'notice_period_months' => $notice_period_months,
		);

		if ( 0 === $property_id ) {
			$property_id = $this->properties->insert( $data );
		} else {
			$this->properties->update( $property_id, $data );
		}

		$this->sync_landlord( $property_id, $landlord_id );
		$this->sync_staff( $property_id, $staff_ids );

		FlashNotice::set( 'properties', __( 'Property saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'id'   => $property_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function sync_landlord( int $property_id, int $landlord_id ): void {
		foreach ( $this->property_landlords->user_ids_for_property( $property_id ) as $existing_id ) {
			if ( $existing_id !== $landlord_id ) {
				$this->property_landlords->unassign( $property_id, $existing_id );
			}
		}

		if ( $landlord_id > 0 ) {
			$this->property_landlords->assign( $property_id, $landlord_id );
		}
	}

	/**
	 * @param array<int,int> $staff_ids
	 */
	private function sync_staff( int $property_id, array $staff_ids ): void {
		$current = $this->property_staff->user_ids_for_property( $property_id );

		foreach ( array_diff( $current, $staff_ids ) as $existing_id ) {
			$this->property_staff->unassign( $property_id, $existing_id );
		}

		foreach ( array_diff( $staff_ids, $current ) as $new_id ) {
			$this->property_staff->assign( $property_id, $new_id );
		}
	}

	private function handle_trash_action(): void {
		check_admin_referer( 'rm_property_archive' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to delete properties.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this property.', 'chrx-rental-manager' ), 403 );
		}

		// SPEC.md §4.1: a property with units that have lease history is
		// blocked from deletion — trashed instead, which is exactly what
		// soft_delete() already does; units-with-history stay queryable.
		if ( $this->properties->has_units( $property_id ) ) {
			FlashNotice::set( 'properties', __( 'This property still has units. Delete or reassign its units first.', 'chrx-rental-manager' ) );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'id'   => $property_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->properties->soft_delete( $property_id );

		FlashNotice::set( 'properties', __( 'Property moved to trash.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function handle_restore_action(): void {
		check_admin_referer( 'rm_property_restore' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_PROPERTIES ) ) {
			wp_die( esc_html__( 'You do not have permission to restore properties.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->properties->restore( $property_id );

		FlashNotice::set( 'properties', __( 'Property restored.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'archived',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_delete_permanently_action(): void {
		check_admin_referer( 'rm_property_delete_permanently' );

		if ( ! $this->access->is_administrator( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to permanently delete properties.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$property    = $this->properties->find( $property_id );

		if ( null === $property ) {
			wp_die( esc_html__( 'Property not found.', 'chrx-rental-manager' ), 404 );
		}

		$archived_url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'archived',
			),
			admin_url( 'admin.php' )
		);

		if ( null === $property['deleted_at'] ) {
			wp_die( esc_html__( 'This property must be moved to trash before it can be permanently deleted.', 'chrx-rental-manager' ), 400 );
		}

		if ( $this->properties->has_any_units( $property_id ) ) {
			FlashNotice::set( 'properties', __( 'This property still has units (including trashed ones) and cannot be permanently deleted. It will remain in the trash.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $archived_url );
			exit;
		}

		$this->properties->delete_permanently( $property_id );

		FlashNotice::set( 'properties', __( 'Property permanently deleted.', 'chrx-rental-manager' ) );
		wp_safe_redirect( $archived_url );
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
