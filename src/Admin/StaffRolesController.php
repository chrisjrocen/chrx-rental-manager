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

/**
 * Staff & Roles screen (designs/25-staff-roles.html): assign Staff/
 * Landlord-Owner WP users to specific properties via
 * rm_property_staff/rm_property_landlords (SPEC.md §2's "Design
 * decision") — through PropertyStaff/PropertyLandlord, never a
 * blanket account-wide permission.
 */
final class StaffRolesController {

	private const NONCE_ACTION = 'rm_staff_roles_save';

	private PropertyStaff $property_staff;
	private PropertyLandlord $property_landlords;
	private Property $properties;

	public function __construct(
		?PropertyStaff $property_staff = null,
		?PropertyLandlord $property_landlords = null,
		?Property $properties = null
	) {
		$this->property_staff     = $property_staff ?? new PropertyStaff();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
		$this->properties         = $properties ?? new Property();
	}

	/**
	 * Processing the save on `admin_init` (rather than inline in the
	 * add_menu_page callback that render() serves) matters: by the time a
	 * menu page callback runs, wp-admin has already sent HTTP headers and
	 * started echoing the page shell, so wp_safe_redirect() inside it
	 * reliably fails with "headers already sent". admin_init runs before
	 * any of that output.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_save' ) );
	}

	public function maybe_handle_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( ! isset( $_POST['rm_staff_roles_submit'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's POST this is.
		if ( ! isset( $_GET['page'] ) || 'chrx-rm-staff-roles' !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_STAFF ) ) {
			wp_die( esc_html__( 'You do not have permission to manage staff & roles.', 'chrx-rental-manager' ), 403 );
		}

		$this->handle_save();
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_STAFF ) ) {
			wp_die( esc_html__( 'You do not have permission to manage staff & roles.', 'chrx-rental-manager' ), 403 );
		}

		$notice = $this->take_flash_notice();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_form( $action, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	private function flash_notice( string $message ): void {
		set_transient( 'rm_staff_roles_notice_' . get_current_user_id(), $message, 60 );
	}

	private function take_flash_notice(): ?string {
		$key    = 'rm_staff_roles_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( false === $notice ) {
			return null;
		}

		delete_transient( $key );

		return (string) $notice;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$list_table = new StaffRolesListTable();
		$list_table->prepare_items();

		$add_url = add_query_arg(
			array(
				'page'   => 'chrx-rm-staff-roles',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/staff-roles-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, ?string $notice ): void {
		$user_id = 0;
		$user    = null;

		if ( 'edit' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
			$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
			$user    = get_userdata( $user_id );

			if ( false === $user ) {
				wp_die( esc_html__( 'User not found.', 'chrx-rental-manager' ), 404 );
			}
		}

		$current_role = null;
		$assigned_ids = array();

		if ( null !== $user ) {
			if ( in_array( RoleManager::ROLE_STAFF, $user->roles, true ) ) {
				$current_role = RoleManager::ROLE_STAFF;
				$assigned_ids = $this->property_staff->property_ids_for_user( $user_id );
			} elseif ( in_array( RoleManager::ROLE_LANDLORD_OWNER, $user->roles, true ) ) {
				$current_role = RoleManager::ROLE_LANDLORD_OWNER;
				$assigned_ids = $this->property_landlords->property_ids_for_user( $user_id );
			}
		}

		$all_properties = $this->properties->all_active();
		$list_url       = add_query_arg( 'page', 'chrx-rm-staff-roles', admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/staff-roles-form.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_staff_roles_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$role = isset( $_POST['rm_role'] ) ? sanitize_key( wp_unslash( $_POST['rm_role'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_ids = isset( $_POST['rm_property_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rm_property_ids'] ) ) : array();

		$back_to_form = add_query_arg(
			array(
				'page'    => 'chrx-rm-staff-roles',
				'action'  => 0 === $user_id ? 'add' : 'edit',
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		);

		if ( ! in_array( $role, array( RoleManager::ROLE_STAFF, RoleManager::ROLE_LANDLORD_OWNER ), true ) ) {
			$this->flash_notice( __( 'Please choose a valid role.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		if ( 0 === $user_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
			$name = isset( $_POST['rm_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_display_name'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
			$email = isset( $_POST['rm_email'] ) ? sanitize_email( wp_unslash( $_POST['rm_email'] ) ) : '';

			$created_user_id = $this->create_user( $name, $email, $role );

			if ( is_wp_error( $created_user_id ) ) {
				$this->flash_notice( $created_user_id->get_error_message() );
				wp_safe_redirect( $back_to_form );
				exit;
			}

			$user_id = $created_user_id;
		} else {
			$this->set_role( $user_id, $role );
		}

		$this->sync_property_assignments( $user_id, $role, $property_ids );

		wp_safe_redirect( add_query_arg( 'page', 'chrx-rm-staff-roles', admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return int|\WP_Error
	 */
	private function create_user( string $name, string $email, string $role ) {
		if ( '' === $name || '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'rm_invalid_input', __( 'Please provide a name and a valid email address.', 'chrx-rental-manager' ) );
		}

		if ( email_exists( $email ) ) {
			return new \WP_Error( 'rm_email_exists', __( 'A user with that email already exists.', 'chrx-rental-manager' ) );
		}

		$username = sanitize_user( current( explode( '@', $email ) ), true );
		$username = '' !== $username ? $username : 'user';
		$suffix   = 1;
		$base     = $username;

		while ( username_exists( $username ) ) {
			++$suffix;
			$username = $base . $suffix;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $name,
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// WP core's own "new user" email — includes a native set-password
		// link, exactly what a Staff/Landlord-Owner needs for first login
		// (only Tenants get the custom-branded portal-activation flow).
		wp_new_user_notification( $user_id, null, 'user' );

		return $user_id;
	}

	private function set_role( int $user_id, string $role ): void {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return;
		}

		foreach ( array( RoleManager::ROLE_STAFF, RoleManager::ROLE_LANDLORD_OWNER ) as $plugin_role ) {
			if ( in_array( $plugin_role, $user->roles, true ) && $plugin_role !== $role ) {
				$user->remove_role( $plugin_role );
			}
		}

		if ( ! in_array( $role, $user->roles, true ) ) {
			$user->add_role( $role );
		}
	}

	/**
	 * @param array<int,int> $property_ids
	 */
	private function sync_property_assignments( int $user_id, string $role, array $property_ids ): void {
		$repository = RoleManager::ROLE_STAFF === $role ? $this->property_staff : $this->property_landlords;
		$other      = RoleManager::ROLE_STAFF === $role ? $this->property_landlords : $this->property_staff;

		// A user can only hold one of these two scoped roles at a time in
		// this screen, so clear any assignments left over from the other
		// role (e.g. switching a user from Landlord-Owner to Staff).
		foreach ( $other->property_ids_for_user( $user_id ) as $property_id ) {
			$other->unassign( $property_id, $user_id );
		}

		$current = $repository->property_ids_for_user( $user_id );

		foreach ( array_diff( $current, $property_ids ) as $property_id ) {
			$repository->unassign( $property_id, $user_id );
		}

		foreach ( array_diff( $property_ids, $current ) as $property_id ) {
			$repository->assign( $property_id, $user_id );
		}
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
