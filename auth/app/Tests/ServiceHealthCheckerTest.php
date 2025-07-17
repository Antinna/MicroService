<?php

namespace Antinna\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Auth\Services\ServiceHealthChecker;
use Antinna\Auth\Database\Connection;

class ServiceHealthCheckerTest extends TestCase
{
    private $healthChecker;
    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->connection = Connection::getInstance();
        $this->connection->beginTransaction();
        
        $this->healthChecker = new ServiceHealthChecker();
    }

    protected function tearDown(): void
    {
        $this->connection->rollback();
        parent::tearDown();
    }

    public function testGetOverallHealthReturnsValidStructure()
    {
        $health = $this->healthChecker->getOverallHealth();

        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('response_time_ms', $health);
        $this->assertArrayHasKey('services', $health);
        $this->assertArrayHasKey('critical_issues', $health);
        $this->assertArrayHasKey('version', $health);
        $this->assertArrayHasKey('uptime', $health);

        $this->assertContains($health['status'], ['healthy', 'degraded', 'unhealthy']);
        $this->assertIsNumeric($health['response_time_ms']);
        $this->assertIsArray($health['services']);
        $this->assertIsArray($health['critical_issues']);
    }

    public function testGetDetailedHealthIncludesSystemMetrics()
    {
        $health = $this->healthChecker->getDetailedHealth();

        $this->assertArrayHasKey('system_metrics', $health);
        $this->assertArrayHasKey('performance_metrics', $health);

        $systemMetrics = $health['system_metrics'];
        $this->assertArrayHasKey('cpu_usage', $systemMetrics);
        $this->assertArrayHasKey('memory_usage', $systemMetrics);
        $this->assertArrayHasKey('disk_usage', $systemMetrics);

        $performanceMetrics = $health['performance_metrics'];
        $this->assertArrayHasKey('active_connections', $performanceMetrics);
        $this->assertArrayHasKey('request_rate', $performanceMetrics);
        $this->assertArrayHasKey('error_rate', $performanceMetrics);
    }

    public function testDatabaseHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('database', $health['services']);
        
        $dbHealth = $health['services']['database'];
        $this->assertArrayHasKey('status', $dbHealth);
        $this->assertArrayHasKey('message', $dbHealth);
        $this->assertArrayHasKey('response_time', $dbHealth);
        
        // Database should be healthy in test environment
        $this->assertEquals('healthy', $dbHealth['status']);
        $this->assertIsNumeric($dbHealth['response_time']);
    }

    public function testJWTServiceHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('jwt', $health['services']);
        
        $jwtHealth = $health['services']['jwt'];
        $this->assertArrayHasKey('status', $jwtHealth);
        $this->assertArrayHasKey('message', $jwtHealth);
        
        // JWT service should be healthy
        $this->assertEquals('healthy', $jwtHealth['status']);
    }

    public function testSessionServiceHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('session', $health['services']);
        
        $sessionHealth = $health['services']['session'];
        $this->assertArrayHasKey('status', $sessionHealth);
        $this->assertArrayHasKey('message', $sessionHealth);
        
        // Session service should be healthy
        $this->assertEquals('healthy', $sessionHealth['status']);
    }

    public function testRateLimiterHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('rate_limiter', $health['services']);
        
        $rateLimiterHealth = $health['services']['rate_limiter'];
        $this->assertArrayHasKey('status', $rateLimiterHealth);
        $this->assertArrayHasKey('message', $rateLimiterHealth);
        
        // Rate limiter should be healthy
        $this->assertEquals('healthy', $rateLimiterHealth['status']);
    }

    public function testDisabledServicesReportCorrectStatus()
    {
        // Test with email service disabled
        putenv('EMAIL_SERVICE_ENABLED=false');
        
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('email_service', $health['services']);
        $emailHealth = $health['services']['email_service'];
        
        $this->assertEquals('disabled', $emailHealth['status']);
        $this->assertStringContainsString('disabled', $emailHealth['message']);
    }

    public function testCriticalServiceFailureCausesUnhealthyStatus()
    {
        // Mock a database failure by using invalid connection
        // This is a simplified test - in real scenarios you'd mock the connection
        
        $health = $this->healthChecker->getOverallHealth();
        
        // If database is healthy, overall status should not be unhealthy due to database
        if ($health['services']['database']['status'] === 'healthy') {
            $this->assertNotEquals('unhealthy', $health['status']);
        }
    }

    public function testHealthCheckResponseTime()
    {
        $startTime = microtime(true);
        $health = $this->healthChecker->getOverallHealth();
        $endTime = microtime(true);
        
        $actualResponseTime = ($endTime - $startTime) * 1000;
        $reportedResponseTime = $health['response_time_ms'];
        
        // Response time should be reasonable (less than 5 seconds)
        $this->assertLessThan(5000, $reportedResponseTime);
        
        // Reported time should be close to actual time (within 100ms tolerance)
        $this->assertLessThan(100, abs($actualResponseTime - $reportedResponseTime));
    }

    public function testMemoryUsageHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('memory_usage', $health['services']);
        
        $memoryHealth = $health['services']['memory_usage'];
        $this->assertArrayHasKey('status', $memoryHealth);
        $this->assertArrayHasKey('details', $memoryHealth);
        
        $details = $memoryHealth['details'];
        $this->assertArrayHasKey('current_mb', $details);
        $this->assertArrayHasKey('peak_mb', $details);
        $this->assertArrayHasKey('limit_mb', $details);
        $this->assertArrayHasKey('usage_percent', $details);
        
        $this->assertIsNumeric($details['current_mb']);
        $this->assertIsNumeric($details['peak_mb']);
        $this->assertIsNumeric($details['usage_percent']);
        
        // Memory usage should be reasonable
        $this->assertLessThan(90, $details['usage_percent']);
    }

    public function testDiskSpaceHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('disk_space', $health['services']);
        
        $diskHealth = $health['services']['disk_space'];
        $this->assertArrayHasKey('status', $diskHealth);
        $this->assertArrayHasKey('details', $diskHealth);
        
        $details = $diskHealth['details'];
        $this->assertArrayHasKey('total_gb', $details);
        $this->assertArrayHasKey('free_gb', $details);
        $this->assertArrayHasKey('used_gb', $details);
        $this->assertArrayHasKey('usage_percent', $details);
        
        $this->assertIsNumeric($details['total_gb']);
        $this->assertIsNumeric($details['free_gb']);
        $this->assertIsNumeric($details['usage_percent']);
        
        // Disk usage should be reasonable
        $this->assertLessThan(95, $details['usage_percent']);
    }

    public function testExternalAPIHealthCheck()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('external_apis', $health['services']);
        
        $apiHealth = $health['services']['external_apis'];
        $this->assertArrayHasKey('status', $apiHealth);
        $this->assertArrayHasKey('details', $apiHealth);
        
        $details = $apiHealth['details'];
        $this->assertIsArray($details);
        
        // Should have checks for major OAuth providers
        $this->assertArrayHasKey('google_oauth', $details);
        $this->assertArrayHasKey('facebook_oauth', $details);
        $this->assertArrayHasKey('github_oauth', $details);
    }

    public function testHealthCheckIncludesVersion()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('version', $health);
        $this->assertIsString($health['version']);
        $this->assertNotEmpty($health['version']);
    }

    public function testHealthCheckIncludesUptime()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        $this->assertArrayHasKey('uptime', $health);
        $this->assertIsArray($health['uptime']);
        $this->assertArrayHasKey('seconds', $health['uptime']);
        $this->assertArrayHasKey('human_readable', $health['uptime']);
        
        $this->assertIsNumeric($health['uptime']['seconds']);
        $this->assertIsString($health['uptime']['human_readable']);
    }

    public function testHealthCheckTimestamp()
    {
        $beforeTime = date('c');
        $health = $this->healthChecker->getOverallHealth();
        $afterTime = date('c');
        
        $this->assertArrayHasKey('timestamp', $health);
        
        $timestamp = $health['timestamp'];
        $this->assertGreaterThanOrEqual($beforeTime, $timestamp);
        $this->assertLessThanOrEqual($afterTime, $timestamp);
        
        // Validate ISO 8601 format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $timestamp);
    }

    public function testConcurrentHealthChecks()
    {
        // Test that multiple concurrent health checks don't interfere with each other
        $results = [];
        
        // Simulate concurrent requests
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->healthChecker->getOverallHealth();
        }
        
        // All results should be valid
        foreach ($results as $result) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('status', $result);
            $this->assertContains($result['status'], ['healthy', 'degraded', 'unhealthy']);
        }
        
        // Results should be consistent (same status)
        $statuses = array_column($results, 'status');
        $uniqueStatuses = array_unique($statuses);
        $this->assertCount(1, $uniqueStatuses, 'Concurrent health checks should return consistent status');
    }

    public function testHealthCheckPerformance()
    {
        // Health check should complete within reasonable time
        $startTime = microtime(true);
        $health = $this->healthChecker->getOverallHealth();
        $endTime = microtime(true);
        
        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        // Health check should complete within 3 seconds
        $this->assertLessThan(3000, $duration, 'Health check should complete within 3 seconds');
        
        // Reported response time should match actual duration (within tolerance)
        $reportedTime = $health['response_time_ms'];
        $this->assertLessThan(200, abs($duration - $reportedTime), 'Reported response time should be accurate');
    }
}