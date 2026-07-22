<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Admin;

use ChrxRentalManager\Data\Document;
use ChrxRentalManager\Data\Expense;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\Access;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared document/photo attachment handling for Unit, Tenant, and Lease
 * detail screens (SPEC.md §7: WP media library, rm_documents table).
 * One upload/delete admin-post handler for all three entity types rather
 * than duplicating the same WP media-library plumbing three times.
 */
final class DocumentsController {

	private const UPLOAD_NONCE_ACTION = 'rm_upload_document';
	private const DELETE_NONCE_ACTION = 'rm_delete_document';

	private Document $documents;
	private Unit $units;
	private Lease $leases;
	private Tenant $tenants;
	private Expense $expenses;
	private Access $access;

	public function __construct(
		?Document $documents = null,
		?Unit $units = null,
		?Lease $leases = null,
		?Tenant $tenants = null,
		?Expense $expenses = null,
		?Access $access = null
	) {
		$this->documents = $documents ?? new Document();
		$this->units     = $units ?? new Unit();
		$this->leases    = $leases ?? new Lease( $this->units );
		$this->tenants   = $tenants ?? new Tenant();
		$this->expenses  = $expenses ?? new Expense();
		$this->access    = $access ?? new Access();
	}

	public function register(): void {
		add_action( 'admin_post_rm_upload_document', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_rm_delete_document', array( $this, 'handle_delete' ) );
	}

	public function handle_upload(): void {
		check_admin_referer( self::UPLOAD_NONCE_ACTION );

		if ( ! current_user_can( RoleManager::CAP_MANAGE_UNITS ) && ! current_user_can( RoleManager::CAP_MANAGE_TENANTS ) && ! current_user_can( RoleManager::CAP_MANAGE_LEASES ) && ! current_user_can( RoleManager::CAP_MANAGE_EXPENSES ) ) {
			wp_die( esc_html__( 'You do not have permission to upload documents.', 'chrx-rental-manager' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$entity_type = isset( $_POST['entity_type'] ) ? sanitize_key( wp_unslash( $_POST['entity_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$entity_id = isset( $_POST['entity_id'] ) ? absint( $_POST['entity_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$redirect_back = isset( $_POST['_wp_http_referer'] ) ? sanitize_text_field( wp_unslash( $_POST['_wp_http_referer'] ) ) : admin_url();

		if ( ! $this->can_manage_entity( $entity_type, $entity_id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage documents on this record.', 'chrx-rental-manager' ), 403 );
		}

		if ( empty( $_FILES['rm_document'] ) || ! is_array( $_FILES['rm_document'] ) ) {
			wp_safe_redirect( add_query_arg( 'rm_doc_error', '1', $redirect_back ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'rm_document', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_safe_redirect( add_query_arg( 'rm_doc_error', '1', $redirect_back ) );
			exit;
		}

		$this->documents->insert(
			array(
				'entity_type'   => $entity_type,
				'entity_id'     => $entity_id,
				'attachment_id' => $attachment_id,
				'label'         => get_the_title( $attachment_id ),
				'uploaded_by'   => get_current_user_id(),
			)
		);

		wp_safe_redirect( $redirect_back );
		exit;
	}

	public function handle_delete(): void {
		check_admin_referer( self::DELETE_NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer().
		$redirect_back = isset( $_GET['redirect'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect'] ) ) : admin_url();

		$document = $this->documents->find( $document_id );

		if ( null === $document ) {
			wp_die( esc_html__( 'Document not found.', 'chrx-rental-manager' ), 404 );
		}

		if ( ! $this->can_manage_entity( $document['entity_type'], (int) $document['entity_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to manage documents on this record.', 'chrx-rental-manager' ), 403 );
		}

		$this->documents->delete( $document_id );

		wp_safe_redirect( $redirect_back );
		exit;
	}

	private function can_manage_entity( string $entity_type, int $entity_id ): bool {
		$user_id = get_current_user_id();

		if ( $this->access->is_administrator( $user_id ) ) {
			return true;
		}

		$property_id = $this->property_id_for_entity( $entity_type, $entity_id );

		if ( null === $property_id ) {
			// Tenant/lease/account-scoped-expense with no unit tied to a
			// property yet — any staff with the relevant manage capability
			// may attach documents.
			return current_user_can( RoleManager::CAP_MANAGE_UNITS ) || current_user_can( RoleManager::CAP_MANAGE_TENANTS ) || current_user_can( RoleManager::CAP_MANAGE_LEASES ) || current_user_can( RoleManager::CAP_MANAGE_EXPENSES );
		}

		return $this->access->userCanAccessProperty( $user_id, $property_id );
	}

	private function property_id_for_entity( string $entity_type, int $entity_id ): ?int {
		if ( Document::ENTITY_UNIT === $entity_type ) {
			$unit = $this->units->find( $entity_id );

			return null === $unit ? null : (int) $unit['property_id'];
		}

		if ( Document::ENTITY_EXPENSE === $entity_type ) {
			$expense = $this->expenses->find( $entity_id );

			return null === $expense || null === $expense['property_id'] ? null : (int) $expense['property_id'];
		}

		if ( Document::ENTITY_LEASE === $entity_type ) {
			$lease = $this->leases->find( $entity_id );

			if ( null === $lease ) {
				return null;
			}

			$unit = $this->units->find( (int) $lease['unit_id'] );

			return null === $unit ? null : (int) $unit['property_id'];
		}

		if ( Document::ENTITY_TENANT === $entity_type ) {
			$leases = $this->leases->for_tenant( $entity_id );

			foreach ( $leases as $lease ) {
				$unit = $this->units->find( (int) $lease['unit_id'] );

				if ( null !== $unit ) {
					return (int) $unit['property_id'];
				}
			}
		}

		return null;
	}

	public static function upload_nonce_action(): string {
		return self::UPLOAD_NONCE_ACTION;
	}

	public static function delete_nonce_action(): string {
		return self::DELETE_NONCE_ACTION;
	}
}
