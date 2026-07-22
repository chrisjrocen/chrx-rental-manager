<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Admin\Support\FlashNotice;
use ChrxRentalManager\Data\Alert;
use ChrxRentalManager\Data\NotificationLog;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Alerts (SPEC.md §4.8) — the one screen two different capabilities
 * can reach: CAP_MANAGE_ALERTS (Staff/Admin, all their assigned
 * properties) or CAP_MANAGE_OWN_ALERTS (Landlord-Owner, their own
 * properties only — SPEC.md §2's "single write capability"). Every gate
 * in this controller checks both explicitly rather than picking one
 * capability for `add_submenu_page()`'s single-capability slot (Menu.php
 * uses CAP_VIEW_DASHBOARD there, which both roles already hold, and
 * relies entirely on this controller's own can_manage()/Access checks —
 * the same "capability is the *what*, Access is the *which properties*"
 * split Access::userCanAccessProperty()'s own docblock describes).
 *
 * Account-level alerts (entity_type = none) are Administrator-only,
 * mirroring Expense::SCOPE_ACCOUNT's "Staff/landlord capability is
 * always tied to a property" precedent from V2-4.
 */
final class AlertsController {

	private const NONCE_ACTION = 'rm_alert_save';
	private const PAGE_SLUG    = 'chrx-rm-alerts';

	private Alert $alerts;
	private Property $properties;
	private Unit $units;
	private NotificationLog $notifications;
	private Access $access;

	public function __construct(
		?Alert $alerts = null,
		?Property $properties = null,
		?Unit $units = null,
		?NotificationLog $notifications = null,
		?Access $access = null
	) {
		$this->alerts        = $alerts ?? new Alert();
		$this->properties    = $properties ?? new Property();
		$this->units         = $units ?? new Unit();
		$this->notifications = $notifications ?? new NotificationLog();
		$this->access        = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
	}

	private function can_manage(): bool {
		return current_user_can( RoleManager::CAP_MANAGE_ALERTS ) || current_user_can( RoleManager::CAP_MANAGE_OWN_ALERTS );
	}

	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, only used to gate which screen's request this is.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! $this->can_manage() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside handle_save() via check_admin_referer() before any state change.
		if ( isset( $_POST['rm_alert_submit'] ) ) {
			$this->handle_save();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_delete_action()/handle_toggle_action().
		if ( isset( $_GET['rm_action'] ) && 'delete' === $_GET['rm_action'] ) {
			$this->handle_delete_action();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside handle_delete_action()/handle_toggle_action().
		if ( isset( $_GET['rm_action'] ) && 'toggle' === $_GET['rm_action'] ) {
			$this->handle_toggle_action();
		}
	}

	public function render(): void {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage alerts.', 'chrx-rental-manager' ), 403 );
		}

		$notice = FlashNotice::take( 'alerts' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$alert_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_form( $action, $alert_id, $notice );

			return;
		}

		$this->render_list( $notice );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $notice is used by the included template, which shares this method's local scope.
	private function render_list( ?string $notice ): void {
		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$list_table               = new AlertsListTable( $restrict_to_property_ids );
		$list_table->prepare_items();

		$add_url  = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		$is_empty = array() === $list_table->items;

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/alerts-list.php';
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $notice is used by the included template, which shares this method's local scope.
	private function render_form( string $action, int $alert_id, ?string $notice ): void {
		$alert = null;

		if ( 'edit' === $action ) {
			$alert = $this->alerts->find( $alert_id );

			if ( null === $alert ) {
				wp_die( esc_html__( 'Alert not found.', 'chrx-rental-manager' ), 404 );
			}

			$this->authorize_entity( (string) $alert['entity_type'], null !== $alert['entity_id'] ? (int) $alert['entity_id'] : null );
		}

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( get_current_user_id() );
		$properties               = null === $restrict_to_property_ids
			? $this->properties->all_active()
			: array_values(
				array_filter(
					$this->properties->all_active(),
					fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true )
				)
			);

		$units = null === $restrict_to_property_ids
			? $this->units->all_active()
			: array_values(
				array_filter(
					$this->units->all_active(),
					fn( array $u ): bool => in_array( (int) $u['property_id'], $restrict_to_property_ids, true )
				)
			);

		$is_administrator = $this->access->is_administrator( get_current_user_id() );
		$list_url         = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$log_rows         = 0 === $alert_id ? array() : $this->notifications->for_type_and_entity( 'custom_alert', $alert_id );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/alert-form.php';
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION, 'rm_alert_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$alert_id = isset( $_POST['alert_id'] ) ? absint( $_POST['alert_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$title = isset( $_POST['rm_title'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_title'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$message = isset( $_POST['rm_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_message'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$entity_type = isset( $_POST['rm_entity_type'] ) ? sanitize_key( wp_unslash( $_POST['rm_entity_type'] ) ) : Alert::ENTITY_NONE;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$property_id = isset( $_POST['rm_property_id'] ) ? absint( $_POST['rm_property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$unit_id = isset( $_POST['rm_unit_id'] ) ? absint( $_POST['rm_unit_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$schedule_type = isset( $_POST['rm_schedule_type'] ) ? sanitize_key( wp_unslash( $_POST['rm_schedule_type'] ) ) : Alert::SCHEDULE_ONCE;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$scheduled_at_raw = isset( $_POST['rm_scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_scheduled_at'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$selectors = isset( $_POST['rm_recipient_selectors'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rm_recipient_selectors'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$user_ids_raw = isset( $_POST['rm_recipient_user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_recipient_user_ids'] ) ) : '';
		$user_ids     = array_values( array_filter( array_map( 'absint', explode( ',', $user_ids_raw ) ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$channels = isset( $_POST['rm_channels'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rm_channels'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$active = ! empty( $_POST['rm_active'] );

		$back_to_form = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => 0 === $alert_id ? 'add' : 'edit',
				'id'     => $alert_id,
			),
			admin_url( 'admin.php' )
		);

		$valid_entity_types = array( Alert::ENTITY_PROPERTY, Alert::ENTITY_UNIT, Alert::ENTITY_NONE );
		$valid_schedules    = array( Alert::SCHEDULE_ONCE, Alert::SCHEDULE_DAILY, Alert::SCHEDULE_WEEKLY, Alert::SCHEDULE_MONTHLY );
		$valid_selectors    = array( Alert::RECIPIENT_TENANTS_OF_ENTITY, Alert::RECIPIENT_STAFF_OF_ENTITY, Alert::RECIPIENT_LANDLORD_OF_ENTITY, Alert::RECIPIENT_SELF );
		$valid_channels     = array( NotificationLog::CHANNEL_EMAIL, NotificationLog::CHANNEL_WHATSAPP, NotificationLog::CHANNEL_PORTAL );

		$selectors = array_values( array_intersect( $selectors, $valid_selectors ) );
		$channels  = array_values( array_intersect( $channels, $valid_channels ) );

		if (
			'' === $title
			|| '' === $message
			|| ! in_array( $entity_type, $valid_entity_types, true )
			|| ( Alert::ENTITY_PROPERTY === $entity_type && 0 === $property_id )
			|| ( Alert::ENTITY_UNIT === $entity_type && 0 === $unit_id )
			|| ! in_array( $schedule_type, $valid_schedules, true )
			|| false === strtotime( $scheduled_at_raw )
			|| ( array() === $selectors && array() === $user_ids )
			|| array() === $channels
		) {
			FlashNotice::set( 'alerts', __( 'Please fill in a title, message, valid schedule, at least one recipient, and at least one channel.', 'chrx-rental-manager' ) );
			wp_safe_redirect( $back_to_form );
			exit;
		}

		$entity_id = null;

		if ( Alert::ENTITY_UNIT === $entity_type ) {
			$unit = $this->units->find( $unit_id );

			if ( null === $unit ) {
				FlashNotice::set( 'alerts', __( 'Please choose a valid unit.', 'chrx-rental-manager' ) );
				wp_safe_redirect( $back_to_form );
				exit;
			}

			$entity_id = $unit_id;
			$this->authorize_entity( $entity_type, $entity_id );
		} elseif ( Alert::ENTITY_PROPERTY === $entity_type ) {
			$entity_id = $property_id;
			$this->authorize_entity( $entity_type, $entity_id );
		} else {
			$this->authorize_entity( Alert::ENTITY_NONE, null );
		}

		$data = array(
			'title'         => $title,
			'message'       => $message,
			'entity_type'   => $entity_type,
			'entity_id'     => $entity_id,
			'schedule_type' => $schedule_type,
			'scheduled_at'  => gmdate( 'Y-m-d H:i:s', (int) strtotime( $scheduled_at_raw ) ),
			'recipients'    => array(
				'selectors' => $selectors,
				'user_ids'  => $user_ids,
			),
			'channels'      => $channels,
			'active'        => $active ? 1 : 0,
		);

		if ( 0 === $alert_id ) {
			$data['created_by'] = get_current_user_id();
			$alert_id           = $this->alerts->insert( $data );
		} else {
			$this->alerts->update( $alert_id, $data );
		}

		FlashNotice::set( 'alerts', __( 'Alert saved.', 'chrx-rental-manager' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'edit',
					'id'     => $alert_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_delete_action(): void {
		check_admin_referer( 'rm_alert_delete' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$alert_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$alert    = $this->alerts->find( $alert_id );

		if ( null === $alert ) {
			wp_die( esc_html__( 'Alert not found.', 'chrx-rental-manager' ), 404 );
		}

		$this->authorize_entity( (string) $alert['entity_type'], null !== $alert['entity_id'] ? (int) $alert['entity_id'] : null );

		$this->alerts->delete( $alert_id );

		FlashNotice::set( 'alerts', __( 'Alert deleted.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function handle_toggle_action(): void {
		check_admin_referer( 'rm_alert_toggle' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$alert_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$alert    = $this->alerts->find( $alert_id );

		if ( null === $alert ) {
			wp_die( esc_html__( 'Alert not found.', 'chrx-rental-manager' ), 404 );
		}

		$this->authorize_entity( (string) $alert['entity_type'], null !== $alert['entity_id'] ? (int) $alert['entity_id'] : null );

		$this->alerts->update( $alert_id, array( 'active' => $alert['active'] ? 0 : 1 ) );

		FlashNotice::set( 'alerts', $alert['active'] ? __( 'Alert deactivated.', 'chrx-rental-manager' ) : __( 'Alert activated.', 'chrx-rental-manager' ) );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The property-scoping half of "capability is the *what*, Access is
	 * the *which properties*" (Access::userCanAccessProperty()'s own
	 * docblock) — resolves an alert's entity_type/entity_id down to the
	 * property it's actually scoped to (an ENTITY_UNIT alert scopes to its
	 * unit's property) and checks it, or requires Administrator for
	 * ENTITY_NONE (account-level) alerts, mirroring
	 * ExpensesController's SCOPE_ACCOUNT admin-only rule from V2-4.
	 */
	private function authorize_entity( string $entity_type, ?int $entity_id ): void {
		if ( Alert::ENTITY_NONE === $entity_type || null === $entity_id ) {
			if ( ! $this->access->is_administrator( get_current_user_id() ) ) {
				wp_die( esc_html__( 'Only an Administrator can manage an account-wide alert.', 'chrx-rental-manager' ), 403 );
			}

			return;
		}

		$property_id = $entity_id;

		if ( Alert::ENTITY_UNIT === $entity_type ) {
			$unit = $this->units->find( $entity_id );

			if ( null === $unit ) {
				wp_die( esc_html__( 'Unit not found.', 'chrx-rental-manager' ), 404 );
			}

			$property_id = (int) $unit['property_id'];
		}

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage alerts on this property.', 'chrx-rental-manager' ), 403 );
		}
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
