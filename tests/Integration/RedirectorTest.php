<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Integration;

use ChrxRentalManager\Auth\Redirector;
use ChrxRentalManager\Roles\RoleManager;

/**
 * Regression test for a real bug caught via live tenant-portal
 * verification (Phase 7): bounce_tenants_out_of_wp_admin() runs on
 * `admin_init`, which also fires for admin-post.php — without the
 * exemption this method now has, a tenant's own "Download PDF" request
 * (Portal\PortalReceiptDownload, an admin-post.php handler) got redirected
 * back to the portal home instead of ever reaching the handler.
 *
 * wp_safe_redirect()+exit() in the non-exempted branch makes that branch
 * unsafe to call directly inside PHPUnit (exit() would kill the test
 * runner) — this test only exercises the admin-post.php early-return,
 * which is exactly the branch the bug was in.
 */
final class RedirectorTest extends IntegrationTestCase {

	public function test_a_tenant_hitting_admin_post_php_is_not_bounced(): void {
		global $pagenow;

		( new RoleManager() )->register_roles();

		$wp_user_id = wp_insert_user( [
			'user_login' => 'redirector_test_' . wp_generate_password( 6, false ),
			'user_email' => uniqid( 'redirector_', true ) . '@example.com',
			'user_pass'  => wp_generate_password(),
			'role'       => RoleManager::ROLE_TENANT,
		] );

		wp_set_current_user( $wp_user_id );

		$previous_pagenow = $pagenow;
		$pagenow          = 'admin-post.php';

		// If the admin-post.php exemption regresses, this call redirects
		// and calls exit(), which would abort the PHPUnit process rather
		// than fail this assertion cleanly — reaching the assertion at
		// all is the point.
		( new Redirector() )->bounce_tenants_out_of_wp_admin();
		$this->addToAssertionCount( 1 );

		$pagenow = $previous_pagenow;
		wp_set_current_user( 0 );
	}
}
