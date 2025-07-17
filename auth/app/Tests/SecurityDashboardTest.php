<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Controllers\SecurityDashboardController;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Services\SecurityMonitor;
use Antinna\Auth\Services\JWTManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for SecurityDashboardController
 */
class SecurityDashboardTest extends TestCase
{
    private SecurityDashboardController $controller;
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
        $this->controller = new SecurityDashboardController();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->controller);
        
        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->controller, $this->userRepository);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->controller, $this->auditLogger);

        $rateLimiterProperty = $reflection->getProperty('rateLimiter');
        $rateLimiterProperty->setAccessible(true);
        $rateLimiterProperty->setValue($this->controller, $this->rateLimiter);

        $securityMonitorProperty = $reflection->getProperty('securityMonitor');
        $securityMonitorProperty->setAccessible(true);
        $securityMonitorProperty->setValue($this->controller, $this->securityMonitor);

        $jwtManagerProperty = $reflection->getProperty('jwtManager');
        $jwtManagerProperty->setAccessible(true);
        $jwtManagerProperty->setValue($this->controller, $this->jwtManager);
    }

    public function testGetDashboardSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock user data
        $userData = [
            'id' => 1,
            'email' => 'test@example.com',
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => '2024-01-01 10:00:00',
            'last_login' => '2024-01-15 14:30:00'
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($userData);

        // Mock security settings
        $securitySettings = [
            'user_id' => 1,
            'mfa_enabled' => true,
            'mfa_method' => 'totp'
        ];

        $this->userRepository->expects($this->once())
            ->method('getSecuritySettings')
            ->with(1)
            ->willReturn($securitySettings);

        // Mock active sessions
        $sessions = [
            [
                'id' => 'session1',
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Mozilla/5.0 Chrome',
                'last_activity' => '2024-01-15 14:30:00'
            ]
        ];

        $this->userRepository->expects($this->once())
            ->method('getActiveSessions')
            ->with(1)
            ->willReturn($sessions);

        // Mock audit logger calls
        $this->auditLogger->expects($this->once())
            ->method('searchLogs')
            ->willReturn([]);

        $this->auditLogger->expects($this->once())
            ->method('getUserSecurityEvents')
            ->with(1, 5)
            ->willReturn([]);

        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('security_dashboard', 'read', 1, true, $this->anything());

        // Capture output
        ob_start();
        $this->controller->getDashboard();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('user_info', $response['data']);
        $this->assertArrayHasKey('login_history', $response['data']);
        $this->assertArrayHasKey('active_sessions', $response['data']);
        $this->assertArrayHasKey('security_events', $response['data']);
        $this->assertArrayHasKey('security_metrics', $response['data']);
        $this->assertArrayHasKey('threat_analysis', $response['data']);
    }

    public function testGetLoginHistorySuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock GET parameters
        $_GET['page'] = '1';
        $_GET['limit'] = '10';
        $_GET['days'] = '30';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock login history
        $loginHistory = [
            [
                'id' => 1,
                'event_type' => AuditLogger::EVENT_LOGIN_SUCCESS,
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Mozilla/5.0 Chrome',
                'created_at' => '2024-01-15 14:30:00',
                'metadata' => json_encode(['auth_method' => 'password'])
            ],
            [
                'id' => 2,
                'event_type' => AuditLogger::EVENT_LOGIN_FAILED,
                'ip_address' => '192.168.1.2',
                'user_agent' => 'Mozilla/5.0 Firefox',
                'created_at' => '2024-01-14 10:15:00',
                'metadata' => json_encode(['auth_method' => 'password'])
            ]
        ];

        $this->auditLogger->expects($this->once())
            ->method('searchLogs')
            ->with($this->callback(function($criteria) {
                return $criteria['user_id'] === 1 &&
                       is_array($criteria['event_type']) &&
                       in_array(AuditLogger::EVENT_LOGIN_SUCCESS, $criteria['event_type']) &&
                       in_array(AuditLogger::EVENT_LOGIN_FAILED, $criteria['event_type']);
            }), 10)
            ->willReturn($loginHistory);

        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('login_history', 'read', 1, true, $this->anything());

        // Capture output
        ob_start();
        $this->controller->getLoginHistory();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('login_history', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);
        
        $history = $response['data']['login_history'];
        $this->assertCount(2, $history);
        $this->assertTrue($history[0]['success']); // LOGIN_SUCCESS
        $this->assertFalse($history[1]['success']); // LOGIN_FAILED
        
        $summary = $response['data']['summary'];
        $this->assertEquals(2, $summary['total_logins']);
        $this->assertEquals(1, $summary['successful_logins']);
        $this->assertEquals(1, $summary['failed_logins']);
        $this->assertEquals(2, $summary['unique_ips']);
    }

    public function testGetActiveSessionsSuccess(): void
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
            [
                'id' => 'current_session',
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Mozilla/5.0 Chrome',
                'created_at' => '2024-01-15 14:00:00',
                'last_activity' => '2024-01-15 14:30:00',
                'expires_at' => '2024-01-16 14:00:00'
            ],
            [
                'id' => 'other_session',
                'ip_address' => '192.168.1.2',
                'user_agent' => 'Mozilla/5.0 Firefox',
                'created_at' => '2024-01-14 10:00:00',
                'last_activity' => '2024-01-14 12:00:00',
                'expires_at' => '2024-01-15 10:00:00'
            ]
        ];

        $this->userRepository->expects($this->once())
            ->method('getActiveSessions')
            ->with(1)
            ->willReturn($sessions);

        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('active_sessions', 'read', 1, true, $this->anything());

        // Capture output
        ob_start();
        $this->controller->getActiveSessions();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('sessions', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);
        
        $sessionData = $response['data']['sessions'];
        $this->assertCount(2, $sessionData);
        
        // Find current session
        $currentSession = array_filter($sessionData, fn($s) => $s['is_current']);
        $this->assertCount(1, $currentSession);
        
        $summary = $response['data']['summary'];
        $this->assertEquals(2, $summary['total_sessions']);
        $this->assertEquals('current_session', $summary['current_session_id']);
        $this->assertEquals(2, $summary['unique_ips']);
    }

    public function testRevokeSessionSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;
        $_SESSION['session_id'] = 'current_session';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'session_management')
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

        $this->userRepository->expects($this->once())
            ->method('revokeSession')
            ->with('target_session')
            ->willReturn(true);

        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with(
                'session_revoked_dashboard',
                'Session revoked via security dashboard',
                1,
                $this->anything(),
                AuditLogger::SEVERITY_INFO,
                $this->callback(function($metadata) {
                    return $metadata['revoked_session_id'] === 'target_session' &&
                           $metadata['revocation_method'] === 'security_dashboard';
                })
            );

        // Capture output
        ob_start();
        $this->controller->revokeSession('target_session');
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals('target_session', $response['data']['revoked_session']['id']);
        $this->assertEquals('192.168.1.2', $response['data']['revoked_session']['ip_address']);
    }

    public function testRevokeCurrentSessionFails(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;
        $_SESSION['session_id'] = 'current_session';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'session_management')
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
        $this->controller->revokeSession('current_session');
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('CANNOT_REVOKE_CURRENT_SESSION', $response['code']);
    }

    public function testRevokeAllSessionsSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;
        $_SESSION['session_id'] = 'current_session';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'session_management')
            ->willReturn(['allowed' => true]);

        // Mock sessions before revoke
        $sessionsBeforeRevoke = [
            ['id' => 'current_session', 'ip_address' => '192.168.1.1'],
            ['id' => 'session2', 'ip_address' => '192.168.1.2'],
            ['id' => 'session3', 'ip_address' => '192.168.1.3']
        ];

        $this->userRepository->expects($this->once())
            ->method('getActiveSessions')
            ->with(1)
            ->willReturn($sessionsBeforeRevoke);

        $this->userRepository->expects($this->once())
            ->method('revokeAllSessionsExcept')
            ->with(1, 'current_session')
            ->willReturn(2);

        $this->auditLogger->expects($this->once())
            ->method('log')
            ->with(
                'all_sessions_revoked_dashboard',
                'Revoked 2 sessions via security dashboard',
                1,
                $this->anything(),
                AuditLogger::SEVERITY_WARNING,
                $this->callback(function($metadata) {
                    return $metadata['revoked_count'] === 2 &&
                           $metadata['current_session_id'] === 'current_session' &&
                           $metadata['revocation_method'] === 'security_dashboard';
                })
            );

        // Capture output
        ob_start();
        $this->controller->revokeAllSessions();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals(2, $response['data']['revoked_count']);
        $this->assertEquals('current_session', $response['data']['current_session_preserved']);
    }

    public function testGetSecurityEventsSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock GET parameters
        $_GET['page'] = '1';
        $_GET['limit'] = '20';
        $_GET['severity'] = 'warning';
        $_GET['days'] = '7';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        // Mock security events
        $securityEvents = [
            [
                'id' => 1,
                'event_type' => AuditLogger::EVENT_SUSPICIOUS_ACTIVITY,
                'event_category' => AuditLogger::CATEGORY_SECURITY,
                'event_description' => 'Suspicious login attempt',
                'severity' => AuditLogger::SEVERITY_WARNING,
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Unknown Agent',
                'created_at' => '2024-01-15 14:30:00',
                'metadata' => json_encode(['threat_level' => 'MEDIUM'])
            ],
            [
                'id' => 2,
                'event_type' => AuditLogger::EVENT_LOGIN_FAILED,
                'event_category' => AuditLogger::CATEGORY_AUTHENTICATION,
                'event_description' => 'Failed login attempt',
                'severity' => AuditLogger::SEVERITY_WARNING,
                'ip_address' => '192.168.1.101',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => '2024-01-15 13:15:00',
                'metadata' => json_encode(['threat_level' => 'LOW'])
            ]
        ];

        $this->auditLogger->expects($this->once())
            ->method('searchLogs')
            ->with($this->callback(function($criteria) {
                return $criteria['user_id'] === 1 &&
                       $criteria['severity'] === 'warning';
            }), 20)
            ->willReturn($securityEvents);

        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('security_events', 'read', 1, true, $this->anything());

        // Capture output
        ob_start();
        $this->controller->getSecurityEvents();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('events', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);
        
        $events = $response['data']['events'];
        $this->assertCount(2, $events);
        $this->assertEquals('MEDIUM', $events[0]['threat_level']);
        $this->assertEquals('LOW', $events[1]['threat_level']);
        
        $summary = $response['data']['summary'];
        $this->assertEquals(2, $summary['total_events']);
        $this->assertArrayHasKey('by_severity', $summary);
        $this->assertArrayHasKey('by_category', $summary);
    }

    public function testGetSecurityMetricsSuccess(): void
    {
        // Mock session data
        $_SESSION['user_id'] = 1;

        // Mock GET parameters
        $_GET['days'] = '30';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(1, RateLimiter::LIMIT_TYPE_USER, 'api_requests')
            ->willReturn(['allowed' => true]);

        $this->auditLogger->expects($this->once())
            ->method('logDataAccess')
            ->with('security_metrics', 'read', 1, true, $this->anything());

        // Capture output
        ob_start();
        $this->controller->getSecurityMetrics();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('metrics', $response['data']);
        $this->assertArrayHasKey('generated_at', $response['data']);
        $this->assertArrayHasKey('period', $response['data']);
        
        $metrics = $response['data']['metrics'];
        $this->assertArrayHasKey('account_security', $metrics);
        $this->assertArrayHasKey('login_analytics', $metrics);
        $this->assertArrayHasKey('session_analytics', $metrics);
        $this->assertArrayHasKey('threat_analysis', $metrics);
        $this->assertArrayHasKey('device_analysis', $metrics);
        $this->assertArrayHasKey('location_analysis', $metrics);
    }

    public function testUnauthenticatedAccess(): void
    {
        // No session data
        unset($_SESSION['user_id']);

        // Test dashboard access
        ob_start();
        $this->controller->getDashboard();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);
    }

    public function testRateLimitExceeded(): void
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
        $this->controller->getDashboard();
        $output = ob_get_clean();

        // Verify response
        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $response['code']);
    }

    protected function tearDown(): void
    {
        // Clean up session and GET data
        unset($_SESSION['user_id']);
        unset($_SESSION['session_id']);
        unset($_GET['page']);
        unset($_GET['limit']);
        unset($_GET['days']);
        unset($_GET['severity']);
    }
}