<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\RolePermissionManager;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for RolePermissionManager
 */
class RolePermissionTest extends TestCase
{
    private RolePermissionManager $rolePermissionManager;
    private MockObject $userRepository;
    private MockObject $auditLogger;

    protected function setUp(): void
    {
        // Create mocks
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        // Create RolePermissionManager instance
        $this->rolePermissionManager = new RolePermissionManager();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->rolePermissionManager);
        
        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->rolePermissionManager, $this->userRepository);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->rolePermissionManager, $this->auditLogger);
    }

    public function testUserHasRoleBasedPermission(): void
    {
        // Mock user with admin role
        $user = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => RolePermissionManager::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Mock audit logging
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'permission_check',
                $this->stringContains('Permission check: user:read'),
                AuditLogger::SEVERITY_INFO,
                $this->anything()
            );

        // Test permission check
        $result = $this->rolePermissionManager->hasPermission(1, RolePermissionManager::PERMISSION_USER_READ);

        $this->assertTrue($result['has_permission']);
        $this->assertEquals(1, $result['user_id']);
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $result['user_role']);
        $this->assertEquals(RolePermissionManager::PERMISSION_USER_READ, $result['permission']);
        $this->assertContains('role', $result['granted_by']);
        $this->assertArrayHasKey('check_time', $result);
    }

    public function testUserLacksPermission(): void
    {
        // Mock user with regular user role
        $user = [
            'id' => 2,
            'email' => 'user@example.com',
            'role' => RolePermissionManager::ROLE_USER,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(2)
            ->willReturn($user);

        // Mock audit logging
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent');

        // Test permission check for admin permission
        $result = $this->rolePermissionManager->hasPermission(2, RolePermissionManager::PERMISSION_ADMIN_ACCESS);

        $this->assertFalse($result['has_permission']);
        $this->assertEquals(2, $result['user_id']);
        $this->assertEquals(RolePermissionManager::ROLE_USER, $result['user_role']);
        $this->assertEquals(RolePermissionManager::PERMISSION_ADMIN_ACCESS, $result['permission']);
    }

    public function testInactiveUserPermissionCheck(): void
    {
        // Mock inactive user
        $user = [
            'id' => 3,
            'email' => 'inactive@example.com',
            'role' => RolePermissionManager::ROLE_USER,
            'is_active' => false
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(3)
            ->willReturn($user);

        // Test permission check
        $result = $this->rolePermissionManager->hasPermission(3, RolePermissionManager::PERMISSION_USER_READ);

        $this->assertFalse($result['has_permission']);
        $this->assertEquals('User account is inactive', $result['reason']);
        $this->assertEquals('USER_INACTIVE', $result['code']);
    }

    public function testNonExistentUserPermissionCheck(): void
    {
        // Mock user not found
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        // Test permission check
        $result = $this->rolePermissionManager->hasPermission(999, RolePermissionManager::PERMISSION_USER_READ);

        $this->assertFalse($result['has_permission']);
        $this->assertEquals('User not found', $result['reason']);
        $this->assertEquals('USER_NOT_FOUND', $result['code']);
    }

    public function testWildcardPermissionMatching(): void
    {
        // Mock super admin user
        $user = [
            'id' => 1,
            'email' => 'superadmin@example.com',
            'role' => RolePermissionManager::ROLE_SUPER_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Mock audit logging
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent');

        // Test wildcard permission (super admin has admin:* which should match admin:custom)
        $result = $this->rolePermissionManager->hasPermission(1, 'admin:custom');

        $this->assertTrue($result['has_permission']);
        $this->assertEquals(RolePermissionManager::ROLE_SUPER_ADMIN, $result['user_role']);
    }

    public function testMultiplePermissionCheck(): void
    {
        // Mock admin user
        $user = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => RolePermissionManager::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->exactly(3))
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Mock audit logging
        $this->auditLogger->expects($this->exactly(3))
            ->method('logSystemEvent');

        $permissions = [
            RolePermissionManager::PERMISSION_USER_READ,
            RolePermissionManager::PERMISSION_USER_WRITE,
            RolePermissionManager::PERMISSION_SYSTEM_CONFIG // Admin doesn't have this
        ];

        // Test requiring all permissions (should fail)
        $result = $this->rolePermissionManager->hasPermissions(1, $permissions, true);

        $this->assertFalse($result['has_permissions']);
        $this->assertTrue($result['require_all']);
        $this->assertCount(2, $result['granted_permissions']);
        $this->assertCount(1, $result['denied_permissions']);
        $this->assertContains(RolePermissionManager::PERMISSION_SYSTEM_CONFIG, $result['denied_permissions']);

        // Test requiring any permission (should succeed)
        $result2 = $this->rolePermissionManager->hasPermissions(1, $permissions, false);

        $this->assertTrue($result2['has_permissions']);
        $this->assertFalse($result2['require_all']);
        $this->assertCount(2, $result2['granted_permissions']);
    }

    public function testGetUserPermissions(): void
    {
        // Mock moderator user
        $user = [
            'id' => 1,
            'email' => 'moderator@example.com',
            'role' => RolePermissionManager::ROLE_MODERATOR,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Test getting user permissions
        $result = $this->rolePermissionManager->getUserPermissions(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['user_id']);
        $this->assertEquals(RolePermissionManager::ROLE_MODERATOR, $result['user_role']);
        $this->assertArrayHasKey('all', $result['permissions']);
        $this->assertArrayHasKey('role_based', $result['permissions']);
        $this->assertArrayHasKey('user_specific', $result['permissions']);
        $this->assertArrayHasKey('inherited', $result['permissions']);
        $this->assertGreaterThan(0, $result['permission_count']);
        $this->assertArrayHasKey('grouped_permissions', $result);

        // Verify moderator has inherited permissions from user role
        $allPermissions = $result['permissions']['all'];
        $this->assertContains(RolePermissionManager::PERMISSION_USER_READ, $allPermissions);
        $this->assertContains(RolePermissionManager::PERMISSION_CONTENT_READ, $allPermissions);
        $this->assertContains(RolePermissionManager::PERMISSION_CONTENT_WRITE, $allPermissions);
    }

    public function testRoleValidation(): void
    {
        // Test valid role
        $result = $this->rolePermissionManager->validateRole(RolePermissionManager::ROLE_ADMIN);

        $this->assertTrue($result['valid']);
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $result['role']);
        $this->assertEquals(80, $result['level']);
        $this->assertIsArray($result['permissions']);
        $this->assertEquals(RolePermissionManager::ROLE_MODERATOR, $result['inherits_from']);

        // Test invalid role
        $result2 = $this->rolePermissionManager->validateRole('invalid_role');

        $this->assertFalse($result2['valid']);
        $this->assertEquals('Invalid role', $result2['message']);
        $this->assertEquals('INVALID_ROLE', $result2['code']);
        $this->assertArrayHasKey('valid_roles', $result2);
    }

    public function testRoleManagementCapability(): void
    {
        // Test admin can manage moderator
        $result = $this->rolePermissionManager->canManageRole(
            RolePermissionManager::ROLE_ADMIN,
            RolePermissionManager::ROLE_MODERATOR
        );

        $this->assertTrue($result['can_manage']);
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $result['manager_role']);
        $this->assertEquals(80, $result['manager_level']);
        $this->assertEquals(RolePermissionManager::ROLE_MODERATOR, $result['target_role']);
        $this->assertEquals(60, $result['target_level']);
        $this->assertEquals('Sufficient role level', $result['reason']);

        // Test user cannot manage admin
        $result2 = $this->rolePermissionManager->canManageRole(
            RolePermissionManager::ROLE_USER,
            RolePermissionManager::ROLE_ADMIN
        );

        $this->assertFalse($result2['can_manage']);
        $this->assertEquals('Insufficient role level', $result2['reason']);

        // Test invalid roles
        $result3 = $this->rolePermissionManager->canManageRole('invalid_role', RolePermissionManager::ROLE_USER);

        $this->assertFalse($result3['can_manage']);
        $this->assertEquals('Invalid role(s)', $result3['reason']);
        $this->assertEquals('INVALID_ROLE', $result3['code']);
    }

    public function testPermissionGranting(): void
    {
        // Mock granter with admin permissions
        $granterUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => RolePermissionManager::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($granterUser);

        // Mock audit logging for permission check and grant
        $this->auditLogger->expects($this->exactly(2))
            ->method('logSystemEvent');

        $this->auditLogger->expects($this->once())
            ->method('logAdminAction')
            ->with(
                'permission_granted',
                "Permission 'user:special' granted to user",
                1,
                2,
                $this->anything()
            );

        // Test granting permission
        $result = $this->rolePermissionManager->grantPermission(2, 'user:special', 1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Permission granted successfully', $result['message']);
        $this->assertEquals(2, $result['user_id']);
        $this->assertEquals('user:special', $result['permission']);
        $this->assertEquals(1, $result['granted_by']);
        $this->assertArrayHasKey('granted_at', $result);
    }

    public function testPermissionRevocation(): void
    {
        // Mock revoker with admin permissions
        $revokerUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => RolePermissionManager::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($revokerUser);

        // Mock audit logging
        $this->auditLogger->expects($this->exactly(2))
            ->method('logSystemEvent');

        $this->auditLogger->expects($this->once())
            ->method('logAdminAction')
            ->with(
                'permission_revoked',
                "Permission 'user:special' revoked from user",
                1,
                2,
                $this->anything()
            );

        // Test revoking permission
        $result = $this->rolePermissionManager->revokePermission(2, 'user:special', 1, 'No longer needed');

        $this->assertTrue($result['success']);
        $this->assertEquals('Permission revoked successfully', $result['message']);
        $this->assertEquals(2, $result['user_id']);
        $this->assertEquals('user:special', $result['permission']);
        $this->assertEquals(1, $result['revoked_by']);
        $this->assertEquals('No longer needed', $result['reason']);
        $this->assertArrayHasKey('revoked_at', $result);
    }

    public function testInsufficientPrivilegesForPermissionManagement(): void
    {
        // Mock regular user trying to grant permissions
        $regularUser = [
            'id' => 2,
            'email' => 'user@example.com',
            'role' => RolePermissionManager::ROLE_USER,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(2)
            ->willReturn($regularUser);

        // Mock audit logging
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent');

        // Test granting permission without privileges
        $result = $this->rolePermissionManager->grantPermission(3, 'user:special', 2);

        $this->assertFalse($result['success']);
        $this->assertEquals('Insufficient privileges to grant permissions', $result['message']);
        $this->assertEquals('INSUFFICIENT_PRIVILEGES', $result['code']);
    }

    public function testRoleHierarchy(): void
    {
        // Test getting role hierarchy
        $hierarchy = $this->rolePermissionManager->getRoleHierarchy();

        $this->assertArrayHasKey('hierarchy', $hierarchy);
        $this->assertArrayHasKey('roles_by_level', $hierarchy);
        $this->assertArrayHasKey('permission_matrix', $hierarchy);

        // Verify hierarchy structure
        $this->assertArrayHasKey(RolePermissionManager::ROLE_SUPER_ADMIN, $hierarchy['hierarchy']);
        $this->assertArrayHasKey(RolePermissionManager::ROLE_ADMIN, $hierarchy['hierarchy']);
        $this->assertArrayHasKey(RolePermissionManager::ROLE_USER, $hierarchy['hierarchy']);

        // Verify roles are ordered by level
        $rolesByLevel = $hierarchy['roles_by_level'];
        $levels = array_keys($rolesByLevel);
        $this->assertEquals($levels, array_reverse(array_sort($levels))); // Should be descending

        // Verify permission matrix
        $matrix = $hierarchy['permission_matrix'];
        $this->assertArrayHasKey(RolePermissionManager::ROLE_ADMIN, $matrix);
        $this->assertArrayHasKey('level', $matrix[RolePermissionManager::ROLE_ADMIN]);
        $this->assertArrayHasKey('direct_permissions', $matrix[RolePermissionManager::ROLE_ADMIN]);
        $this->assertArrayHasKey('inherited_permissions', $matrix[RolePermissionManager::ROLE_ADMIN]);
        $this->assertArrayHasKey('all_permissions', $matrix[RolePermissionManager::ROLE_ADMIN]);
    }

    public function testPermissionInheritance(): void
    {
        // Mock admin user
        $user = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => RolePermissionManager::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Get user permissions
        $result = $this->rolePermissionManager->getUserPermissions(1);

        $this->assertTrue($result['success']);
        
        // Admin should inherit permissions from moderator and user roles
        $inheritedPermissions = $result['permissions']['inherited'];
        $this->assertNotEmpty($inheritedPermissions);
        
        // Should include permissions from lower-level roles
        $allPermissions = $result['permissions']['all'];
        $this->assertContains(RolePermissionManager::PERMISSION_USER_READ, $allPermissions);
        $this->assertContains(RolePermissionManager::PERMISSION_CONTENT_READ, $allPermissions);
    }
}