<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\AdminAuthenticator;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for AdminAuthenticator
 */
class AdminAuthenticatorTest extends TestCase
{
    private AdminAuthenticator $adminAuth;
    private MockObject $userRepository;
    private MockObject $auditLogger;
    private MockObject $rateLimiter;

    protected function setUp(): void
    {
        // Create mocks
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);

        // Create AdminAuthenticator instance
        $this->adminAuth = new AdminAuthenticator();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->adminAuth);
        
        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->adminAuth, $this->userRepository);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->adminAuth, $this->auditLogger);

        $rateLimiterProperty = $reflection->getProperty('rateLimiter');
        $rateLimiterProperty->setAccessible(true);
        $rateLimiterProperty->setValue($this->adminAuth, $this->rateLimiter);
    }

    public function testSuccessfulAdminAuthentication(): void
    {
        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with('admin@example.com', RateLimiter::LIMIT_TYPE_IP, 'admin_login')
            ->willReturn(['allowed' => true]);

        // Mock admin user
        $adminUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'password_hash' => password_hash('admin_password', PASSWORD_DEFAULT),
            'role' => AdminAuthenticator::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('admin@example.com')
            ->willReturn($adminUser);

        // Mock security settings (MFA enabled)
        $this->userRepository->expects($this->once())
            ->method('getSecuritySettings')
            ->with(1)
            ->willReturn(['mfa_enabled' => true]);

        // Mock successful login logging
        $this->auditLogger->expects($this->once())
            ->method('logAuthentication')
            ->with(
                AuditLogger::EVENT_LOGIN_SUCCESS,
                'Admin login successful',
                1,
                'admin_password',
                true,
                $this->anything()
            );

        $this->userRepository->expects($this->once())
            ->method('updateLastLogin')
            ->with(1);

        // Test authentication
        $result = $this->adminAuth->authenticateAdmin('admin@example.com', 'admin_password', '192.168.1.1');

        $this->assertTrue($result['success']);
        $this->assertEquals('Admin authentication successful', $result['message']);
        $this->assertEquals(1, $result['user']['id']);
        $this->assertEquals(AdminAuthenticator::ROLE_ADMIN, $result['user']['role']);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayNotHasKey('password_hash', $result['user']);
    }

    public function testAdminAuthenticationWithInvalidCredentials(): void
    {
        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock user not found
        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('admin@example.com')
            ->willReturn(null);

        // Mock failed login logging
        $this->auditLogger->expects($this->once())
            ->method('logSecurityIncident')
            ->with(
                'admin_login_failed',
                'Failed admin login attempt: User not found',
                null,
                AuditLogger::SEVERITY_WARNING,
                $this->anything()
            );

        // Test authentication
        $result = $this->adminAuth->authenticateAdmin('admin@example.com', 'wrong_password', '192.168.1.1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
        $this->assertEquals('INVALID_CREDENTIALS', $result['code']);
    }

    public function testAdminAuthenticationWithInactiveAccount(): void
    {
        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock inactive admin user
        $adminUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'password_hash' => password_hash('admin_password', PASSWORD_DEFAULT),
            'role' => AdminAuthenticator::ROLE_ADMIN,
            'is_active' => false
        ];

        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('admin@example.com')
            ->willReturn($adminUser);

        // Mock failed login logging
        $this->auditLogger->expects($this->once())
            ->method('logSecurityIncident')
            ->with(
                'admin_login_failed',
                'Failed admin login attempt: Account inactive',
                1,
                AuditLogger::SEVERITY_WARNING,
                $this->anything()
            );

        // Test authentication
        $result = $this->adminAuth->authenticateAdmin('admin@example.com', 'admin_password', '192.168.1.1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Account is inactive', $result['message']);
        $this->assertEquals('ACCOUNT_INACTIVE', $result['code']);
    }

    public function testAdminAuthenticationWithNonAdminRole(): void
    {
        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock regular user (not admin)
        $regularUser = [
            'id' => 1,
            'email' => 'user@example.com',
            'password_hash' => password_hash('user_password', PASSWORD_DEFAULT),
            'role' => 'user',
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('user@example.com')
            ->willReturn($regularUser);

        // Mock failed login logging
        $this->auditLogger->expects($this->once())
            ->method('logSecurityIncident')
            ->with(
                'admin_login_failed',
                'Failed admin login attempt: Insufficient privileges',
                1,
                AuditLogger::SEVERITY_WARNING,
                $this->anything()
            );

        // Test authentication
        $result = $this->adminAuth->authenticateAdmin('user@example.com', 'user_password', '192.168.1.1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Insufficient privileges', $result['message']);
        $this->assertEquals('INSUFFICIENT_PRIVILEGES', $result['code']);
    }

    public function testAdminAuthenticationWithMFARequired(): void
    {
        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock admin user
        $adminUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'password_hash' => password_hash('admin_password', PASSWORD_DEFAULT),
            'role' => AdminAuthenticator::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('findByEmail')
            ->with('admin@example.com')
            ->willReturn($adminUser);

        // Mock security settings (MFA not enabled)
        $this->userRepository->expects($this->once())
            ->method('getSecuritySettings')
            ->with(1)
            ->willReturn(['mfa_enabled' => false]);

        // Test authentication
        $result = $this->adminAuth->authenticateAdmin('admin@example.com', 'admin_password', '192.168.1.1');

        $this->assertFalse($result['success']);
        $this->assertEquals('MFA verification required', $result['message']);
        $this->assertEquals('MFA_REQUIRED', $result['code']);
        $this->assertEquals(1, $result['user_id']);
        $this->assertArrayHasKey('mfa_methods', $result);
    }

    public function testAdminAuthenticationRateLimited(): void
    {
        // Mock rate limiter - rate limited
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with('admin@example.com', RateLimiter::LIMIT_TYPE_IP, 'admin_login')
            ->willReturn(['allowed' => false]);

        // Mock security incident logging
        $this->auditLogger->expects($this->once())
            ->method('logSecurityIncident')
            ->with(
                'admin_login_rate_limited',
                'Admin login rate limit exceeded',
                null,
                AuditLogger::SEVERITY_WARNING,
                $this->anything()
            );

        // Test authentication
        $result = $this->adminAuth->authenticateAdmin('admin@example.com', 'admin_password', '192.168.1.1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Too many login attempts. Please try again later.', $result['message']);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $result['code']);
    }

    public function testVerifyAdminSessionSuccess(): void
    {
        // Mock admin user
        $adminUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => AdminAuthenticator::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($adminUser);

        // Test session verification
        $result = $this->adminAuth->verifyAdminSession(1);

        $this->assertTrue($result['valid']);
        $this->assertEquals(AdminAuthenticator::ROLE_ADMIN, $result['role']);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayNotHasKey('password_hash', $result['user']);
    }

    public function testVerifyAdminSessionWithPermissionCheck(): void
    {
        // Mock admin user
        $adminUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => AdminAuthenticator::ROLE_USER_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($adminUser);

        // Test session verification with permission that user_admin doesn't have
        $result = $this->adminAuth->verifyAdminSession(1, AdminAuthenticator::PERMISSION_SYSTEM_CONFIG);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Permission denied', $result['message']);
        $this->assertEquals('PERMISSION_DENIED', $result['code']);
        $this->assertEquals(AdminAuthenticator::PERMISSION_SYSTEM_CONFIG, $result['required_permission']);
    }

    public function testIsAdminRole(): void
    {
        $this->assertTrue($this->adminAuth->isAdminRole(AdminAuthenticator::ROLE_ADMIN));
        $this->assertTrue($this->adminAuth->isAdminRole(AdminAuthenticator::ROLE_SUPER_ADMIN));
        $this->assertTrue($this->adminAuth->isAdminRole(AdminAuthenticator::ROLE_SECURITY_ADMIN));
        $this->assertTrue($this->adminAuth->isAdminRole(AdminAuthenticator::ROLE_USER_ADMIN));
        $this->assertFalse($this->adminAuth->isAdminRole('user'));
        $this->assertFalse($this->adminAuth->isAdminRole('moderator'));
    }

    public function testGetAdminPermissions(): void
    {
        // Test super admin permissions
        $superAdminPerms = $this->adminAuth->getAdminPermissions(AdminAuthenticator::ROLE_SUPER_ADMIN);
        $this->assertContains(AdminAuthenticator::PERMISSION_USER_MANAGEMENT, $superAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_SECURITY_POLICIES, $superAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_SYSTEM_CONFIG, $superAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_AUDIT_LOGS, $superAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_SECURITY_METRICS, $superAdminPerms);

        // Test regular admin permissions
        $adminPerms = $this->adminAuth->getAdminPermissions(AdminAuthenticator::ROLE_ADMIN);
        $this->assertContains(AdminAuthenticator::PERMISSION_USER_MANAGEMENT, $adminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_SECURITY_POLICIES, $adminPerms);
        $this->assertNotContains(AdminAuthenticator::PERMISSION_SYSTEM_CONFIG, $adminPerms);

        // Test security admin permissions
        $securityAdminPerms = $this->adminAuth->getAdminPermissions(AdminAuthenticator::ROLE_SECURITY_ADMIN);
        $this->assertNotContains(AdminAuthenticator::PERMISSION_USER_MANAGEMENT, $securityAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_SECURITY_POLICIES, $securityAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_SECURITY_METRICS, $securityAdminPerms);

        // Test user admin permissions
        $userAdminPerms = $this->adminAuth->getAdminPermissions(AdminAuthenticator::ROLE_USER_ADMIN);
        $this->assertContains(AdminAuthenticator::PERMISSION_USER_MANAGEMENT, $userAdminPerms);
        $this->assertNotContains(AdminAuthenticator::PERMISSION_SECURITY_POLICIES, $userAdminPerms);
        $this->assertContains(AdminAuthenticator::PERMISSION_AUDIT_LOGS, $userAdminPerms);
    }

    public function testHasPermission(): void
    {
        // Test super admin has all permissions
        $this->assertTrue($this->adminAuth->hasPermission(
            AdminAuthenticator::ROLE_SUPER_ADMIN,
            AdminAuthenticator::PERMISSION_SYSTEM_CONFIG
        ));

        // Test regular admin doesn't have system config permission
        $this->assertFalse($this->adminAuth->hasPermission(
            AdminAuthenticator::ROLE_ADMIN,
            AdminAuthenticator::PERMISSION_SYSTEM_CONFIG
        ));

        // Test security admin has security permissions
        $this->assertTrue($this->adminAuth->hasPermission(
            AdminAuthenticator::ROLE_SECURITY_ADMIN,
            AdminAuthenticator::PERMISSION_SECURITY_POLICIES
        ));

        // Test user admin doesn't have security permissions
        $this->assertFalse($this->adminAuth->hasPermission(
            AdminAuthenticator::ROLE_USER_ADMIN,
            AdminAuthenticator::PERMISSION_SECURITY_POLICIES
        ));
    }

    public function testCreateAdminSession(): void
    {
        // Mock admin user
        $adminUser = [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => AdminAuthenticator::ROLE_ADMIN,
            'is_active' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($adminUser);

        // Mock audit logging
        $this->auditLogger->expects($this->once())
            ->method('logAdminAction')
            ->with(
                'admin_session_created',
                'Admin session created',
                1,
                null,
                $this->anything()
            );

        // Test session creation
        $result = $this->adminAuth->createAdminSession(1, '192.168.1.1', 'Admin Browser');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('session_id', $result);
        $this->assertArrayHasKey('session_token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayNotHasKey('password_hash', $result['user']);
    }
}