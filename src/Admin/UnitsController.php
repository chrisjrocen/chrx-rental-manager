<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Units: list (designs/08), detail (designs/09), add/edit (designs/10).
 *
 * Unit status (SPEC.md §4.1): occupied/vacant is derived from active-lease
 * presence and kept in sync by Data\Lease/Data\Unit whenever a lease
 * starts/ends; this controller exposes the manual maintenance/reserved
 * override as an explicit status choice on the form, which Unit::update()
 * (via the plain update() call here, not sync_derived_status()) simply
 * persists as-is — an admin explicitly choosing "Occupied"/"Vacant" here
 * is treated the same as any other manual status set, since the derived
 * value is recalculated the next time a lease changes anyway.
 */
final class UnitsController {

	private const NONCE_ACTION = 'rm_unit_save';
	private const PAGE_SLUG    = 'chrx-rm-units';

	private Unit $units;
	private Property $properties;
	private Lease $leases;
	private Tenant $tenants;
	private Document $documents;
	private Access $access;

	public function __construct(
		?Unit $units = null,
		?Property $properties = null,
		?Lease $leases = null,
		?Tenant $tenants = null,
		?Document $documents = null,
		?Access $access = null
	) {
		$this->units      = $units ?? new Unit();
		$this->properties = $properties ?? new Property();
		$this->leases     = $leases ?? new Lease( $this->units );
		$this->tenants    = $tenants ?? new Tenant();
		$this->documents  = $documents ?? new Document();
		$this->access     = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
	}

	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's request this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_unit_submit'] ) ) {
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
		if ( ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) && ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) ) {
			wp_die( esc_html__( 'You do not have permission to view units.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'units' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$unit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			if ( ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) ) {
				wp_die( esc_html__( 'You do not have permission to manage units.', 'chrx-rental-manager' ), 403 );
			}

			$this->render_form( $action, $unit_id, $notice );

			return;
		}

		if ( 'archived' === $action ) {
			$this->render_archived( $notice );

			return;
		}

		if ( $unit_id > 0 ) {
			$this->render_detail( $unit_id, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table               = new UnitsListTable( $restrict_to_property_ids );
		$list_table->prepare_items();

		$properties = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_filter(
				$this->properties->all_active(),
				fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
			);

		$add_url    = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		$can_manage = current_user_can( RoleManager::CAP_MANAGE_UNITS );
		$is_empty   = 0 === $list_table->get_pagination_arg( 'total_items' );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/units-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_archived( ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage units.', 'chrx-rental-manager' ), 403 );
		}

		$archived               = $this->units->all_archived();
		$properties             = $this->properties;
		$list_url               = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$can_delete_permanently = $this->access->is_administrator( get_current_user_id() );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/units-archived.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_detail( int $unit_id, ?string $notice ): void {
		$unit = $this->units->find( $unit_id );

		if ( null === $unit || null !== $unit['deleted_at'] ) {
			wp_die( esc_html__( 'Unit not found.', 'chrx-rental-manager' ), 404 );
		}

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this unit.', 'chrx-rental-manager' ), 403 );
		}

		$property      = $this->properties->find( (int) $unit['property_id'] );
		$lease_history = $this->leases->for_unit( $unit_id );

		$lease_history = array_map(
			function ( array $lease ): array {
				$tenant               = $this->tenants->find( (int) $lease['tenant_id'] );
				$lease['tenant_name'] = null === $tenant ? '' : $tenant['full_name'];

				return $lease;
			},
			$lease_history
		);

		$documents  = $this->documents->for_entity( Document::ENTITY_UNIT, $unit_id );
		$can_manage = current_user_can( RoleManager::CAP_MANAGE_UNITS ) && $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/unit-detail.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, int $unit_id, ?string $notice ): void {
		$unit = null;

		if ( 'edit' === $action ) {
			$unit = $this->units->find( $unit_id );

			if ( null === $unit ) {
				wp_die( esc_html__( 'Unit not found.', 'chrx-rental-manager' ), 404 );
			}

			if ( ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this unit.', 'chrx-rental-manager' ), 403 );
			}
		}

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$properties               = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_filter(
				$this->properties->all_active(),
				fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
			);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pre-fill param, no state change.
		$preselected_property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;

		$list_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/unit-form.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_unit_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_POST['unit_id'] ) ? absint( $_POST['unit_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_POST['rm_property_id'] ) ? absint( $_POST['rm_property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_label = isset( $_POST['rm_unit_label'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_unit_label'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$bedrooms = isset( $_POST['rm_bedrooms'] ) ? absint( $_POST['rm_bedrooms'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$rent_amount = isset( $_POST['rm_rent_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_rent_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$status = isset( $_POST['rm_status'] ) ? sanitize_key( wp_unslash( $_POST['rm_status'] ) ) : Unit::STATUS_VACANT;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$notes = isset( $_POST['rm_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_notes'] ) ) : '';

		$back_to_form = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 0 === $unit_id ? 'add' : 'edit',
				'id'     => $unit_id,
			),
			admin_url( 'admin.php' )
		);

		$valid_statuses = array( Unit::STATUS_VACANT, Unit::STATUS_OCCUPIED, Unit::STATUS_MAINTENANCE, Unit::STATUS_RESERVED );

		if ( '' === $unit_label || 0 === $property_id || ! in_array( $status, $valid_statuses, true ) ) {
			FlashNotice::set( 'units', __( 'Please fill in the property and unit label.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage units on this property.', 'chrx-rental-manager' ), 403 );
		}

		$data = array(
			'property_id' => $property_id,
			'unit_label'  => $unit_label,
			'bedrooms'    => $bedrooms,
			'rent_amount' => $rent_amount,
			'status'      => $status,
			'notes'       => $notes,
		);

		if ( 0 === $unit_id ) {
			$unit_id = $this->units->insert( $data );
		} else {
			$this->units->update( $unit_id, $data );
		}

		FlashNotice::set( 'units', __( 'Unit saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'id'   => $unit_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_trash_action(): void {
		check_admin_referer( 'rm_unit_archive' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) ) {
			wp_die( esc_html__( 'You do not have permission to delete units.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$unit    = $this->units->find( $unit_id );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this unit.', 'chrx-rental-manager' ), 403 );
		}

		// SPEC.md §4.1: deleting a unit with lease history is blocked —
		// trash it instead so history stays queryable in reports.
		if ( Unit::STATUS_OCCUPIED === $unit['status'] || null !== $this->leases->active_lease_for_unit( $unit_id ) ) {
			FlashNotice::set( 'units', __( 'This unit has an active lease. End the lease before deleting the unit.', 'chrx-rental-manager' ) );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'id'   => $unit_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->units->soft_delete( $unit_id );

		FlashNotice::set( 'units', __( 'Unit moved to trash.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function handle_restore_action(): void {
		check_admin_referer( 'rm_unit_restore' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) ) {
			wp_die( esc_html__( 'You do not have permission to restore units.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->units->restore( $unit_id );

		FlashNotice::set( 'units', __( 'Unit restored.', 'chrx-rental-manager' ) );
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
		check_admin_referer( 'rm_unit_delete_permanently' );

		if ( ! $this->access->is_administrator( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to permanently delete units.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$unit    = $this->units->find( $unit_id );

		if ( null === $unit ) {
			wp_die( esc_html__( 'Unit not found.', 'chrx-rental-manager' ), 404 );
		}

		$archived_url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'archived',
			),
			admin_url( 'admin.php' )
		);

		if ( null === $unit['deleted_at'] ) {
			wp_die( esc_html__( 'This unit must be moved to trash before it can be permanently deleted.', 'chrx-rental-manager' ), 400 );
		}

		if ( $this->units->has_lease_history( $unit_id ) ) {
			FlashNotice::set( 'units', __( 'This unit has lease history and cannot be permanently deleted. It will remain in the trash.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $archived_url );
			exit;
		}

		$this->units->delete_permanently( $unit_id );

		FlashNotice::set( 'units', __( 'Unit permanently deleted.', 'chrx-rental-manager' ) );
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
