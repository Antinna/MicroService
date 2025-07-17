<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\RolePermissionManager;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Controllers\RolePermissionController;
use Antinna\Auth\Routes\RolePermissionRoutes;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration test for Role and Permission System
 */
class RolePermissionIntegrationTest extends TestCase
{
    private PDO $db;
    private UserRepository $userRepository;
    private RolePermissionManager $rolePermissionManager;
    private JWTManager $jwtManager;
    private RolePermissionController $controller;
    private RolePermissionRoutes $routes;
    private int $adminUserId;
    private int $moderatorUserId;
    private int $regularUserId;
    private string $adminToken;
    private string $moderatorToken;
    private string $userToken;

    protected function setUp(): void
    {
        // Set up test database connection
        $this->db = Connection::getInstance()->getConnection();
        
        // Initialize services
        $this->userRepository = new UserRepository();
        $this->rolePermissionManager = new RolePermissionManager();
        $this->jwtManager = new JWTManager();
        $this->controller = new RolePermissionController();
        $this->routes = new RolePermissionRoutes();

        // Create test users with different roles
        $this->createTestUsers();
        $this->createTestTokens();
    }

    public function testCompleteRolePermissionWorkflow(): void
    {
        echo "Testing complete role and permission workflow...\n";

        // 1. Test role validation
        $this->testRoleValidation();

        // 2. Test permission checking
        $this->testPermissionChecking();

        // 3. Test role hierarchy
        $this->testRoleHierarchy();

        // 4. Test permission management
        $this->testPermissionManagement();

        // 5. Test API endpoints
        $this->testAPIEndpoints();

        // 6. Test routes integration
        $this->testRoutesIntegration();

        echo "✓ Complete role and permission workflow tested successfully\n";
    }

    private function createTestUsers(): void
    {
        // Create admin user
        $adminData = [
            'email' => 'role.admin@example.com',
            'phone' => '+1234567897',
            'password_hash' => password_hash('admin_password', PASSWORD_DEFAULT),
            'name' => 'Role Admin User',
            'display_name' => 'Role Admin',
            'role' => RolePermissionManager::ROLE_ADMIN,
            'is_active' => true,
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->adminUserId = $this->userRepository->create($adminData);
        $this->assertGreaterThan(0, $this->adminUserId, 'Admin user should be created successfully');

        // Create moderator user
        $moderatorData = [
            'email' => 'role.moderator@example.com',
            'phone' => '+1234567898',
            'password_hash' => password_hash('moderator_password', PASSWORD_DEFAULT),
            'name' => 'Role Moderator User',
            'display_name' => 'Role Moderator',
            'role' => RolePermissionManager::ROLE_MODERATOR,
            'is_active' => true,
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->moderatorUserId = $this->userRepository->create($moderatorData);
        $this->assertGreaterThan(0, $this->moderatorUserId, 'Moderator user should be created successfully');

        // Create regular user
        $userData = [
            'email' => 'role.user@example.com',
            'phone' => '+1234567899',
            'password_hash' => password_hash('user_password', PASSWORD_DEFAULT),
            'name' => 'Role Regular User',
            'display_name' => 'Role User',
            'role' => RolePermissionManager::ROLE_USER,
            'is_active' => true,
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->regularUserId = $this->userRepository->create($userData);
        $this->assertGreaterThan(0, $this->regularUserId, 'Regular user should be created successfully');
    }

    private function createTestTokens(): void
    {
        // Create admin token
        $adminTokenResult = $this->jwtManager->generateToken([
            'user_id' => $this->adminUserId,
            'token_type' => 'access',
            'scopes' => ['admin:access', 'admin:users', 'admin:permissions', 'user:read', 'user:write'],
            'iss' => 'auth-service'
        ], 3600);

        $this->assertTrue($adminTokenResult['success'], 'Admin token should be generated successfully');
        $this->adminToken = $adminTokenResult['token'];

        // Create moderator token
        $moderatorTokenResult = $this->jwtManager->generateToken([
            'user_id' => $this->moderatorUserId,
            'token_type' => 'access',
            'scopes' => ['user:read', 'user:write', 'content:read', 'content:write'],
            'iss' => 'auth-service'
        ], 3600);

        $this->assertTrue($moderatorTokenResult['success'], 'Moderator token should be generated successfully');
        $this->moderatorToken = $moderatorTokenResult['token'];

        // Create user token
        $userTokenResult = $this->jwtManager->generateToken([
            'user_id' => $this->regularUserId,
            'token_type' => 'access',
            'scopes' => ['user:read', 'content:read'],
            'iss' => 'auth-service'
        ], 3600);

        $this->assertTrue($userTokenResult['success'], 'User token should be generated successfully');
        $this->userToken = $userTokenResult['token'];
    }

    private function testRoleValidation(): void
    {
        echo "  Testing role validation...\n";

        // Test valid roles
        $validRoles = [
            RolePermissionManager::ROLE_SUPER_ADMIN,
            RolePermissionManager::ROLE_ADMIN,
            RolePermissionManager::ROLE_MODERATOR,
            RolePermissionManager::ROLE_USER,
            RolePermissionManager::ROLE_GUEST
        ];

        foreach ($validRoles as $role) {
            $result = $this->rolePermissionManager->validateRole($role);
            $this->assertTrue($result['valid'], "Role $role should be valid");
            $this->assertEquals($role, $result['role']);
            $this->assertIsInt($result['level']);
            $this->assertIsArray($result['permissions']);
        }

        echo "    ✓ Valid roles validation\n";

        // Test invalid role
        $result = $this->rolePermissionManager->validateRole('invalid_role');
        $this->assertFalse($result['valid'], 'Invalid role should fail validation');
        $this->assertEquals('INVALID_ROLE', $result['code']);
        $this->assertArrayHasKey('valid_roles', $result);

        echo "    ✓ Invalid role validation\n";

        // Test role management capabilities
        $canManage = $this->rolePermissionManager->canManageRole(
            RolePermissionManager::ROLE_ADMIN,
            RolePermissionManager::ROLE_USER
        );
        $this->assertTrue($canManage['can_manage'], 'Admin should be able to manage user');

        $cannotManage = $this->rolePermissionManager->canManageRole(
            RolePermissionManager::ROLE_USER,
            RolePermissionManager::ROLE_ADMIN
        );
        $this->assertFalse($cannotManage['can_manage'], 'User should not be able to manage admin');

        echo "    ✓ Role management capabilities\n";
    }

    private function testPermissionChecking(): void
    {
        echo "  Testing permission checking...\n";

        // Test admin permissions
        $adminPermissionCheck = $this->rolePermissionManager->hasPermission(
            $this->adminUserId,
            RolePermissionManager::PERMISSION_USER_WRITE
        );
        $this->assertTrue($adminPermissionCheck['has_permission'], 'Admin should have user:write permission');
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $adminPermissionCheck['user_role']);

        echo "    ✓ Admin permission check\n";

        // Test moderator permissions
        $moderatorPermissionCheck = $this->rolePermissionManager->hasPermission(
            $this->moderatorUserId,
            RolePermissionManager::PERMISSION_CONTENT_WRITE
        );
        $this->assertTrue($moderatorPermissionCheck['has_permission'], 'Moderator should have content:write permission');

        // Test moderator lacks admin permissions
        $moderatorAdminCheck = $this->rolePermissionManager->hasPermission(
            $this->moderatorUserId,
            RolePermissionManager::PERMISSION_ADMIN_ACCESS
        );
        $this->assertFalse($moderatorAdminCheck['has_permission'], 'Moderator should not have admin:access permission');

        echo "    ✓ Moderator permission check\n";

        // Test regular user permissions
        $userPermissionCheck = $this->rolePermissionManager->hasPermission(
            $this->regularUserId,
            RolePermissionManager::PERMISSION_USER_READ
        );
        $this->assertTrue($userPermissionCheck['has_permission'], 'User should have user:read permission');

        // Test user lacks write permissions
        $userWriteCheck = $this->rolePermissionManager->hasPermission(
            $this->regularUserId,
            RolePermissionManager::PERMISSION_USER_WRITE
        );
        $this->assertFalse($userWriteCheck['has_permission'], 'User should not have user:write permission');

        echo "    ✓ Regular user permission check\n";

        // Test multiple permissions check
        $multiplePermissions = [
            RolePermissionManager::PERMISSION_USER_READ,
            RolePermissionManager::PERMISSION_CONTENT_READ,
            RolePermissionManager::PERMISSION_ADMIN_ACCESS
        ];

        $adminMultipleCheck = $this->rolePermissionManager->hasPermissions(
            $this->adminUserId,
            $multiplePermissions,
            false // require any
        );
        $this->assertTrue($adminMultipleCheck['has_permissions'], 'Admin should have at least one permission');
        $this->assertGreaterThan(0, count($adminMultipleCheck['granted_permissions']));

        $userMultipleCheck = $this->rolePermissionManager->hasPermissions(
            $this->regularUserId,
            $multiplePermissions,
            true // require all
        );
        $this->assertFalse($userMultipleCheck['has_permissions'], 'User should not have all permissions');
        $this->assertContains(RolePermissionManager::PERMISSION_ADMIN_ACCESS, $userMultipleCheck['denied_permissions']);

        echo "    ✓ Multiple permissions check\n";
    }

    private function testRoleHierarchy(): void
    {
        echo "  Testing role hierarchy...\n";

        $hierarchy = $this->rolePermissionManager->getRoleHierarchy();
        
        $this->assertArrayHasKey('hierarchy', $hierarchy);
        $this->assertArrayHasKey('roles_by_level', $hierarchy);
        $this->assertArrayHasKey('permission_matrix', $hierarchy);

        // Verify hierarchy levels
        $rolesByLevel = $hierarchy['roles_by_level'];
        $this->assertEquals(RolePermissionManager::ROLE_SUPER_ADMIN, $rolesByLevel[100]);
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $rolesByLevel[80]);
        $this->assertEquals(RolePermissionManager::ROLE_MODERATOR, $rolesByLevel[60]);
        $this->assertEquals(RolePermissionManager::ROLE_USER, $rolesByLevel[40]);
        $this->assertEquals(RolePermissionManager::ROLE_GUEST, $rolesByLevel[20]);

        echo "    ✓ Role hierarchy levels\n";

        // Test permission inheritance
        $adminPermissions = $this->rolePermissionManager->getUserPermissions($this->adminUserId);
        $this->assertTrue($adminPermissions['success']);
        
        $allPermissions = $adminPermissions['permissions']['all'];
        $inheritedPermissions = $adminPermissions['permissions']['inherited'];
        
        // Admin should inherit permissions from moderator and user
        $this->assertNotEmpty($inheritedPermissions);
        $this->assertContains(RolePermissionManager::PERMISSION_USER_READ, $allPermissions);
        $this->assertContains(RolePermissionManager::PERMISSION_CONTENT_READ, $allPermissions);

        echo "    ✓ Permission inheritance\n";

        // Test permission matrix
        $matrix = $hierarchy['permission_matrix'];
        $adminMatrix = $matrix[RolePermissionManager::ROLE_ADMIN];
        
        $this->assertEquals(80, $adminMatrix['level']);
        $this->assertIsArray($adminMatrix['direct_permissions']);
        $this->assertIsArray($adminMatrix['inherited_permissions']);
        $this->assertIsArray($adminMatrix['all_permissions']);
        $this->assertGreaterThan(count($adminMatrix['direct_permissions']), count($adminMatrix['all_permissions']));

        echo "    ✓ Permission matrix\n";
    }

    private function testPermissionManagement(): void
    {
        echo "  Testing permission management...\n";

        // Test granting permission (admin granting to user)
        $grantResult = $this->rolePermissionManager->grantPermission(
            $this->regularUserId,
            'user:special',
            $this->adminUserId,
            ['reason' => 'Test permission grant']
        );
        $this->assertTrue($grantResult['success'], 'Permission grant should succeed');
        $this->assertEquals($this->regularUserId, $grantResult['user_id']);
        $this->assertEquals('user:special', $grantResult['permission']);
        $this->assertEquals($this->adminUserId, $grantResult['granted_by']);

        echo "    ✓ Permission granting\n";

        // Test revoking permission
        $revokeResult = $this->rolePermissionManager->revokePermission(
            $this->regularUserId,
            'user:special',
            $this->adminUserId,
            'Test permission revocation'
        );
        $this->assertTrue($revokeResult['success'], 'Permission revocation should succeed');
        $this->assertEquals($this->regularUserId, $revokeResult['user_id']);
        $this->assertEquals('user:special', $revokeResult['permission']);
        $this->assertEquals($this->adminUserId, $revokeResult['revoked_by']);
        $this->assertEquals('Test permission revocation', $revokeResult['reason']);

        echo "    ✓ Permission revocation\n";

        // Test insufficient privileges (user trying to grant permissions)
        $insufficientResult = $this->rolePermissionManager->grantPermission(
            $this->moderatorUserId,
            'admin:access',
            $this->regularUserId // Regular user trying to grant
        );
        $this->assertFalse($insufficientResult['success'], 'Regular user should not be able to grant permissions');
        $this->assertEquals('INSUFFICIENT_PRIVILEGES', $insufficientResult['code']);

        echo "    ✓ Insufficient privileges check\n";
    }

    private function testAPIEndpoints(): void
    {
        echo "  Testing API endpoints...\n";

        // Test permission check endpoint
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->adminToken;
        
        $this->mockJsonInput(json_encode([
            'user_id' => $this->adminUserId,
            'permission' => RolePermissionManager::PERMISSION_USER_READ,
            'context' => []
        ]));

        ob_start();
        $this->controller->checkPermission();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Permission check API should succeed');
        $this->assertTrue($response['data']['has_permission']);
        $this->assertEquals($this->adminUserId, $response['data']['user_id']);

        echo "    ✓ Permission check endpoint\n";

        // Test multiple permissions check endpoint
        $this->mockJsonInput(json_encode([
            'user_id' => $this->moderatorUserId,
            'permissions' => [
                RolePermissionManager::PERMISSION_USER_READ,
                RolePermissionManager::PERMISSION_CONTENT_WRITE,
                RolePermissionManager::PERMISSION_ADMIN_ACCESS
            ],
            'require_all' => false
        ]));

        ob_start();
        $this->controller->checkMultiplePermissions();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Multiple permissions check API should succeed');
        $this->assertTrue($response['data']['has_permissions']); // Should have at least one
        $this->assertGreaterThan(0, count($response['data']['granted_permissions']));
        $this->assertContains(RolePermissionManager::PERMISSION_ADMIN_ACCESS, $response['data']['denied_permissions']);

        echo "    ✓ Multiple permissions check endpoint\n";

        // Test get user permissions endpoint
        ob_start();
        $this->controller->getUserPermissions($this->moderatorUserId);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Get user permissions API should succeed');
        $this->assertEquals($this->moderatorUserId, $response['data']['user_id']);
        $this->assertEquals(RolePermissionManager::ROLE_MODERATOR, $response['data']['user_role']);
        $this->assertArrayHasKey('permissions', $response['data']);
        $this->assertGreaterThan(0, $response['data']['permission_count']);

        echo "    ✓ Get user permissions endpoint\n";

        // Test role validation endpoint
        $this->mockJsonInput(json_encode([
            'role' => RolePermissionManager::ROLE_ADMIN
        ]));

        ob_start();
        $this->controller->validateRole();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Role validation API should succeed');
        $this->assertTrue($response['data']['valid']);
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $response['data']['role']);
        $this->assertEquals(80, $response['data']['level']);

        echo "    ✓ Role validation endpoint\n";

        // Test role management check endpoint
        $this->mockJsonInput(json_encode([
            'manager_role' => RolePermissionManager::ROLE_ADMIN,
            'target_role' => RolePermissionManager::ROLE_USER
        ]));

        ob_start();
        $this->controller->canManageRole();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Role management check API should succeed');
        $this->assertTrue($response['data']['can_manage']);
        $this->assertEquals(RolePermissionManager::ROLE_ADMIN, $response['data']['manager_role']);
        $this->assertEquals(RolePermissionManager::ROLE_USER, $response['data']['target_role']);

        echo "    ✓ Role management check endpoint\n";

        // Test role hierarchy endpoint
        ob_start();
        $this->controller->getRoleHierarchy();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Role hierarchy API should succeed');
        $this->assertArrayHasKey('hierarchy', $response['data']);
        $this->assertArrayHasKey('roles_by_level', $response['data']);
        $this->assertArrayHasKey('permission_matrix', $response['data']);

        echo "    ✓ Role hierarchy endpoint\n";
    }

    private function testRoutesIntegration(): void
    {
        echo "  Testing routes integration...\n";

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->adminToken;

        // Test permission check route
        $this->mockJsonInput(json_encode([
            'user_id' => $this->adminUserId,
            'permission' => RolePermissionManager::PERMISSION_USER_READ
        ]));

        ob_start();
        $this->routes->handleRequest('POST', '/api/permissions/check');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Permission check route should work');

        echo "    ✓ Permission check route\n";

        // Test role validation route
        $this->mockJsonInput(json_encode([
            'role' => RolePermissionManager::ROLE_MODERATOR
        ]));

        ob_start();
        $this->routes->handleRequest('POST', '/api/roles/validate');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Role validation route should work');

        echo "    ✓ Role validation route\n";

        // Test get user permissions route
        ob_start();
        $this->routes->handleRequest('GET', "/api/permissions/user/{$this->moderatorUserId}");
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Get user permissions route should work');

        echo "    ✓ Get user permissions route\n";

        // Test role hierarchy route
        ob_start();
        $this->routes->handleRequest('GET', '/api/roles/hierarchy');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Role hierarchy route should work');

        echo "    ✓ Role hierarchy route\n";

        // Test invalid route
        ob_start();
        $this->routes->handleRequest('GET', '/api/invalid/route');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Invalid route should return error');
        $this->assertEquals('NOT_FOUND', $response['code']);

        echo "    ✓ Invalid route handling\n";

        // Test method not allowed
        ob_start();
        $this->routes->handleRequest('DELETE', '/api/permissions/check');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'DELETE should not be allowed');
        $this->assertEquals('METHOD_NOT_ALLOWED', $response['code']);

        echo "    ✓ Method not allowed handling\n";
    }

    public function testErrorHandling(): void
    {
        echo "Testing error handling...\n";

        // Test missing authentication
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->controller->getUserPermissions($this->regularUserId);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('MISSING_AUTH_HEADER', $response['code']);

        echo "  ✓ Missing authentication error\n";

        // Test insufficient permissions
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->userToken; // Regular user token

        $this->mockJsonInput(json_encode([
            'user_id' => $this->moderatorUserId,
            'permission' => 'admin:special'
        ]));

        ob_start();
        $this->controller->grantPermission();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        // Should fail due to insufficient token scopes

        echo "  ✓ Insufficient permissions error\n";

        // Test invalid JSON input
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->adminToken;
        $this->mockJsonInput('invalid json');

        ob_start();
        $this->controller->checkPermission();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);

        echo "  ✓ Invalid JSON error\n";

        // Test missing required fields
        $this->mockJsonInput(json_encode([]));

        ob_start();
        $this->controller->checkPermission();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('MISSING_REQUIRED_FIELDS', $response['code']);

        echo "  ✓ Missing required fields error\n";
    }

    /**
     * Mock JSON input for testing
     */
    private function mockJsonInput(string $json): void
    {
        // Create a temporary stream
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $json);
        rewind($stream);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->adminUserId) {
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->adminUserId]);
        }
        if ($this->moderatorUserId) {
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->moderatorUserId]);
        }
        if ($this->regularUserId) {
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->regularUserId]);
        }

        // Clean up globals
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REQUEST_METHOD']);
    }
}