<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Tests\Unit;

use ChrxRentalManager\Roles\RoleManager;
use PHPUnit\Framework\TestCase;

final class PluginBootstrapTest extends TestCase {

	public function test_role_manager_defines_three_custom_roles(): void {
		$role_manager = new RoleManager();
		$roles        = $role_manager->custom_role_definitions();

		$this->assertCount( 3, $roles );
		$this->assertArrayHasKey( RoleManager::ROLE_STAFF, $roles );
		$this->assertArrayHasKey( RoleManager::ROLE_LANDLORD_OWNER, $roles );
		$this->assertArrayHasKey( RoleManager::ROLE_TENANT, $roles );
	}
}
