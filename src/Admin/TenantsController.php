<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenants: list (designs/11), detail (designs/12), add/edit (designs/13).
 * "Invite to Portal" reuses TenantInviteController (Roles & Permissions
 * phase) rather than duplicating that logic.
 */
final class TenantsController {

	private const NONCE_ACTION = 'rm_tenant_save';
	private const PAGE_SLUG    = 'chrx-rm-tenants';

	private Tenant $tenants;
	private Lease $leases;
	private Unit $units;
	private Document $documents;
	private Access $access;
	private TenantInviteController $invite_controller;

	public function __construct(
		?Tenant $tenants = null,
		?Lease $leases = null,
		?Unit $units = null,
		?Document $documents = null,
		?Access $access = null,
		?TenantInviteController $invite_controller = null
	) {
		$this->tenants           = $tenants ?? new Tenant();
		$this->leases            = $leases ?? new Lease();
		$this->units             = $units ?? new Unit();
		$this->documents         = $documents ?? new Document();
		$this->access            = $access ?? new Access();
		$this->invite_controller = $invite_controller ?? new TenantInviteController();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
	}

	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's request this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_tenant_submit'] ) ) {
			$this->handle_save();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_archive_action()/handle_restore_action().
		if ( isset( $_GET['rm_action'] ) && 'archive' === $_GET['rm_action'] ) {
			$this->handle_archive_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_archive_action()/handle_restore_action().
		if ( isset( $_GET['rm_action'] ) && 'restore' === $_GET['rm_action'] ) {
			$this->handle_restore_action();
		}
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) && ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view tenants.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'tenants' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$tenant_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			if ( ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
				wp_die( esc_html__( 'You do not have permission to manage tenants.', 'chrx-rental-manager' ), 403 );
			}

			$this->render_form( $action, $tenant_id, $notice );

			return;
		}

		if ( 'archived' === $action ) {
			$this->render_archived( $notice );

			return;
		}

		if ( $tenant_id > 0 ) {
			$this->render_detail( $tenant_id, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table               = new TenantsListTable( $restrict_to_property_ids );
		$list_table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab param, no state change.
		$active_tab = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Tenant::STATUS_ACTIVE;

		$active_count = count( $this->tenants->search( '', Tenant::STATUS_ACTIVE, PHP_INT_MAX, 0 ) );
		$former_count = count( $this->tenants->search( '', Tenant::STATUS_FORMER, PHP_INT_MAX, 0 ) );

		$add_url    = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		$can_manage = current_user_can( RoleManager::CAP_MANAGE_TENANTS );
		$is_empty   = 0 === $list_table->get_pagination_arg( 'total_items' );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/tenants-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_archived( ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage tenants.', 'chrx-rental-manager' ), 403 );
		}

		$archived = $this->tenants->all_archived();
		$list_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/tenants-archived.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_detail( int $tenant_id, ?string $notice ): void {
		$tenant = $this->tenants->find( $tenant_id );

		if ( null === $tenant || null !== $tenant['deleted_at'] ) {
			wp_die( esc_html__( 'Tenant not found.', 'chrx-rental-manager' ), 404 );
		}

		if ( ! $this->tenant_visible_to_current_user( $tenant_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view this tenant.', 'chrx-rental-manager' ), 403 );
		}

		$leases = $this->leases->for_tenant( $tenant_id );
		$leases = array_map(
			function ( array $lease ): array {
				$unit                = $this->units->find( (int) $lease['unit_id'] );
				$lease['unit_label'] = null === $unit ? '' : $unit['unit_label'];

				return $lease;
			},
			$leases
		);

		$documents  = $this->documents->for_entity( Document::ENTITY_TENANT, $tenant_id );
		$can_manage = current_user_can( RoleManager::CAP_MANAGE_TENANTS ) && $this->tenant_visible_to_current_user( $tenant_id );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/tenant-detail.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, int $tenant_id, ?string $notice ): void {
		$tenant = null;

		if ( 'edit' === $action ) {
			$tenant = $this->tenants->find( $tenant_id );

			if ( null === $tenant ) {
				wp_die( esc_html__( 'Tenant not found.', 'chrx-rental-manager' ), 404 );
			}

			if ( ! $this->tenant_visible_to_current_user( $tenant_id ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this tenant.', 'chrx-rental-manager' ), 403 );
			}
		}

		$list_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/tenant-form.php';
	}

	private function tenant_visible_to_current_user( int $tenant_id ): bool {
		$user_id = get_current_user_id();

		if ( $this->access->is_administrator( $user_id ) ) {
			return true;
		}

		$leases = $this->leases->for_tenant( $tenant_id );

		if ( array() === $leases ) {
			return current_user_can( RoleManager::CAP_MANAGE_TENANTS ) || current_user_can( RoleManager::CAP_VIEW_DASHBOARD );
		}

		foreach ( $leases as $lease ) {
			$unit = $this->units->find( (int) $lease['unit_id'] );

			if ( null !== $unit && $this->access->userCanAccessProperty( $user_id, (int) $unit['property_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_tenant_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$tenant_id = isset( $_POST['tenant_id'] ) ? absint( $_POST['tenant_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$full_name = isset( $_POST['rm_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_full_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$phone = isset( $_POST['rm_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_phone'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$email = isset( $_POST['rm_email'] ) ? sanitize_email( wp_unslash( $_POST['rm_email'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$national_id = isset( $_POST['rm_national_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_national_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$send_invite = ! empty( $_POST['rm_send_invite'] );

		$back_to_form = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 0 === $tenant_id ? 'add' : 'edit',
				'id'     => $tenant_id,
			),
			admin_url( 'admin.php' )
		);

		if ( '' === $full_name ) {
			FlashNotice::set( 'tenants', __( 'Full name is required.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		if ( $tenant_id > 0 && ! $this->tenant_visible_to_current_user( $tenant_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this tenant.', 'chrx-rental-manager' ), 403 );
		}

		$data = array(
			'full_name'   => $full_name,
			'phone'       => $phone,
			'email'       => $email,
			'national_id' => $national_id,
		);

		$is_new = 0 === $tenant_id;

		if ( $is_new ) {
			$data['status'] = Tenant::STATUS_ACTIVE;
			$tenant_id      = $this->tenants->insert( $data );
		} else {
			$this->tenants->update( $tenant_id, $data );
		}

		if ( $is_new && $send_invite && '' !== $email ) {
			$this->invite_controller->invite( $tenant_id, get_current_user_id() );
		}

		FlashNotice::set( 'tenants', __( 'Tenant saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'id'   => $tenant_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_archive_action(): void {
		check_admin_referer( 'rm_tenant_archive' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
			wp_die( esc_html__( 'You do not have permission to archive tenants.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$tenant_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $this->tenant_visible_to_current_user( $tenant_id ) ) {
			wp_die( esc_html__( 'You do not have permission to archive this tenant.', 'chrx-rental-manager' ), 403 );
		}

		$active_lease = null;
		foreach ( $this->leases->for_tenant( $tenant_id ) as $lease ) {
			if ( Lease::STATUS_ACTIVE === $lease['status'] ) {
				$active_lease = $lease;
				break;
			}
		}

		if ( null !== $active_lease ) {
			FlashNotice::set( 'tenants', __( 'This tenant has an active lease. End the lease before archiving the tenant.', 'chrx-rental-manager' ) );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'id'   => $tenant_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->tenants->soft_delete( $tenant_id );
		$this->tenants->update( $tenant_id, array( 'status' => Tenant::STATUS_FORMER ) );

		FlashNotice::set( 'tenants', __( 'Tenant archived.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function handle_restore_action(): void {
		check_admin_referer( 'rm_tenant_restore' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) ) {
			wp_die( esc_html__( 'You do not have permission to restore tenants.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$tenant_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->tenants->restore( $tenant_id );

		FlashNotice::set( 'tenants', __( 'Tenant restored.', 'chrx-rental-manager' ) );
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

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
