<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Admin\Support\PortalStatus;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Roles\RoleManager;

/**
 * Portal status derivation feeding the Tenants list "Portal" column and
 * the Tenant detail "Portal access" panel (Phase 3).
 */
final class PortalStatusTest extends IntegrationTestCase {

	public function test_tenant_with_no_wp_user_is_not_invited(): void {
		$tenants = new Tenant();
		$id = $tenants->insert( [ 'full_name' => 'No Account Tenant' ] );
		$tenant = $tenants->find( $id );

		$this->assertSame( PortalStatus::NOT_INVITED, PortalStatus::for_tenant( $tenant ) );
	}

	public function test_tenant_with_unset_password_is_invited(): void {
		$user_id = wp_insert_user( [
			'user_login' => 'portal_status_invited_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'test_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );

		// wp_insert_user() leaves user_activation_key empty by default;
		// simulate an actual invite by setting a reset key, exactly what
		// TenantInviteController::send_invite_email() triggers via
		// get_password_reset_key().
		get_password_reset_key( get_userdata( $user_id ) );

		$tenants = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Invited Tenant', 'wp_user_id' => $user_id ] );
		$tenant = $tenants->find( $tenant_id );

		$this->assertSame( PortalStatus::INVITED, PortalStatus::for_tenant( $tenant ) );
	}

	public function test_tenant_who_has_set_a_password_is_active(): void {
		$user_id = wp_insert_user( [
			'user_login' => 'portal_status_active_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'test_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );

		$user = get_userdata( $user_id );
		get_password_reset_key( $user );
		// reset_password() (called by both the invite and forgot-password
		// flows) clears user_activation_key via wp_set_password().
		reset_password( $user, 'NewPass123!' );

		$tenants = new Tenant();
		$tenant_id = $tenants->insert( [ 'full_name' => 'Active Tenant', 'wp_user_id' => $user_id ] );
		$tenant = $tenants->find( $tenant_id );

		$this->assertSame( PortalStatus::ACTIVE, PortalStatus::for_tenant( $tenant ) );
	}
}
