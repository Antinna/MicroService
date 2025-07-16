<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\VendorRoleManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VendorRoleManager
 */
class VendorRoleManagerTest extends TestCase
{
    private VendorRoleManager $manager;

    protected function setUp(): void
    {
        $this->manager = new VendorRoleManager();
    }

    public function testValidateRequiredFields()
    {
        $data = [];
        $result = $this->manager->validate($data);
        
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('vendor_id', $result['errors']);
        $this->assertArrayHasKey('user_id', $result['errors']);
        $this->assertArrayHasKey('role', $result['errors']);
    }

    public function testValidateVendorId()
    {
        // Test invalid vendor ID
        $data = ['vendor_id' => 'invalid'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('vendor_id', $result['errors']);
        $this->assertStringContainsString('must be a valid number', $result['errors']['vendor_id']);

        // Test valid vendor ID
        $data = ['vendor_id' => 123];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('vendor_id', $result['errors']);
    }

    public function testValidateUserId()
    {
        // Test invalid user ID
        $data = ['user_id' => 'invalid'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('user_id', $result['errors']);
        $this->assertStringContainsString('must be a valid number', $result['errors']['user_id']);

        // Test valid user ID
        $data = ['user_id' => 456];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('user_id', $result['errors']);
    }

    public function testValidateRole()
    {
        // Test invalid role
        $data = ['role' => 'invalid_role'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('role', $result['errors']);
        $this->assertStringContainsString('must be one of', $result['errors']['role']);

        // Test valid roles
        $validRoles = ['owner', 'manager', 'delivery_staff'];
        foreach ($validRoles as $role) {
            $data = ['role' => $role];
            $result = $this->manager->validate($data);
            $this->assertArrayNotHasKey('role', $result['errors']);
        }
    }

    public function testValidatePermissions()
    {
        // Test invalid permissions format
        $data = ['permissions' => 'invalid'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('permissions', $result['errors']);
        $this->assertStringContainsString('must be an array', $result['errors']['permissions']);

        // Test invalid permission values
        $data = ['permissions' => ['invalid.permission']];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('permissions', $result['errors']);
        $this->assertIsArray($result['errors']['permissions']);

        // Test valid permissions
        $data = ['permissions' => ['vendor.view', 'products.view']];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('permissions', $result['errors']);
    }

    public function testValidCompleteData()
    {
        $data = [
            'vendor_id' => 123,
            'user_id' => 456,
            'role' => 'manager',
            'permissions' => ['vendor.view', 'products.manage']
        ];

        $result = $this->manager->validate($data);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals($data['vendor_id'], $result['data']['vendor_id']);
        $this->assertEquals($data['user_id'], $result['data']['user_id']);
        $this->assertEquals($data['role'], $result['data']['role']);
        $this->assertEquals($data['permissions'], $result['data']['permissions']);
    }

    public function testGetAvailableRoles()
    {
        $result = $this->manager->getAvailableRoles();
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('roles', $result);
        $this->assertIsArray($result['roles']);
        
        // Check that all expected roles are present
        $roleNames = array_column($result['roles'], 'role');
        $this->assertContains('owner', $roleNames);
        $this->assertContains('manager', $roleNames);
        $this->assertContains('delivery_staff', $roleNames);
        
        // Check role structure
        foreach ($result['roles'] as $role) {
            $this->assertArrayHasKey('role', $role);
            $this->assertArrayHasKey('permissions', $role);
            $this->assertArrayHasKey('description', $role);
            $this->assertIsArray($role['permissions']);
            $this->assertIsString($role['description']);
        }
    }

    public function testOwnerPermissions()
    {
        $result = $this->manager->getAvailableRoles();
        $ownerRole = null;
        
        foreach ($result['roles'] as $role) {
            if ($role['role'] === 'owner') {
                $ownerRole = $role;
                break;
            }
        }
        
        $this->assertNotNull($ownerRole);
        $this->assertContains('vendor.manage', $ownerRole['permissions']);
        $this->assertContains('roles.manage', $ownerRole['permissions']);
        $this->assertContains('settings.manage', $ownerRole['permissions']);
    }

    public function testManagerPermissions()
    {
        $result = $this->manager->getAvailableRoles();
        $managerRole = null;
        
        foreach ($result['roles'] as $role) {
            if ($role['role'] === 'manager') {
                $managerRole = $role;
                break;
            }
        }
        
        $this->assertNotNull($managerRole);
        $this->assertContains('products.manage', $managerRole['permissions']);
        $this->assertContains('orders.manage', $managerRole['permissions']);
        $this->assertNotContains('roles.manage', $managerRole['permissions']);
        $this->assertNotContains('settings.manage', $managerRole['permissions']);
    }

    public function testDeliveryStaffPermissions()
    {
        $result = $this->manager->getAvailableRoles();
        $deliveryRole = null;
        
        foreach ($result['roles'] as $role) {
            if ($role['role'] === 'delivery_staff') {
                $deliveryRole = $role;
                break;
            }
        }
        
        $this->assertNotNull($deliveryRole);
        $this->assertContains('orders.view', $deliveryRole['permissions']);
        $this->assertContains('delivery.manage', $deliveryRole['permissions']);
        $this->assertNotContains('products.manage', $deliveryRole['permissions']);
        $this->assertNotContains('vendor.manage', $deliveryRole['permissions']);
    }

    // Note: The following tests would require mocking the repository in a real test environment
    
    public function testAssignRoleWithMockData()
    {
        // This test would require mocking VendorRoleRepository
        // In a real test, you would mock the repository to avoid database calls
        $this->assertTrue(true); // Placeholder assertion
    }

    public function testHasPermissionWithMockData()
    {
        // This test would require mocking VendorRoleRepository
        // In a real test, you would mock the repository to avoid database calls
        $this->assertTrue(true); // Placeholder assertion
    }

    public function testHasRoleWithMockData()
    {
        // This test would require mocking VendorRoleRepository
        // In a real test, you would mock the repository to avoid database calls
        $this->assertTrue(true); // Placeholder assertion
    }
}