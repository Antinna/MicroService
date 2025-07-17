<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Controllers\UserController;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Services\SecurityMonitor;
use Antinna\Auth\Services\JWTManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for UserController
 */
class UserControllerTest extends TestCase
{
    private UserController $userController;
    private MockObject $userRepository;
    private MockObject $auditLogger;
    private MockObject $rateLimiter;
    private MockObject $securityMonitor;
    private MockObject $jwtManager;

    protected function setUp(): void
    {
        // Create mocks
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->securityMonitor = $this->createMock(SecurityMonitor::class);
        $this->jwtManager = $this->createMock(JWTManager::class);

        // Create controller instance
        $this->userController = new UserController();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->userController);
        
        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->userController, $this->userRepository);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->userController, $this->auditLogger);

        $rateLimiterProperty = $reflection->getProperty('rateLimiter');
        $rateLimiterProperty->setAccessible(true);
        $rateLimiterProperty->setValue($this->userController, $this->rateLimiter);

        $securityMonitorProperty = $reflection->getProperty('securityMonitor');
        $securityMonitorProperty->setAccessible(true);
        $securityMonitorProperty->setValue($this->userController, $this->securityMonitor);

        $jwtManagerProperty = $reflection->getProperty('jwtManager');
        $jwtManagerProperty->setAccessible(true);
        $jwtManagerProperty->setValue($this->userController, $this->jwtManager);
    }

    public function testGetProfileSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock user repository
        $userData = [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password_hash' => 'hashed_password'
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($userData);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('user_profile', 'read', 1, true, ['user_id' => 1]);

        // Capture output
        ob_start();
        $this->userController->getProfile();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals('test@example.com', $response['data']['user']['email']);
        $this->assertArrayNotHasKey('password_hash', $response['data']['user']);
    }

    public function testGetProfileUnauthenticated(): void
    {
        // No session data
        unset($_SESSION['user_id']);

        // Capture output
        ob_start();
        $this->userController->getProfile();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);
    }

    public function testGetProfileRateLimited(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter - rate limited
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => false]);

        // Capture output
        ob_start();
        $this->userController->getProfile();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $response['code']);
    }

    public function testUpdateProfileSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock input data
        $inputData = json_encode([
            'name' => 'Updated Name',
            'display_name' => 'Updated Display Name'
        ]);

        // Mock php://input
        $this->mockPhpInput($inputData);

        // Mock user repository update
        $this->userRepository->expects($this->once())
            ->method('update')
            ->with(1, ['name' => 'Updated Name', 'display_name' => 'Updated Display Name'])
            ->willReturn(true);

        // Mock user repository find (for returning updated user)
        $updatedUser = [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Updated Name',
            'display_name' => 'Updated Display Name'
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($updatedUser);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with(
                'user_profile_updated',
                'User profile updated',
                1,
                $this->anything(),
                AuditLogger::SEVERITY_INFO,
                ['updated_fields' => ['name', 'display_name']]
            );

        // Capture output
        ob_start();
        $this->userController->updateProfile();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals('Updated Name', $response['data']['user']['name']);
        $this->assertEquals(['name', 'display_name'], $response['data']['updated_fields']);
    }

    public function testChangePasswordSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'password_reset')
            ->willReturn(['allowed' => true]);

        // Mock input data
        $inputData = json_encode([
            'current_password' => 'current_password',
            'new_password' => 'new_strong_password'
        ]);

        // Mock php://input
        $this->mockPhpInput($inputData);

        // Mock user data with hashed password
        $userData = [
            'id' => 1,
            'email' => 'test@example.com',
            'password_hash' => password_hash('current_password', PASSWORD_DEFAULT)
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($userData);

        // Mock password update
        $this->userRepository->expects($this->once())
            ->method('update')
            ->with(1, $this->callback(function($data) {
                return isset($data['password_hash']) && password_verify('new_strong_password', $data['password_hash']);
            }))
            ->willReturn(true);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with(
                AuditLogger::EVENT_PASSWORD_CHANGE,
                'User password changed successfully',
                1,
                $this->anything(),
                AuditLogger::SEVERITY_INFO
            );

        // Capture output
        ob_start();
        $this->userController->changePassword();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals('Password changed successfully', $response['data']['message']);
    }

    public function testChangePasswordInvalidCurrent(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'password_reset')
            ->willReturn(['allowed' => true]);

        // Mock input data with wrong current password
        $inputData = json_encode([
            'current_password' => 'wrong_password',
            'new_password' => 'new_strong_password'
        ]);

        // Mock php://input
        $this->mockPhpInput($inputData);

        // Mock user data with different hashed password
        $userData = [
            'id' => 1,
            'email' => 'test@example.com',
            'password_hash' => password_hash('correct_password', PASSWORD_DEFAULT)
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($userData);

        // Mock audit logger for failed attempt
        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with(
                'password_change_failed',
                'Failed password change attempt - incorrect current password',
                1,
                $this->anything(),
                AuditLogger::SEVERITY_WARNING
            );

        // Capture output
        ob_start();
        $this->userController->changePassword();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('INVALID_CURRENT_PASSWORD', $response['code']);
    }

    public function testGetSecuritySettingsSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn(['id' => 1, 'email' => 'test@example.com']);

        // Mock security settings
        $securitySettings = [
            'user_id' => 1,
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'notification_on_login' => true
        ];

        $this->userRepository->expects($this->once())
            ->method('getSecuritySettings')
            ->with(1)
            ->willReturn($securitySettings);

        // Mock active sessions
        $activeSessions = [
            ['id' => 'session1', 'ip_address' => '192.168.1.1', 'created_at' => '2024-01-01 10:00:00']
        ];

        $this->userRepository->expects($this->once())
            ->method('getActiveSessions')
            ->with(1)
            ->willReturn($activeSessions);

        // Mock security events
        $securityEvents = [
            ['event_type' => 'login_success', 'created_at' => '2024-01-01 10:00:00']
        ];

        $this->auditLogger->expects($this->once())
            ->method('getUserSecurityEvents')
            ->with(1, 10)
            ->willReturn($securityEvents);

        // Mock data access logging
        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('security_settings', 'read', 1, true);

        // Capture output
        ob_start();
        $this->userController->getSecuritySettings();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals($securitySettings, $response['data']['security_settings']);
        $this->assertEquals($activeSessions, $response['data']['active_sessions']);
        $this->assertEquals($securityEvents, $response['data']['recent_security_events']);
    }

    public function testGetSessionsSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;
        $_SESSION['session_id'] = 'current_session';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock active sessions
        $sessions = [
            ['id' => 'current_session', 'ip_address' => '192.168.1.1'],
            ['id' => 'other_session', 'ip_address' => '192.168.1.2']
        ];

        $this->userRepository->expects($this->once())
            ->method('getActiveSessions')
            ->with(1)
            ->willReturn($sessions);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('user_sessions', 'read', 1, true);

        // Capture output
        ob_start();
        $this->userController->getSessions();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertCount(2, $response['data']['sessions']);
        $this->assertTrue($response['data']['sessions'][0]['is_current']);
        $this->assertFalse($response['data']['sessions'][1]['is_current']);
    }

    public function testRevokeSessionSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;
        $_SESSION['session_id'] = 'current_session';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock session data
        $sessionData = [
            'id' => 'target_session',
            'user_id' => 1,
            'ip_address' => '192.168.1.2'
        ];

        $this->userRepository->expects($this->once())
            ->method('getSessionById')
            ->with('target_session')
            ->willReturn($sessionData);

        // Mock session revocation
        $this->userRepository->expects($this->once())
            ->method('revokeSession')
            ->with('target_session')
            ->willReturn(true);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with(
                'session_revoked',
                'User session revoked',
                1,
                $this->anything(),
                AuditLogger::SEVERITY_INFO,
                ['session_id' => 'target_session']
            );

        // Capture output
        ob_start();
        $this->userController->revokeSession('target_session');
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals('target_session', $response['data']['session_id']);
    }

    public function testRevokeCurrentSessionFails(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;
        $_SESSION['session_id'] = 'current_session';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock session data - same as current session
        $sessionData = [
            'id' => 'current_session',
            'user_id' => 1,
            'ip_address' => '192.168.1.1'
        ];

        $this->userRepository->expects($this->once())
            ->method('getSessionById')
            ->with('current_session')
            ->willReturn($sessionData);

        // Capture output
        ob_start();
        $this->userController->revokeSession('current_session');
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('CANNOT_REVOKE_CURRENT_SESSION', $response['code']);
    }

    public function testGetActivityLogSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock GET parameters
        $_GET['page'] = '2';
        $_GET['limit'] = '10';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock activity logs
        $logs = [
            ['event_type' => 'login_success', 'created_at' => '2024-01-01 10:00:00'],
            ['event_type' => 'profile_updated', 'created_at' => '2024-01-01 09:00:00']
        ];

        $this->auditLogger->expects($this->once())
            ->method('getUserLogs')
            ->with(1, 10, 2)
            ->willReturn($logs);

        $this->auditLogger->expects($this->once())
            ->method('getUserLogsCount')
            ->with(1)
            ->willReturn(25);

        // Mock data access logging
        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('activity_log', 'read', 1, true);

        // Capture output
        ob_start();
        $this->userController->getActivityLog();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals($logs, $response['data']['logs']);
        $this->assertEquals(2, $response['data']['pagination']['page']);
        $this->assertEquals(10, $response['data']['pagination']['limit']);
        $this->assertEquals(25, $response['data']['pagination']['total_count']);
        $this->assertEquals(3, $response['data']['pagination']['total_pages']);
    }

    /**
     * Helper method to mock php://input
     */
    private function mockPhpInput(string $data): void
    {
        // This is a simplified mock - in a real test environment,
        // you might use a stream wrapper or dependency injection
        // to properly mock file_get_contents('php://input')
        
        // For now, we'll assume the controller can be modified to accept
        // input data directly for testing purposes
    }

    protected function tearDown(): void
    {
        // Clean up session data
        unset($_SESSION['user_id']);
        unset($_SESSION['session_id']);
        unset($_GET['page']);
        unset($_GET['limit']);
    }
}