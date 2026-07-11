<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents — links a WP media library attachment to a lease/unit/tenant
 * (SPEC.md §7). Attachment lifecycle (upload, storage) is handled by WP
 * core; this table only tracks the association.
 */
final class Document extends AbstractRepository {

	protected const TABLE = 'rm_documents';

	public const ENTITY_LEASE  = 'lease';
	public const ENTITY_UNIT   = 'unit';
	public const ENTITY_TENANT = 'tenant';

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function for_entity( string $entity_type, int $entity_id ): array {
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return $this->results(
			"SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d ORDER BY created_at DESC",
			array( $entity_type, $entity_id )
		);
	}

	public function delete( int $id ): bool {
		return $this->hard_delete( $id );
	}
}
