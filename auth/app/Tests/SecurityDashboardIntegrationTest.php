<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\UserAuthenticator;
use Antinna\Auth\Services\SessionManager;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Controllers\SecurityDashboardController;
use Antinna\Auth\Routes\SecurityRoutes;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration test for Security Dashboard API
 */
class SecurityDashboardIntegrationTest extends TestCase
{
    private PDO $db;
    private UserRepository $userRepository;
    private UserAuthenticator $userAuthenticator;
    private SessionManager $sessionManager;
    private AuditLogger $auditLogger;
    private SecurityDashboardController $controller;
    private SecurityRoutes $routes;
    private int $testUserId;
    private array $testSessionIds = [];

    protected function setUp(): void
    {
        // Set up test database connection
        $this->db = Connection::getInstance()->getConnection();
        
        // Initialize services
        $this->userRepository = new UserRepository();
        $this->userAuthenticator = new UserAuthenticator();
        $this->sessionManager = new SessionManager();
        $this->auditLogger = new AuditLogger();
        $this->controller = new SecurityDashboardController();
        $this->routes = new SecurityRoutes();

        // Create test user and sessions
        $this->createTestUser();
        $this->createTestSessions();
        $this->createTestAuditLogs();
        
        // Start session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function testCompleteSecurityDashboardFlow(): void
    {
        // 1. Authenticate user
        $this->authenticateTestUser();

        // 2. Test security dashboard overview
        $this->testSecurityDashboard();

        // 3. Test login history
        $this->testLoginHistory();

        // 4. Test active sessions
        $this->testActiveSessionsManagement();

        // 5. Test security events
        $this->testSecurityEvents();

        // 6. Test security metrics
        $this->testSecurityMetrics();

        // 7. Test session revocation
        $this->testSessionRevocation();

        // 8. Test routes integration
        $this->testRoutesIntegration();
    }

    private function createTestUser(): void
    {
        $userData = [
            'email' => 'security.test@example.com',
            'phone' => '+1234567892',
            'password_hash' => password_hash('secure_password', PASSWORD_DEFAULT),
            'name' => 'Security Test User',
            'display_name' => 'Security Test',
            'role' => 'user',
            'is_active' => true,
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'last_login' => date('Y-m-d H:i:s', strtotime('-1 hour'))
        ];

        $this->testUserId = $this->userRepository->create($userData);
        $this->assertGreaterThan(0, $this->testUserId, 'Test user should be created successfully');

        // Set security settings
        $securitySettings = [
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'notification_on_login' => true,
            'notification_on_password_change' => true,
            'notification_on_suspicious_activity' => true
        ];
        $this->userRepository->updateSecuritySettings($this->testUserId, $securitySettings);
    }

    private function createTestSessions(): void
    {
        // Create multiple test sessions
        $sessions = [
            ['ip' => '192.168.1.1', 'agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/91.0'],
            ['ip' => '192.168.1.2', 'agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Safari/14.1'],
            ['ip' => '10.0.0.1', 'agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Firefox/89.0']
        ];

        foreach ($sessions as $sessionData) {
            $result = $this->sessionManager->createSession(
                $this->testUserId,
                $sessionData['ip'],
                $sessionData['agent']
            );
            
            if ($result['success']) {
                $this->testSessionIds[] = $result['session_id'];
            }
        }

        $this->assertGreaterThan(0, count($this->testSessionIds), 'Test sessions should be created');
    }

    private function createTestAuditLogs(): void
    {
        // Create various audit log entries for testing
        $events = [
            [
                'type' => AuditLogger::EVENT_LOGIN_SUCCESS,
                'description' => 'Successful login',
                'severity' => AuditLogger::SEVERITY_INFO,
                'ip' => '192.168.1.1',
                'metadata' => ['auth_method' => 'password']
            ],
            [
                'type' => AuditLogger::EVENT_LOGIN_FAILED,
                'description' => 'Failed login attempt',
                'severity' => AuditLogger::SEVERITY_WARNING,
                'ip' => '192.168.1.100',
                'metadata' => ['auth_method' => 'password', 'reason' => 'invalid_password']
            ],
            [
                'type' => AuditLogger::EVENT_SUSPICIOUS_ACTIVITY,
                'description' => 'Suspicious login pattern detected',
                'severity' => AuditLogger::SEVERITY_WARNING,
                'ip' => '192.168.1.100',
                'metadata' => ['threat_level' => 'MEDIUM', 'pattern' => 'multiple_failed_attempts']
            ],
            [
                'type' => AuditLogger::EVENT_PASSWORD_CHANGE,
                'description' => 'Password changed successfully',
                'severity' => AuditLogger::SEVERITY_INFO,
                'ip' => '192.168.1.1',
                'metadata' => ['change_method' => 'user_initiated']
            ],
            [
                'type' => AuditLogger::EVENT_MFA_ENABLED,
                'description' => 'MFA enabled for account',
                'severity' => AuditLogger::SEVERITY_INFO,
                'ip' => '192.168.1.1',
                'metadata' => ['mfa_method' => 'totp']
            ]
        ];

        foreach ($events as $event) {
            $this->auditLogger->log(
                $event['type'],
                $event['description'],
                $this->testUserId,
                $event['ip'],
                $event['severity'],
                $event['metadata']
            );
        }
    }

    private function authenticateTestUser(): void
    {
        // Simulate user authentication
        $authResult = $this->userAuthenticator->authenticateWithPassword(
            'security.test@example.com',
            'secure_password'
        );

        $this->assertTrue($authResult['success'], 'User authentication should succeed');
        
        // Set session data (use first test session as current)
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['session_id'] = $this->testSessionIds[0];
    }

    private function testSecurityDashboard(): void
    {
        // Test security dashboard overview
        ob_start();
        $this->controller->getDashboard();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Security dashboard should load successfully');
        $this->assertArrayHasKey('user_info', $response['data']);
        $this->assertArrayHasKey('login_history', $response['data']);
        $this->assertArrayHasKey('active_sessions', $response['data']);
        $this->assertArrayHasKey('security_events', $response['data']);
        $this->assertArrayHasKey('security_metrics', $response['data']);
        $this->assertArrayHasKey('threat_analysis', $response['data']);

        // Verify user info
        $userInfo = $response['data']['user_info'];
        $this->assertEquals($this->testUserId, $userInfo['user_id']);
        $this->assertEquals('security.test@example.com', $userInfo['email']);
        $this->assertTrue($userInfo['mfa_enabled']);
        $this->assertEquals('totp', $userInfo['mfa_method']);
        $this->assertTrue($userInfo['email_verified']);
        $this->assertGreaterThan(50, $userInfo['security_score']); // Should have good security score
    }

    private function testLoginHistory(): void
    {
        // Set query parameters
        $_GET['page'] = '1';
        $_GET['limit'] = '10';
        $_GET['days'] = '30';

        ob_start();
        $this->controller->getLoginHistory();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Login history should load successfully');
        $this->assertArrayHasKey('login_history', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);

        $history = $response['data']['login_history'];
        $this->assertNotEmpty($history, 'Should have login history entries');

        // Verify login history structure
        foreach ($history as $login) {
            $this->assertArrayHasKey('success', $login);
            $this->assertArrayHasKey('ip_address', $login);
            $this->assertArrayHasKey('location', $login);
            $this->assertArrayHasKey('device_info', $login);
            $this->assertArrayHasKey('auth_method', $login);
            $this->assertArrayHasKey('risk_score', $login);
            $this->assertArrayHasKey('created_at', $login);
        }

        // Verify summary
        $summary = $response['data']['summary'];
        $this->assertGreaterThan(0, $summary['total_logins']);
        $this->assertGreaterThan(0, $summary['successful_logins']);
        $this->assertArrayHasKey('failed_logins', $summary);
        $this->assertArrayHasKey('unique_ips', $summary);
    }

    private function testActiveSessionsManagement(): void
    {
        ob_start();
        $this->controller->getActiveSessions();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Active sessions should load successfully');
        $this->assertArrayHasKey('sessions', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);

        $sessions = $response['data']['sessions'];
        $this->assertGreaterThanOrEqual(3, count($sessions), 'Should have at least 3 test sessions');

        // Verify session structure
        foreach ($sessions as $session) {
            $this->assertArrayHasKey('id', $session);
            $this->assertArrayHasKey('ip_address', $session);
            $this->assertArrayHasKey('location', $session);
            $this->assertArrayHasKey('device_info', $session);
            $this->assertArrayHasKey('created_at', $session);
            $this->assertArrayHasKey('last_activity', $session);
            $this->assertArrayHasKey('is_current', $session);
            $this->assertArrayHasKey('risk_score', $session);
            $this->assertArrayHasKey('activity_summary', $session);
        }

        // Verify current session is marked
        $currentSessions = array_filter($sessions, fn($s) => $s['is_current']);
        $this->assertCount(1, $currentSessions, 'Should have exactly one current session');

        // Verify summary
        $summary = $response['data']['summary'];
        $this->assertGreaterThanOrEqual(3, $summary['total_sessions']);
        $this->assertEquals($this->testSessionIds[0], $summary['current_session_id']);
        $this->assertGreaterThan(0, $summary['unique_ips']);
    }

    private function testSecurityEvents(): void
    {
        // Set query parameters
        $_GET['page'] = '1';
        $_GET['limit'] = '20';
        $_GET['days'] = '30';

        ob_start();
        $this->controller->getSecurityEvents();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Security events should load successfully');
        $this->assertArrayHasKey('events', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);

        $events = $response['data']['events'];
        $this->assertNotEmpty($events, 'Should have security events');

        // Verify event structure
        foreach ($events as $event) {
            $this->assertArrayHasKey('event_type', $event);
            $this->assertArrayHasKey('event_category', $event);
            $this->assertArrayHasKey('description', $event);
            $this->assertArrayHasKey('severity', $event);
            $this->assertArrayHasKey('ip_address', $event);
            $this->assertArrayHasKey('location', $event);
            $this->assertArrayHasKey('threat_level', $event);
            $this->assertArrayHasKey('created_at', $event);
        }

        // Verify summary
        $summary = $response['data']['summary'];
        $this->assertGreaterThan(0, $summary['total_events']);
        $this->assertArrayHasKey('by_severity', $summary);
        $this->assertArrayHasKey('by_category', $summary);
    }

    private function testSecurityMetrics(): void
    {
        // Set query parameters
        $_GET['days'] = '30';

        ob_start();
        $this->controller->getSecurityMetrics();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Security metrics should load successfully');
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

        // Verify period information
        $period = $response['data']['period'];
        $this->assertEquals(30, $period['days']);
        $this->assertArrayHasKey('from', $period);
        $this->assertArrayHasKey('to', $period);
    }

    private function testSessionRevocation(): void
    {
        // Get a session to revoke (not the current one)
        $sessionToRevoke = null;
        foreach ($this->testSessionIds as $sessionId) {
            if ($sessionId !== $_SESSION['session_id']) {
                $sessionToRevoke = $sessionId;
                break;
            }
        }

        $this->assertNotNull($sessionToRevoke, 'Should have a session to revoke');

        // Test revoking specific session
        ob_start();
        $this->controller->revokeSession($sessionToRevoke);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Session revocation should succeed');
        $this->assertEquals($sessionToRevoke, $response['data']['revoked_session']['id']);

        // Verify session was actually revoked
        $revokedSession = $this->userRepository->getSessionById($sessionToRevoke);
        $this->assertNull($revokedSession, 'Revoked session should not be found in active sessions');

        // Test revoking all other sessions
        ob_start();
        $this->controller->revokeAllSessions();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Revoke all sessions should succeed');
        $this->assertGreaterThan(0, $response['data']['revoked_count']);
        $this->assertEquals($_SESSION['session_id'], $response['data']['current_session_preserved']);

        // Verify only current session remains
        $remainingSessions = $this->userRepository->getActiveSessions($this->testUserId);
        $this->assertCount(1, $remainingSessions, 'Should have only current session remaining');
        $this->assertEquals($_SESSION['session_id'], $remainingSessions[0]['id']);
    }

    private function testRoutesIntegration(): void
    {
        // Test dashboard route
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        $this->routes->handleRequest('GET', '/api/security/dashboard');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Dashboard route should work');

        // Test login history route
        ob_start();
        $this->routes->handleRequest('GET', '/api/security/login-history');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Login history route should work');

        // Test sessions route
        ob_start();
        $this->routes->handleRequest('GET', '/api/security/sessions');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Sessions route should work');

        // Test events route
        ob_start();
        $this->routes->handleRequest('GET', '/api/security/events');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Events route should work');

        // Test metrics route
        ob_start();
        $this->routes->handleRequest('GET', '/api/security/metrics');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Metrics route should work');

        // Test invalid route
        ob_start();
        $this->routes->handleRequest('GET', '/api/security/invalid');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Invalid route should return error');
        $this->assertEquals('NOT_FOUND', $response['code']);

        // Test method not allowed
        ob_start();
        $this->routes->handleRequest('POST', '/api/security/dashboard');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'POST to dashboard should not be allowed');
        $this->assertEquals('METHOD_NOT_ALLOWED', $response['code']);
    }

    public function testErrorHandling(): void
    {
        // Test unauthenticated access
        unset($_SESSION['user_id']);

        ob_start();
        $this->controller->getDashboard();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);

        // Test session not found for revocation
        $_SESSION['user_id'] = $this->testUserId;

        ob_start();
        $this->controller->revokeSession('nonexistent_session');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('SESSION_NOT_FOUND', $response['code']);

        // Test trying to revoke current session
        ob_start();
        $this->controller->revokeSession($_SESSION['session_id']);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('CANNOT_REVOKE_CURRENT_SESSION', $response['code']);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->testUserId) {
            // Delete audit logs
            $this->db->prepare("DELETE FROM audit_logs WHERE user_id = ?")->execute([$this->testUserId]);
            
            // Delete sessions
            $this->db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$this->testUserId]);
            
            // Delete user
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->testUserId]);
        }

        // Clean up session data
        session_destroy();
        unset($_SESSION);
        unset($_GET);
        unset($_SERVER['REQUEST_METHOD']);
    }
}