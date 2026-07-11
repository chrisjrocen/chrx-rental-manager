<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Billing\StatementPdf;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\PropertyLandlord;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Landlord statement PDF generator (SPEC.md §4.4). Staff/Admin
 * (rm_view_reports) get the full generator (designs/22): landlord +
 * property + date-range selection, live preview, download. A pure
 * Landlord-Owner (rm_view_statements only) instead gets a fixed,
 * read-only "My statements" list (designs/28) for the last several
 * completed months of each property they own — the same underlying
 * download endpoint either way, gated by Access::userCanAccessProperty()
 * so a landlord can never fetch a statement for a property they don't
 * own by manipulating the query string (the SPEC.md §4.4 edge case this
 * phase's deliverable specifically calls out to test).
 */
final class StatementsController {

	private const PAGE_SLUG       = 'chrx-rm-statements';
	private const DOWNLOAD_ACTION = 'rm_download_statement';

	private StatementPdf $statement_pdf;
	private Property $properties;
	private PropertyLandlord $property_landlords;
	private Access $access;

	public function __construct(
		?StatementPdf $statement_pdf = null,
		?Property $properties = null,
		?PropertyLandlord $property_landlords = null,
		?Access $access = null
	) {
		$this->statement_pdf      = $statement_pdf ?? new StatementPdf();
		$this->properties         = $properties ?? new Property();
		$this->property_landlords = $property_landlords ?? new PropertyLandlord();
		$this->access             = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
	}

	public function render(): void {
		if ( ! current_user_can( RoleManager::CAP_VIEW_STATEMENTS ) && ! current_user_can( RoleManager::CAP_VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view statements.', 'chrx-rental-manager' ), 403 );
		}

		$user_id = get_current_user_id();

		// CAP_MANAGE_PROPERTIES, not CAP_VIEW_REPORTS, is the correct
		// staff-vs-landlord signal here: SPEC.md §2's role table grants
		// Landlord-Owner "reports" too, so they hold rm_view_reports
		// exactly like Staff/Admin do (see RoleManager's capability
		// sets) — only the manage capability is genuinely staff/admin-only.
		// designs/22's full generator has no landlord-facing variant; the
		// landlord's "reports" per SPEC.md is designs/28's read-only list.
		$is_staff_or_admin = current_user_can( RoleManager::CAP_MANAGE_PROPERTIES );

		if ( ! $is_staff_or_admin ) {
			$this->render_landlord_list( $user_id );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'preview' === $action ) {
			$this->render_preview( $user_id );

			return;
		}

		$this->render_generator_form( $user_id );
	}

	private function render_generator_form( int $user_id ): void {
		$landlords = get_users( array( 'role' => RoleManager::ROLE_LANDLORD_OWNER ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, no state change.
		$selected_landlord_id = isset( $_GET['landlord_id'] ) ? absint( $_GET['landlord_id'] ) : 0;

		$restrict_to_property_ids = $this->access->accessiblePropertyIds( $user_id );
		$landlord_property_ids    = $selected_landlord_id > 0 ? $this->property_landlords->property_ids_for_user( $selected_landlord_id ) : null;

		$available_properties = $this->properties->all_active();

		if ( null !== $restrict_to_property_ids ) {
			$available_properties = array_values( array_filter( $available_properties, fn( array $p ): bool => in_array( (int) $p['id'], $restrict_to_property_ids, true ) ) );
		}

		if ( null !== $landlord_property_ids ) {
			$available_properties = array_values( array_filter( $available_properties, fn( array $p ): bool => in_array( (int) $p['id'], $landlord_property_ids, true ) ) );
		}

		$default_from = gmdate( 'Y-m-01', strtotime( current_time( 'Y-m-d' ) . ' first day of last month' ) );
		$default_to   = gmdate( 'Y-m-t', strtotime( $default_from ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/statement-generator.php';
	}

	private function render_preview( int $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change; the actual PDF-serving endpoint re-checks access before streaming anything.
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change; display only.
		$landlord_id = isset( $_GET['landlord_id'] ) ? absint( $_GET['landlord_id'] ) : 0;

		$property = $this->properties->find( $property_id );

		if ( null === $property || false === strtotime( $from ) || false === strtotime( $to ) ) {
			wp_die( esc_html__( 'Please choose a property and a valid date range.', 'chrx-rental-manager' ), 400 );
		}

		if ( ! $this->access->userCanAccessProperty( $user_id, $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view statements for this property.', 'chrx-rental-manager' ), 403 );
		}

		$landlord_user = $landlord_id > 0 ? get_userdata( $landlord_id ) : false;
		$landlord_name = false !== $landlord_user ? $landlord_user->display_name : '';

		$download_url = $this->download_url( $property_id, $from, $to, $landlord_name );
		$list_url     = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/statement-preview.php';
	}

	private function render_landlord_list( int $user_id ): void {
		$property_ids = $this->access->accessiblePropertyIds( $user_id ) ?? array();
		$properties   = array_filter(
			array_map( fn( int $id ): ?array => $this->properties->find( $id ), $property_ids ),
			static fn( ?array $p ): bool => null !== $p
		);

		// Last 6 completed calendar months (not the current, still-open
		// one) — SPEC.md §4.4 doesn't define a statement cadence, and
		// there's no persisted statement history to read back from
		// (see StatementPdf's docblock), so this is a fixed, reasonable
		// window rather than an open-ended date picker for a read-only
		// audience.
		$periods = array();

		for ( $i = 1; $i <= 6; $i++ ) {
			$month_start = gmdate( 'Y-m-01', strtotime( current_time( 'Y-m-d' ) . " -{$i} month" ) );
			$periods[]   = array(
				'from'  => $month_start,
				'to'    => gmdate( 'Y-m-t', strtotime( $month_start ) ),
				'label' => gmdate( 'F Y', strtotime( $month_start ) ),
			);
		}

		$rows = array();

		foreach ( $properties as $property ) {
			foreach ( $periods as $period ) {
				$rows[] = array(
					'property' => $property,
					'period'   => $period,
					'summary'  => $this->statement_pdf->summary( (int) $property['id'], $period['from'], $period['to'] ),
				);
			}
		}

		include \ChrxRentalManager\PLUGIN_DIR . '/templates/admin/statements-list.php';
	}

	public function handle_download(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below via check_admin_referer() once the property id is known.
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;
		check_admin_referer( self::DOWNLOAD_ACTION . '_' . $property_id );

		if ( ! current_user_can( RoleManager::CAP_VIEW_STATEMENTS ) && ! current_user_can( RoleManager::CAP_VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view statements.', 'chrx-rental-manager' ), 403 );
		}

		if ( ! $this->access->userCanAccessProperty( get_current_user_id(), $property_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view statements for this property.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$landlord_name = isset( $_GET['landlord_name'] ) ? sanitize_text_field( wp_unslash( $_GET['landlord_name'] ) ) : '';

		if ( false === strtotime( $from ) || false === strtotime( $to ) ) {
			wp_die( esc_html__( 'Invalid date range.', 'chrx-rental-manager' ), 400 );
		}

		$pdf_bytes = $this->statement_pdf->render( $property_id, $from, $to, $landlord_name );

		if ( null === $pdf_bytes ) {
			wp_die( esc_html__( 'Property not found.', 'chrx-rental-manager' ), 404 );
		}

		$property = $this->properties->find( $property_id );
		$filename = sanitize_file_name( $property['name'] . '-statement-' . gmdate( 'Y-m', strtotime( $from ) ) );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf_bytes ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF binary, not HTML.
		echo $pdf_bytes;
		exit;
	}

	public function download_url( int $property_id, string $from, string $to, string $landlord_name = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => self::DOWNLOAD_ACTION,
					'property_id'   => $property_id,
					'from'          => $from,
					'to'            => $to,
					'landlord_name' => $landlord_name,
				),
				admin_url( 'admin-post.php' )
			),
			self::DOWNLOAD_ACTION . '_' . $property_id
		);
	}

	public static function page_slug(): string {
		return self::PAGE_SLUG;
	}
}
