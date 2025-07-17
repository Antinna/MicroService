<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\SecurityMonitor;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Database\Connection;
use PHPUnit\Framework\TestCase;
use PDO;

class SecurityMonitorTest extends TestCase
{
    private SecurityMonitor $securityMonitor;
    private AuditLogger $auditLogger;
    private PDO $db;
    private int $testUserId;

    protected function setUp(): void
    {
        $this->securityMonitor = new SecurityMonitor();
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

    public function testMonitorAuthenticationSuccess(): void
    {
        $eventData = [
            'user_id' => $this->testUserId,
            'ip_address' => '192.168.1.100',
            'auth_method' => 'password'
        ];

        $result = $this->securityMonitor->monitorAuthentication(
            AuditLogger::EVENT_LOGIN_SUCCESS,
            $eventData
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('alerts_generated', $result);
        $this->assertArrayHasKey('alerts', $result);
    }

    public function testBruteForceDetection(): void
    {
        $ipAddress = '192.168.1.200';
        
        // Simulate multiple failed login attempts
        for ($i = 0; $i < 6; $i++) {
            $this->auditLogger->log(
                AuditLogger::EVENT_LOGIN_FAILED,
                'Failed login attempt',
                $this->testUserId,
                $ipAddress,
                AuditLogger::SEVERITY_WARNING
            );
        }

        // Monitor the next failed attempt
        $eventData = [
            'user_id' => $this->testUserId,
            'ip_address' => $ipAddress,
            'auth_method' => 'password'
        ];

        $result = $this->securityMonitor->monitorAuthentication(
            AuditLogger::EVENT_LOGIN_FAILED,
            $eventData
        );

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['alerts_generated']);
        
        // Check if brute force alert was generated
        $bruteForceAlert = null;
        foreach ($result['alerts'] as $alert) {
            if ($alert['type'] === SecurityMonitor::ALERT_BRUTE_FORCE) {
                $bruteForceAlert = $alert;
                break;
            }
        }
        
        $this->assertNotNull($bruteForceAlert);
        $this->assertEquals(AuditLogger::SEVERITY_CRITICAL, $bruteForceAlert['severity']);
        $this->assertEquals($ipAddress, $bruteForceAlert['data']['ip_address']);
    }

    public function testSuspiciousLoginDetection(): void
    {
        $eventData = [
            'user_id' => $this->testUserId,
            'ip_address' => '10.0.0.1', // Different IP from usual
            'auth_method' => 'magic_link'
        ];

        $result = $this->securityMonitor->monitorAuthentication(
            AuditLogger::EVENT_LOGIN_SUCCESS,
            $eventData
        );

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['alerts']);
    }

    public function testSystemEventsMonitoring(): void
    {
        $result = $this->securityMonitor->monitorSystemEvents();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('alerts_generated', $result);
        $this->assertArrayHasKey('alerts', $result);
        $this->assertArrayHasKey('monitored_at', $result);
    }

    public function testSecurityDashboard(): void
    {
        $result = $this->securityMonitor->getSecurityDashboard();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('dashboard', $result);
        
        $dashboard = $result['dashboard'];
        $this->assertArrayHasKey('timestamp', $dashboard);
        $this->assertArrayHasKey('threat_level', $dashboard);
        $this->assertArrayHasKey('active_alerts', $dashboard);
        $this->assertArrayHasKey('recent_incidents', $dashboard);
        $this->assertArrayHasKey('security_metrics', $dashboard);
        $this->assertArrayHasKey('system_health', $dashboard);
        $this->assertArrayHasKey('threat_intelligence', $dashboard);
    }

    public function testIPAddressAnalysis(): void
    {
        $ipAddress = '192.168.1.100';
        
        // Create some test data for this IP
        $this->auditLogger->log(
            AuditLogger::EVENT_LOGIN_FAILED,
            'Failed login',
            $this->testUserId,
            $ipAddress
        );

        $result = $this->securityMonitor->analyzeIPAddress($ipAddress);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('analysis', $result);
        
        $analysis = $result['analysis'];
        $this->assertEquals($ipAddress, $analysis['ip_address']);
        $this->assertArrayHasKey('threat_score', $analysis);
        $this->assertArrayHasKey('threat_level', $analysis);
        $this->assertArrayHasKey('indicators', $analysis);
        $this->assertArrayHasKey('recommendations', $analysis);
    }

    public function testUserBehaviorMonitoring(): void
    {
        $result = $this->securityMonitor->monitorUserBehavior($this->testUserId);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('analysis', $result);
        
        $analysis = $result['analysis'];
        $this->assertEquals($this->testUserId, $analysis['user_id']);
        $this->assertArrayHasKey('risk_score', $analysis);
        $this->assertArrayHasKey('anomalies', $analysis);
        $this->assertArrayHasKey('behavioral_patterns', $analysis);
        $this->assertArrayHasKey('recommendations', $analysis);
    }

    public function testAlertGeneration(): void
    {
        $alertData = [
            'user_id' => $this->testUserId,
            'ip_address' => '192.168.1.100',
            'failed_attempts' => 5,
            'time_window' => 300
        ];

        $result = $this->securityMonitor->generateAlert(
            SecurityMonitor::ALERT_BRUTE_FORCE,
            $alertData
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('alert', $result);
        
        $alert = $result['alert'];
        $this->assertArrayHasKey('id', $alert);
        $this->assertEquals(SecurityMonitor::ALERT_BRUTE_FORCE, $alert['type']);
        $this->assertArrayHasKey('severity', $alert);
        $this->assertArrayHasKey('threat_level', $alert);
        $this->assertArrayHasKey('title', $alert);
        $this->assertArrayHasKey('description', $alert);
        $this->assertArrayHasKey('created_at', $alert);
        $this->assertEquals('active', $alert['status']);
    }

    public function testThreatLevelConstants(): void
    {
        $this->assertEquals('low', SecurityMonitor::THREAT_LEVEL_LOW);
        $this->assertEquals('medium', SecurityMonitor::THREAT_LEVEL_MEDIUM);
        $this->assertEquals('high', SecurityMonitor::THREAT_LEVEL_HIGH);
        $this->assertEquals('critical', SecurityMonitor::THREAT_LEVEL_CRITICAL);
    }

    public function testAlertTypeConstants(): void
    {
        $expectedAlertTypes = [
            'brute_force_attack',
            'suspicious_login',
            'account_takeover',
            'rate_limit_abuse',
            'unusual_activity',
            'system_anomaly',
            'data_breach_attempt',
            'privilege_escalation'
        ];

        $actualAlertTypes = [
            SecurityMonitor::ALERT_BRUTE_FORCE,
            SecurityMonitor::ALERT_SUSPICIOUS_LOGIN,
            SecurityMonitor::ALERT_ACCOUNT_TAKEOVER,
            SecurityMonitor::ALERT_RATE_LIMIT_ABUSE,
            SecurityMonitor::ALERT_UNUSUAL_ACTIVITY,
            SecurityMonitor::ALERT_SYSTEM_ANOMALY,
            SecurityMonitor::ALERT_DATA_BREACH,
            SecurityMonitor::ALERT_PRIVILEGE_ESCALATION
        ];

        $this->assertEquals($expectedAlertTypes, $actualAlertTypes);
    }

    public function testMultipleFailedLoginsFromDifferentIPs(): void
    {
        $ips = ['192.168.1.1', '192.168.1.2', '192.168.1.3'];
        
        // Create failed attempts from different IPs (should not trigger brute force)
        foreach ($ips as $ip) {
            for ($i = 0; $i < 3; $i++) {
                $this->auditLogger->log(
                    AuditLogger::EVENT_LOGIN_FAILED,
                    'Failed login',
                    $this->testUserId,
                    $ip
                );
            }
        }

        // Monitor authentication from one of the IPs
        $result = $this->securityMonitor->monitorAuthentication(
            AuditLogger::EVENT_LOGIN_FAILED,
            ['user_id' => $this->testUserId, 'ip_address' => $ips[0]]
        );

        $this->assertTrue($result['success']);
        // Should not generate brute force alert since attempts are distributed
        $this->assertEquals(0, $result['alerts_generated']);
    }

    public function testAccountTakeoverDetection(): void
    {
        // Simulate suspicious activity followed by password change
        $this->auditLogger->log(
            AuditLogger::EVENT_SUSPICIOUS_ACTIVITY,
            'Suspicious login pattern',
            $this->testUserId,
            '192.168.1.100',
            AuditLogger::SEVERITY_WARNING
        );

        // Monitor password change event
        $result = $this->securityMonitor->monitorAuthentication(
            AuditLogger::EVENT_PASSWORD_CHANGE,
            ['user_id' => $this->testUserId, 'ip_address' => '192.168.1.100']
        );

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['alerts']);
    }

    public function testErrorHandling(): void
    {
        // Test with invalid event data
        $result = $this->securityMonitor->monitorAuthentication(
            'invalid_event_type',
            []
        );

        $this->assertTrue($result['success']); // Should handle gracefully
    }

    public function testSecurityMetricsCalculation(): void
    {
        // Create various types of events
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_SUCCESS, 'Success', $this->testUserId);
        $this->auditLogger->log(AuditLogger::EVENT_LOGIN_FAILED, 'Failed', $this->testUserId);
        $this->auditLogger->log(AuditLogger::EVENT_SUSPICIOUS_ACTIVITY, 'Suspicious', $this->testUserId);

        $dashboard = $this->securityMonitor->getSecurityDashboard();
        
        $this->assertTrue($dashboard['success']);
        $this->assertIsArray($dashboard['dashboard']['security_metrics']);
    }

    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (email, password_hash, is_verified) VALUES (?, ?, 1)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['security-test@example.com', password_hash('password', PASSWORD_DEFAULT)]);
        
        return (int)$this->db->lastInsertId();
    }

    private function cleanupTestData(): void
    {
        // Clean up security alerts
        $sql = "DELETE FROM security_alerts WHERE alert_data LIKE '%security-test%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        // Clean up audit logs
        $sql = "DELETE FROM audit_logs WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        
        // Clean up test user
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
    }
}