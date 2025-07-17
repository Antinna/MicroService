<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Database\Connection;
use PHPUnit\Framework\TestCase;
use PDO;

class AuditLoggerTest extends TestCase
{
    private AuditLogger $auditLogger;
    private PDO $db;
    private int $testUserId;

    protected function setUp(): void
    {
        $this->auditLogger = new AuditLogger();
        $this->db = Connection::getInstance()->getConnection();
        
        // Create test user
        $this->testUserId = $this->createTestUser();
        
        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    public function testBasicLogging(): void
    {
        $result = $this->auditLogger->log(
            AuditLogger::EVENT_LOGIN_SUCCESS,
            'User logged in successfully',
            $this->testUserId,
            '192.168.1.1',
            AuditLogger::SEVERITY_INFO,
            ['auth_method' => 'password']
        );

        $this->assertTrue($result);

        // Verify log was stored
        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertCount(1, $logs);
        $this->assertEquals(AuditLogger::EVENT_LOGIN_SUCCESS, $logs[0]['event_type']);
        $this->assertEquals('192.168.1.1', $logs[0]['ip_address']);
    }

    public function testAuthenticationLogging(): void
    {
        $result = $this->auditLogger->logAuthentication(
            AuditLogger::EVENT_LOGIN_SUCCESS,
            'Successful password authentication',
            $this->testUserId,
            'password',
            true,
            ['login_duration' => 1.5]
        );

        $this->assertTrue($result);

        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertCount(1, $logs);
        
        $metadata = json_decode($logs[0]['metadata'], true);
        $this->assertEquals('password', $metadata['auth_method']);
        $this->assertTrue($metadata['success']);
        $this->assertEquals(1.5, $metadata['login_duration']);
    }

    public function testSecurityIncidentLogging(): void
    {
        $result = $this->auditLogger->logSecurityIncident(
            AuditLogger::EVENT_SUSPICIOUS_ACTIVITY,
            'Multiple failed login attempts detected',
            $this->testUserId,
            AuditLogger::SEVERITY_CRITICAL,
            ['failed_attempts' => 5, 'time_window' => '5 minutes']
        );

        $this->assertTrue($result);

        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertCount(1, $logs);
        $this->assertEquals(AuditLogger::SEVERITY_CRITICAL, $logs[0]['severity']);
        
        $metadata = json_decode($logs[0]['metadata'], true);
        $this->assertArrayHasKey('incident_id', $metadata);
        $this->assertArrayHasKey('threat_level', $metadata);
        $this->assertEquals(5, $metadata['failed_attempts']);
    }

    public function testDataAccessLogging(): void
    {
        $result = $this->auditLogger->logDataAccess(
            'user_profile',
            'read',
            $this->testUserId,
            true,
            ['profile_id' => $this->testUserId]
        );

        $this->assertTrue($result);

        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertCount(1, $logs);
        
        $metadata = json_decode($logs[0]['metadata'], true);
        $this->assertEquals('user_profile', $metadata['resource']);
        $this->assertEquals('read', $metadata['action']);
        $this->assertTrue($metadata['authorized']);
    }

    public function testAdminActionLogging(): void
    {
        $adminUserId = $this->createTestUser('admin@example.com');
        $targetUserId = $this->testUserId;

        $result = $this->auditLogger->logAdminAction(
            'user_suspension',
            'User account suspended for policy violation',
            $adminUserId,
            $targetUserId,
            ['reason' => 'spam', 'duration' => '7 days']
        );

        $this->assertTrue($result);

        $logs = $this->auditLogger->getUserLogs($adminUserId, 1);
        $this->assertCount(1, $logs);
        $this->assertEquals(AuditLogger::EVENT_ADMIN_ACTION, $logs[0]['event_type']);
        
        $metadata = json_decode($logs[0]['metadata'], true);
        $this->assertEquals($adminUserId, $metadata['admin_user_id']);
        $this->assertEquals($targetUserId, $metadata['target_user_id']);
        $this->assertEquals('user_suspension', $metadata['action']);
    }

    public function testSystemEventLogging(): void
    {
        $result = $this->auditLogger->logSystemEvent(
            AuditLogger::EVENT_SYSTEM_ERROR,
            'Database connection timeout',
            AuditLogger::SEVERITY_ERROR,
            ['error_code' => 'DB_TIMEOUT', 'retry_count' => 3]
        );

        $this->assertTrue($result);

        $recentEvents = $this->auditLogger->getRecentSecurityEvents(10);
        $this->assertGreaterThan(0, count($recentEvents));
        
        $systemEvent = array_filter($recentEvents, fn($event) => $event['event_type'] === AuditLogger::EVENT_SYSTEM_ERROR);
        $this->assertCount(1, $systemEvent);
    }

    public function testBatchLogging(): void
    {
        $events = [
            [
                'event_type' => AuditLogger::EVENT_LOGIN_SUCCESS,
                'description' => 'User 1 logged in',
                'user_id' => $this->testUserId,
                'severity' => AuditLogger::SEVERITY_INFO
            ],
            [
                'event_type' => AuditLogger::EVENT_LOGIN_FAILED,
                'description' => 'Failed login attempt',
                'user_id' => $this->testUserId,
                'severity' => AuditLogger::SEVERITY_WARNING
            ],
            [
                'event_type' => AuditLogger::EVENT_LOGOUT,
                'description' => 'User logged out',
                'user_id' => $this->testUserId,
                'severity' => AuditLogger::SEVERITY_INFO
            ]
        ];

        $result = $this->auditLogger->logBatch($events);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['results']);

        $logs = $this->auditLogger->getUserLogs($this->testUserId, 10);
        $this->assertCount(3, $logs);
    }

    public function testSearchLogs(): void
    {
        // Create test logs
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_SUCCESS, 'Login 1', $this->testUserId, '192.168.1.1');
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_FAILED, 'Failed login', $this->testUserId, '192.168.1.2');
        $this->auditLogger->log(AuditLogger::EVENT_LOGOUT, 'Logout', $this->testUserId, '192.168.1.1');

        // Search by user ID
        $results = $this->auditLogger->searchLogs(['user_id' => $this->testUserId]);
        $this->assertCount(3, $results);

        // Search by event type
        $results = $this->auditLogger->searchLogs(['event_type' => AuditLogger::EVENT_LOGIN_SUCCESS]);
        $this->assertCount(1, $results);

        // Search by IP address
        $results = $this->auditLogger->searchLogs(['ip_address' => '192.168.1.1']);
        $this->assertCount(2, $results);

        // Search by severity
        $results = $this->auditLogger->searchLogs(['severity' => AuditLogger::SEVERITY_WARNING]);
        $this->assertCount(1, $results);
    }

    public function testAuditStatistics(): void
    {
        // Create test logs with different severities
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_SUCCESS, 'Login', $this->testUserId, 'auto', AuditLogger::SEVERITY_INFO);
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_FAILED, 'Failed', $this->testUserId, 'auto', AuditLogger::SEVERITY_WARNING);
        $this->auditLogger->log(AuditLogger::EVENT_SYSTEM_ERROR, 'Error', null, 'auto', AuditLogger::SEVERITY_ERROR);

        $stats = $this->auditLogger->getAuditStatistics('1 hour');
        $this->assertIsArray($stats);
        $this->assertGreaterThan(0, count($stats));
    }

    public function testSecurityDashboard(): void
    {
        // Create various test events
        $this->auditLogger->logAuthentication(AuditLogger::EVENT_LOGIN_SUCCESS, 'Success', $this->testUserId, 'password', true);
        $this->auditLogger->logAuthentication(AuditLogger::EVENT_LOGIN_FAILED, 'Failed', $this->testUserId, 'password', false);
        $this->auditLogger->logSecurityIncident(AuditLogger::EVENT_SUSPICIOUS_ACTIVITY, 'Suspicious', $this->testUserId);

        $dashboard = $this->auditLogger->getSecurityDashboard('1 hour');
        
        $this->assertArrayHasKey('timeframe', $dashboard);
        $this->assertArrayHasKey('severity_stats', $dashboard);
        $this->assertArrayHasKey('top_events', $dashboard);
        $this->assertArrayHasKey('suspicious_activities', $dashboard);
        $this->assertArrayHasKey('failed_logins', $dashboard);
        $this->assertArrayHasKey('system_health', $dashboard);
    }

    public function testExportLogs(): void
    {
        // Create test logs
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_SUCCESS, 'Test export', $this->testUserId);

        $criteria = ['user_id' => $this->testUserId];
        $export = $this->auditLogger->exportLogs($criteria, 'json');

        $this->assertTrue($export['success']);
        $this->assertEquals('json', $export['format']);
        $this->assertArrayHasKey('data', $export);
        $this->assertGreaterThan(0, $export['count']);
    }

    public function testAnomalyDetection(): void
    {
        // Create some test data that might trigger anomaly detection
        for ($i = 0; $i < 5; $i++) {
            $this->auditLogger->log(AuditLogger::EVENT_LOGIN_FAILED, 'Failed attempt', $this->testUserId, '192.168.1.100');
        }

        $anomalies = $this->auditLogger->detectAnomalies('1 hour');
        
        $this->assertArrayHasKey('timeframe', $anomalies);
        $this->assertArrayHasKey('anomalies_detected', $anomalies);
        $this->assertArrayHasKey('anomalies', $anomalies);
    }

    public function testComplianceReport(): void
    {
        // Create test data
        $this->auditLogger->logAuthentication(AuditLogger::EVENT_LOGIN_SUCCESS, 'Login', $this->testUserId, 'password', true);
        $this->auditLogger->logDataAccess('user_data', 'read', $this->testUserId, true);
        $this->auditLogger->logAdminAction('user_update', 'Updated user', $this->testUserId, $this->testUserId);

        $startDate = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $endDate = date('Y-m-d H:i:s');

        $report = $this->auditLogger->generateComplianceReport($startDate, $endDate);
        
        $this->assertArrayHasKey('report_period', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('security_metrics', $report);
        $this->assertArrayHasKey('top_users_by_activity', $report);
        $this->assertArrayHasKey('top_ip_addresses', $report);
    }

    public function testCleanupOldLogs(): void
    {
        // Create an old log entry by directly inserting into database
        $sql = "
            INSERT INTO audit_logs (user_id, event_type, event_description, ip_address, created_at)
            VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 100 DAY))
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId, 'old_event', 'Old log entry', '127.0.0.1']);

        // Create a recent log
        $this->auditLogger->log('recent_event', 'Recent log entry', $this->testUserId);

        // Clean up logs older than 90 days
        $deletedCount = $this->auditLogger->cleanupOldLogs(90);
        
        $this->assertEquals(1, $deletedCount);

        // Verify recent log still exists
        $logs = $this->auditLogger->getUserLogs($this->testUserId);
        $this->assertCount(1, $logs);
        $this->assertEquals('recent_event', $logs[0]['event_type']);
    }

    public function testEventCategoryDetermination(): void
    {
        // Test authentication events
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_SUCCESS, 'Login', $this->testUserId);
        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertEquals(AuditLogger::CATEGORY_AUTHENTICATION, $logs[0]['event_category']);

        // Test security events
        $this->auditLogger->log(AuditLogger::EVENT_SUSPICIOUS_ACTIVITY, 'Suspicious', $this->testUserId);
        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertEquals(AuditLogger::CATEGORY_SECURITY, $logs[0]['event_category']);

        // Test admin events
        $this->auditLogger->log(AuditLogger::EVENT_ADMIN_ACTION, 'Admin action', $this->testUserId);
        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $this->assertEquals(AuditLogger::CATEGORY_USER_MANAGEMENT, $logs[0]['event_category']);
    }

    public function testMetadataEnrichment(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/test';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $this->auditLogger->log('test_event', 'Test metadata enrichment', $this->testUserId);
        
        $logs = $this->auditLogger->getUserLogs($this->testUserId, 1);
        $metadata = json_decode($logs[0]['metadata'], true);
        
        $this->assertEquals('POST', $metadata['request_method']);
        $this->assertEquals('/api/test', $metadata['request_uri']);
        $this->assertEquals('example.com', $metadata['http_host']);
        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertArrayHasKey('php_sapi', $metadata);
    }

    public function testSeverityLevels(): void
    {
        $severityLevels = [
            AuditLogger::SEVERITY_DEBUG,
            AuditLogger::SEVERITY_INFO,
            AuditLogger::SEVERITY_NOTICE,
            AuditLogger::SEVERITY_WARNING,
            AuditLogger::SEVERITY_ERROR,
            AuditLogger::SEVERITY_CRITICAL,
            AuditLogger::SEVERITY_ALERT,
            AuditLogger::SEVERITY_EMERGENCY
        ];

        foreach ($severityLevels as $severity) {
            $result = $this->auditLogger->log('test_severity', "Testing $severity", $this->testUserId, 'auto', $severity);
            $this->assertTrue($result);
        }

        $logs = $this->auditLogger->getUserLogs($this->testUserId, 10);
        $this->assertCount(8, $logs);
    }

    private function createTestUser(string $email = 'audit-test@example.com'): int
    {
        $sql = "INSERT INTO users (email, password_hash, is_verified) VALUES (?, ?, 1)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email, password_hash('password', PASSWORD_DEFAULT)]);
        
        return (int)$this->db->lastInsertId();
    }

    private function cleanupTestData(): void
    {
        // Clean up audit logs
        $sql = "DELETE FROM audit_logs WHERE user_id = ? OR user_id IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        
        // Clean up test users
        $sql = "DELETE FROM users WHERE email LIKE '%audit-test%' OR email LIKE '%admin@example.com%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
    }
}