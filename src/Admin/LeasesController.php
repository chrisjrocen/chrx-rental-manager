<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\DuplicateActiveLeaseException;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leases: list (designs/14), detail with charge ledger (designs/15),
 * add/edit (designs/16).
 *
 * Charge generation deviation from the design: designs/16's copy implies
 * "Create Lease" bulk-generates all of the term's monthly charges
 * immediately. SPEC.md §4.2 is explicit that charges are generated
 * incrementally by the daily rm_generate_monthly_charges cron job, a
 * configurable number of days before each billing_day — not in bulk at
 * creation. This controller follows SPEC.md: creating a lease only
 * inserts the lease row (the cron job, built in the Billing phase,
 * creates each period's charge as it approaches).
 */
final class LeasesController {

	private const NONCE_ACTION = 'rm_lease_save';
	private const PAGE_SLUG    = 'chrx-rm-leases';

	private Lease $leases;
	private Unit $units;
	private Tenant $tenants;
	private Property $properties;
	private Charge $charges;
	private Payment $payments;
	private Document $documents;
	private Ledger $ledger;
	private Access $access;
	private LeaseRenewalController $renewal_controller;
	private LeaseMoveOutController $move_out_controller;
	private RecordPaymentController $record_payment_controller;

	public function __construct(
		?Lease $leases = null,
		?Unit $units = null,
		?Tenant $tenants = null,
		?Property $properties = null,
		?Charge $charges = null,
		?Payment $payments = null,
		?Document $documents = null,
		?Ledger $ledger = null,
		?Access $access = null,
		?LeaseRenewalController $renewal_controller = null,
		?LeaseMoveOutController $move_out_controller = null,
		?RecordPaymentController $record_payment_controller = null
	) {
		$this->units      = $units ?? new Unit();
		$this->leases     = $leases ?? new Lease( $this->units );
		$this->tenants    = $tenants ?? new Tenant();
		$this->properties = $properties ?? new Property();
		$this->charges    = $charges ?? new Charge();
		$this->payments   = $payments ?? new Payment();
		$this->documents  = $documents ?? new Document();
		$this->ledger     = $ledger ?? new Ledger( $this->charges, $this->payments, $this->leases );
		$this->access     = $access ?? new Access();

		$this->renewal_controller        = $renewal_controller ?? new LeaseRenewalController( $this->leases, $this->units, $this->tenants, $this->access );
		$this->move_out_controller       = $move_out_controller ?? new LeaseMoveOutController( $this->leases, $this->units, $this->tenants, $this->charges, $this->ledger, $this->access );
		$this->record_payment_controller = $record_payment_controller ?? new RecordPaymentController( $this->leases, $this->units, $this->tenants, $this->charges, $this->payments, $this->ledger, $this->access );
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
		$this->renewal_controller->register();
		$this->move_out_controller->register();
		$this->record_payment_controller->register();
	}

	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's request this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_lease_submit'] ) ) {
			$this->handle_save();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action()/handle_waive_charge_action().
		if ( isset( $_GET['rm_action'] ) && 'archive' === $_GET['rm_action'] ) {
			$this->handle_trash_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action()/handle_waive_charge_action().
		if ( isset( $_GET['rm_action'] ) && 'restore' === $_GET['rm_action'] ) {
			$this->handle_restore_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action()/handle_waive_charge_action().
		if ( isset( $_GET['rm_action'] ) && 'delete_permanently' === $_GET['rm_action'] ) {
			$this->handle_delete_permanently_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_trash_action()/handle_restore_action()/handle_delete_permanently_action()/handle_waive_charge_action().
		if ( isset( $_GET['rm_action'] ) && 'waive_charge' === $_GET['rm_action'] ) {
			$this->handle_waive_charge_action();
		}
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_VIEW_DASHBOARD ) && ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to view leases.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'leases' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$lease_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
				wp_die( esc_html__( 'You do not have permission to manage leases.', 'chrx-rental-manager' ), 403 );
			}

			$this->render_form( $action, $lease_id, $notice );

			return;
		}

		if ( 'renew' === $action ) {
			$this->renewal_controller->render_form( $lease_id, $notice );

			return;
		}

		if ( 'move-out' === $action ) {
			$this->move_out_controller->render_form( $lease_id, $notice );

			return;
		}

		if ( 'record-payment' === $action ) {
			$this->record_payment_controller->render_form( $lease_id, $notice );

			return;
		}

		if ( 'archived' === $action ) {
			$this->render_archived( $notice );

			return;
		}

		if ( $lease_id > 0 ) {
			$this->render_detail( $lease_id, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table               = new LeasesListTable( $restrict_to_property_ids );
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
		$can_manage = current_user_can( RoleManager::CAP_MANAGE_LEASES );
		$is_empty   = 0 === $list_table->get_pagination_arg( 'total_items' );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/leases-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_archived( ?string $notice ): void {
		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to manage leases.', 'chrx-rental-manager' ), 403 );
		}

		$archived               = $this->leases->all_archived();
		$units                  = $this->units;
		$tenants                = $this->tenants;
		$list_url               = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$can_delete_permanently = $this->access->is_administrator( get_current_user_id() );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/leases-archived.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_detail( int $lease_id, ?string $notice ): void {
		$lease = $this->leases->find( $lease_id );

		if ( null === $lease || null !== $lease['deleted_at'] ) {
			wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
		}

		$unit     = $this->units->find( (int) $lease['unit_id'] );
		$tenant   = $this->tenants->find( (int) $lease['tenant_id'] );
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this lease.', 'chrx-rental-manager' ), 403 );
		}

		$charges = $this->charges->for_lease( $lease_id );
		$charges = array_map(
			function ( array $charge ): array {
				$paid = 0.0;
				foreach ( $this->payments->for_charge( (int) $charge['id'] ) as $payment ) {
					$paid += (float) $payment['amount'];
				}
				$charge['paid'] = $paid;

				return $charge;
			},
			$charges
		);

		$paid_to_date = $this->ledger->paid_to_date_for_lease( $lease_id );
		$balance      = $this->ledger->outstanding_balance_for_lease( $lease_id );

		$documents  = $this->documents->for_entity( Document::ENTITY_LEASE, $lease_id );
		$can_manage = current_user_can( RoleManager::CAP_MANAGE_LEASES ) && $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/lease-detail.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, int $lease_id, ?string $notice ): void {
		$lease = null;

		if ( 'edit' === $action ) {
			$lease = $this->leases->find( $lease_id );

			if ( null === $lease ) {
				wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
			}

			$unit = $this->units->find( (int) $lease['unit_id'] );

			if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this lease.', 'chrx-rental-manager' ), 403 );
			}

			$tenant               = $this->tenants->find( (int) $lease['tenant_id'] );
			$lease['unit_label']  = $unit['unit_label'];
			$lease['tenant_name'] = null === $tenant ? '' : $tenant['full_name'];
		}

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );

		// Vacant units only for a new lease (an occupied unit already has
		// an active lease — SPEC.md §4.1's no-double-active-lease rule).
		$vacant_units = array_filter(
			$this->units->all_active(),
			function ( array $u ) use ( $restrict_to_property_ids ): bool {
				if ( Unit::STATUS_OCCUPIED === $u['status'] ) {
					return false;
				}

				return null === $restrict_to_property_ids || in_array( (int) $u['property_id'], $restrict_to_property_ids, true );
			}
		);

		$tenants  = $this->tenants->search( '', Tenant::STATUS_ACTIVE, PHP_INT_MAX, 0 );
		$list_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/lease-form.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_lease_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_POST['lease_id'] ) ? absint( $_POST['lease_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_POST['rm_unit_id'] ) ? absint( $_POST['rm_unit_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$tenant_id = isset( $_POST['rm_tenant_id'] ) ? absint( $_POST['rm_tenant_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$rent_amount = isset( $_POST['rm_rent_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_rent_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$deposit_amount = isset( $_POST['rm_deposit_amount'] ) ? (float) str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['rm_deposit_amount'] ) ) ) : 0.0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$billing_day = isset( $_POST['rm_billing_day'] ) ? absint( $_POST['rm_billing_day'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$start_date = isset( $_POST['rm_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$end_date = isset( $_POST['rm_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_end_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$deposit_collected = ! empty( $_POST['rm_deposit_collected'] );

		$back_to_form = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 0 === $lease_id ? 'add' : 'edit',
				'id'     => $lease_id,
			),
			admin_url( 'admin.php' )
		);

		$valid_dates = false !== strtotime( $start_date ) && false !== strtotime( $end_date ) && strtotime( $end_date ) > strtotime( $start_date );

		if ( 0 === $unit_id || 0 === $tenant_id || $rent_amount <= 0 || ! $valid_dates ) {
			FlashNotice::set( 'leases', __( 'Please fill in unit, tenant, rent, and a valid date range.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $this->property_id_for_unit( $unit_id ) ) ) {
			wp_die( esc_html__( 'You do not have permission to manage leases on this property.', 'chrx-rental-manager' ), 403 );
		}

		$data = array(
			'unit_id'        => $unit_id,
			'tenant_id'      => $tenant_id,
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'rent_amount'    => $rent_amount,
			'billing_day'    => min( 28, max( 1, $billing_day ) ),
			'deposit_amount' => $deposit_amount,
			'deposit_status' => $deposit_collected ? 'paid' : 'unpaid',
		);

		try {
			if ( 0 === $lease_id ) {
				$lease_id = $this->leases->create( $data );
			} else {
				$this->leases->update( $lease_id, $data );
			}
		} catch ( DuplicateActiveLeaseException $e ) {
			FlashNotice::set(
				'leases',
				sprintf(
					/* translators: %d: conflicting lease id */
					__( 'This unit already has an active lease (#%d). End that lease before creating a new one.', 'chrx-rental-manager' ),
					$e->conflicting_lease_id
				)
			);
			wp_safe_redirect( $back_to_form );
			exit;
		}

		FlashNotice::set( 'leases', __( 'Lease saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'id'   => $lease_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function property_id_for_unit( int $unit_id ): int {
		$unit = $this->units->find( $unit_id );

		return null === $unit ? 0 : (int) $unit['property_id'];
	}

	private function handle_trash_action(): void {
		check_admin_referer( 'rm_lease_archive' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to delete leases.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$lease    = $this->leases->find( $lease_id );

		if ( null === $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
		}

		$unit = $this->units->find( (int) $lease['unit_id'] );

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this lease.', 'chrx-rental-manager' ), 403 );
		}

		$this->leases->soft_delete( $lease_id );

		FlashNotice::set( 'leases', __( 'Lease moved to trash.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function handle_restore_action(): void {
		check_admin_referer( 'rm_lease_restore' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to restore leases.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->leases->restore( $lease_id );

		FlashNotice::set( 'leases', __( 'Lease restored.', 'chrx-rental-manager' ) );
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
		check_admin_referer( 'rm_lease_delete_permanently' );

		if ( ! $this->access->is_administrator( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to permanently delete leases.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$lease_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$lease    = $this->leases->find( $lease_id );

		if ( null === $lease ) {
			wp_die( esc_html__( 'Lease not found.', 'chrx-rental-manager' ), 404 );
		}

		$archived_url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'archived',
			),
			admin_url( 'admin.php' )
		);

		if ( null === $lease['deleted_at'] ) {
			wp_die( esc_html__( 'This lease must be moved to trash before it can be permanently deleted.', 'chrx-rental-manager' ), 400 );
		}

		if ( $this->leases->has_financial_history( $lease_id ) ) {
			FlashNotice::set( 'leases', __( 'This lease has charge or payment history and cannot be permanently deleted. It will remain in the trash.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $archived_url );
			exit;
		}

		$this->leases->delete_permanently( $lease_id );

		FlashNotice::set( 'leases', __( 'Lease permanently deleted.', 'chrx-rental-manager' ) );
		wp_safe_redirect( $archived_url );
		exit;
	}

	private function handle_waive_charge_action(): void {
		check_admin_referer( 'rm_charge_waive' );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) ) {
			wp_die( esc_html__( 'You do not have permission to waive charges.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$charge_id = isset( $_GET['charge_id'] ) ? absint( $_GET['charge_id'] ) : 0;
		$charge    = $this->charges->find( $charge_id );

		if ( null === $charge ) {
			wp_die( esc_html__( 'Charge not found.', 'chrx-rental-manager' ), 404 );
		}

		$lease = $this->leases->find( (int) $charge['lease_id'] );
		$unit  = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;

		if ( null === $unit || ! $this->access->userCanAccessProperty( get_current_user_id(), (int) $unit['property_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to waive this charge.', 'chrx-rental-manager' ), 403 );
		}

		// SPEC.md §4.3: "Staff can waive/delete a late fee charge manually" —
		// scope this action to late-fee charges only.
		if ( Charge::TYPE_LATE_FEE !== $charge['type'] ) {
			wp_die( esc_html__( 'Only late fee charges can be waived.', 'chrx-rental-manager' ), 400 );
		}

		$this->charges->mark_waived( $charge_id );

		FlashNotice::set( 'leases', __( 'Late fee waived.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'id'   => $lease['id'],
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
