<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\ServiceHealthChecker;
use Antinna\Multivendor\Services\Logger;

class ServiceHealthCheckerTest extends TestCase
{
    private ServiceHealthChecker $healthChecker;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->healthChecker = new ServiceHealthChecker($this->logger);
    }

    public function testServiceConfiguration(): void
    {
        $config = $this->healthChecker->getServiceConfiguration();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('auth', $config);
        $this->assertArrayHasKey('pay', $config);
        $this->assertArrayHasKey('social', $config);
        $this->assertArrayHasKey('delivery', $config);
        $this->assertArrayHasKey('multivendor', $config);
        
        // Check service structure
        foreach ($config as $serviceKey => $serviceConfig) {
            $this->assertArrayHasKey('name', $serviceConfig);
            $this->assertArrayHasKey('url', $serviceConfig);
            $this->assertArrayHasKey('health_endpoint', $serviceConfig);
            $this->assertArrayHasKey('timeout', $serviceConfig);
            $this->assertArrayHasKey('critical', $serviceConfig);
        }
    }

    public function testCheckLocalServiceHealth(): void
    {
        $health = $this->healthChecker->checkServiceHealth('multivendor');
        
        $this->assertEquals('multivendor', $health['service']);
        $this->assertEquals('Multivendor Service', $health['name']);
        $this->assertContains($health['status'], ['healthy', 'unhealthy']);
        $this->assertEquals('local', $health['url']);
        $this->assertArrayHasKey('checks', $health);
        $this->assertArrayHasKey('version', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('response_time', $health);
        
        // Check individual health checks
        $checks = $health['checks'];
        $this->assertArrayHasKey('php', $checks);
        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('filesystem', $checks);
        $this->assertArrayHasKey('memory', $checks);
        
        // PHP check should always be healthy
        $this->assertEquals('healthy', $checks['php']['status']);
        $this->assertEquals(PHP_VERSION, $checks['php']['version']);
    }

    public function testCheckUnknownService(): void
    {
        $health = $this->healthChecker->checkServiceHealth('unknown-service');
        
        $this->assertEquals('unknown-service', $health['service']);
        $this->assertEquals('unknown', $health['status']);
        $this->assertEquals('Service not configured', $health['error']);
        $this->assertArrayHasKey('timestamp', $health);
    }

    public function testCheckRemoteServiceHealth(): void
    {
        // This will fail since we don't have actual services running
        $health = $this->healthChecker->checkServiceHealth('auth');
        
        $this->assertEquals('auth', $health['service']);
        $this->assertEquals('Authentication Service', $health['name']);
        $this->assertEquals('unhealthy', $health['status']);
        $this->assertArrayHasKey('error', $health);
        $this->assertArrayHasKey('response_time', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertTrue($health['critical']);
    }

    public function testCheckAllServicesHealth(): void
    {
        $result = $this->healthChecker->checkAllServicesHealth();
        
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('summary', $result);
        
        $services = $result['services'];
        $this->assertCount(5, $services);
        $this->assertArrayHasKey('auth', $services);
        $this->assertArrayHasKey('pay', $services);
        $this->assertArrayHasKey('social', $services);
        $this->assertArrayHasKey('delivery', $services);
        $this->assertArrayHasKey('multivendor', $services);
        
        $summary = $result['summary'];
        $this->assertEquals(5, $summary['total_services']);
        $this->assertArrayHasKey('healthy_services', $summary);
        $this->assertArrayHasKey('unhealthy_services', $summary);
        $this->assertArrayHasKey('critical_services_down', $summary);
        $this->assertArrayHasKey('overall_status', $summary);
        $this->assertArrayHasKey('last_check', $summary);
        
        // At least multivendor should be healthy
        $this->assertGreaterThan(0, $summary['healthy_services']);
        
        // Overall status should be one of the expected values
        $this->assertContains($summary['overall_status'], ['healthy', 'degraded', 'critical']);
    }

    public function testHealthCaching(): void
    {
        // First call
        $health1 = $this->healthChecker->checkServiceHealth('multivendor', true);
        $timestamp1 = $health1['timestamp'];
        
        // Second call immediately after (should use cache)
        $health2 = $this->healthChecker->checkServiceHealth('multivendor', true);
        $timestamp2 = $health2['timestamp'];
        
        $this->assertEquals($timestamp1, $timestamp2);
        
        // Third call without cache
        $health3 = $this->healthChecker->checkServiceHealth('multivendor', false);
        $timestamp3 = $health3['timestamp'];
        
        $this->assertGreaterThanOrEqual($timestamp2, $timestamp3);
    }

    public function testClearCache(): void
    {
        // Make a call to populate cache
        $this->healthChecker->checkServiceHealth('multivendor', true);
        
        // Clear cache
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Health check cache cleared');
        
        $this->healthChecker->clearCache();
        
        // This should work without issues
        $this->assertTrue(true);
    }

    public function testSetCacheTimeout(): void
    {
        $this->healthChecker->setCacheTimeout(60);
        
        // This should work without issues
        $this->assertTrue(true);
    }

    public function testGetSystemInfo(): void
    {
        $systemInfo = $this->healthChecker->getSystemInfo();
        
        $this->assertArrayHasKey('php', $systemInfo);
        $this->assertArrayHasKey('server', $systemInfo);
        $this->assertArrayHasKey('memory', $systemInfo);
        $this->assertArrayHasKey('extensions', $systemInfo);
        
        // Check PHP info
        $phpInfo = $systemInfo['php'];
        $this->assertEquals(PHP_VERSION, $phpInfo['version']);
        $this->assertEquals(PHP_SAPI, $phpInfo['sapi']);
        $this->assertEquals(PHP_OS, $phpInfo['os']);
        
        // Check extensions
        $extensions = $systemInfo['extensions'];
        $this->assertArrayHasKey('pdo', $extensions);
        $this->assertArrayHasKey('json', $extensions);
        $this->assertTrue($extensions['pdo']); // PDO should be available
        $this->assertTrue($extensions['json']); // JSON should be available
    }

    public function testPhpHealthCheck(): void
    {
        $reflection = new \ReflectionClass($this->healthChecker);
        $method = $reflection->getMethod('checkPhpHealth');
        $method->setAccessible(true);
        
        $phpHealth = $method->invoke($this->healthChecker);
        
        $this->assertEquals('PHP Runtime', $phpHealth['name']);
        $this->assertEquals('healthy', $phpHealth['status']);
        $this->assertEquals(PHP_VERSION, $phpHealth['version']);
        $this->assertArrayHasKey('memory_limit', $phpHealth);
        $this->assertArrayHasKey('max_execution_time', $phpHealth);
    }

    public function testMemoryHealthCheck(): void
    {
        $reflection = new \ReflectionClass($this->healthChecker);
        $method = $reflection->getMethod('checkMemoryHealth');
        $method->setAccessible(true);
        
        $memoryHealth = $method->invoke($this->healthChecker);
        
        $this->assertEquals('Memory Usage', $memoryHealth['name']);
        $this->assertContains($memoryHealth['status'], ['healthy', 'warning', 'critical']);
        $this->assertArrayHasKey('usage', $memoryHealth);
        $this->assertArrayHasKey('limit', $memoryHealth);
        $this->assertArrayHasKey('percentage', $memoryHealth);
        $this->assertArrayHasKey('peak_usage', $memoryHealth);
        
        $this->assertIsFloat($memoryHealth['percentage']);
        $this->assertGreaterThanOrEqual(0, $memoryHealth['percentage']);
    }

    public function testFilesystemHealthCheck(): void
    {
        $reflection = new \ReflectionClass($this->healthChecker);
        $method = $reflection->getMethod('checkFilesystemHealth');
        $method->setAccessible(true);
        
        $filesystemHealth = $method->invoke($this->healthChecker);
        
        $this->assertEquals('Filesystem', $filesystemHealth['name']);
        $this->assertContains($filesystemHealth['status'], ['healthy', 'unhealthy']);
        $this->assertArrayHasKey('log_path', $filesystemHealth);
        
        if ($filesystemHealth['status'] === 'healthy') {
            $this->assertTrue($filesystemHealth['writable']);
            $this->assertArrayHasKey('free_space', $filesystemHealth);
        }
    }

    public function testFormatBytes(): void
    {
        $reflection = new \ReflectionClass($this->healthChecker);
        $method = $reflection->getMethod('formatBytes');
        $method->setAccessible(true);
        
        $testCases = [
            [0, '0 B'],
            [1024, '1 KB'],
            [1048576, '1 MB'],
            [1073741824, '1 GB'],
            [1536, '1.5 KB'],
            [2097152, '2 MB']
        ];
        
        foreach ($testCases as [$bytes, $expected]) {
            $result = $method->invoke($this->healthChecker, $bytes);
            $this->assertEquals($expected, $result);
        }
    }

    public function testParseMemoryLimit(): void
    {
        $reflection = new \ReflectionClass($this->healthChecker);
        $method = $reflection->getMethod('parseMemoryLimit');
        $method->setAccessible(true);
        
        $testCases = [
            ['-1', 0],
            ['128M', 128 * 1024 * 1024],
            ['1G', 1024 * 1024 * 1024],
            ['512K', 512 * 1024],
            ['256', 256]
        ];
        
        foreach ($testCases as [$limit, $expected]) {
            $result = $method->invoke($this->healthChecker, $limit);
            $this->assertEquals($expected, $result);
        }
    }

    public function testDetermineStatusFromResponse(): void
    {
        $reflection = new \ReflectionClass($this->healthChecker);
        $method = $reflection->getMethod('determineStatusFromResponse');
        $method->setAccessible(true);
        
        $testCases = [
            [['status' => 'healthy'], 'healthy'],
            [['status' => 'ok'], 'healthy'],
            [['status' => 'up'], 'healthy'],
            [['status' => 'running'], 'healthy'],
            [['status' => 'unhealthy'], 'unhealthy'],
            [['status' => 'down'], 'unhealthy'],
            [['status' => 'error'], 'unhealthy'],
            [['status' => 'failed'], 'unhealthy'],
            [['message' => 'all good'], 'healthy'], // No explicit status
            [[], 'healthy'] // Empty response
        ];
        
        foreach ($testCases as [$response, $expected]) {
            $result = $method->invoke($this->healthChecker, $response);
            $this->assertEquals($expected, $result);
        }
    }

    public function testEnvironmentServiceUrls(): void
    {
        putenv('AUTH_SERVICE_URL=http://custom-auth.example.com');
        putenv('PAY_SERVICE_URL=http://custom-pay.example.com');
        
        $customHealthChecker = new ServiceHealthChecker($this->logger);
        $config = $customHealthChecker->getServiceConfiguration();
        
        $this->assertEquals('http://custom-auth.example.com', $config['auth']['url']);
        $this->assertEquals('http://custom-pay.example.com', $config['pay']['url']);
        
        // Clean up
        putenv('AUTH_SERVICE_URL=');
        putenv('PAY_SERVICE_URL=');
    }

    public function testCriticalServiceFlags(): void
    {
        $config = $this->healthChecker->getServiceConfiguration();
        
        // These services should be marked as critical
        $this->assertTrue($config['auth']['critical']);
        $this->assertTrue($config['pay']['critical']);
        $this->assertTrue($config['delivery']['critical']);
        $this->assertTrue($config['multivendor']['critical']);
        
        // Social service should not be critical
        $this->assertFalse($config['social']['critical']);
    }

    public function testResponseTimeTracking(): void
    {
        $health = $this->healthChecker->checkServiceHealth('multivendor');
        
        $this->assertArrayHasKey('response_time', $health);
        $this->assertIsFloat($health['response_time']);
        $this->assertGreaterThan(0, $health['response_time']);
    }

    public function testLoggingIntegration(): void
    {
        // This will trigger a warning log for unhealthy remote service
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Service health check failed');
        
        $this->healthChecker->checkServiceHealth('auth');
    }
}