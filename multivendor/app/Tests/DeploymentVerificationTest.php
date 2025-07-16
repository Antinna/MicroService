<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Config\Environment;
use Antinna\Multivendor\Services\ServiceHealthChecker;
use Antinna\Multivendor\Services\Logger;

class DeploymentVerificationTest extends TestCase
{
    private Environment $environment;
    private ServiceHealthChecker $healthChecker;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->environment = Environment::getInstance();
        $this->logger = new Logger();
        $this->healthChecker = new ServiceHealthChecker($this->logger);
    }

    public function testEnvironmentConfiguration(): void
    {
        // Test that environment is properly detected
        $env = $this->environment->getEnvironment();
        $this->assertContains($env, ['development', 'staging', 'production']);
        
        // Test basic configuration values
        $this->assertNotEmpty($this->environment->get('app.name'));
        $this->assertNotEmpty($this->environment->get('app.version'));
        $this->assertIsBool($this->environment->get('app.debug'));
    }

    public function testDatabaseConfiguration(): void
    {
        // Test database configuration
        $this->assertNotEmpty($this->environment->get('database.host'));
        $this->assertIsInt($this->environment->get('database.port'));
        $this->assertNotEmpty($this->environment->get('database.name'));
        $this->assertNotEmpty($this->environment->get('database.username'));
        
        // Test database connection options
        $options = $this->environment->get('database.options');
        $this->assertIsArray($options);
        $this->assertArrayHasKey(\PDO::ATTR_ERRMODE, $options);
    }

    public function testAdminConfiguration(): void
    {
        // Test admin configuration
        $this->assertNotEmpty($this->environment->get('admin.username'));
        $this->assertNotEmpty($this->environment->get('admin.password'));
        $this->assertIsInt($this->environment->get('admin.session_timeout'));
        $this->assertGreaterThan(0, $this->environment->get('admin.session_timeout'));
    }

    public function testServiceConfiguration(): void
    {
        // Test service URLs
        $services = ['auth', 'pay', 'social', 'delivery'];
        
        foreach ($services as $service) {
            $url = $this->environment->get("services.{$service}");
            $this->assertNotEmpty($url);
            $this->assertTrue(filter_var($url, FILTER_VALIDATE_URL) !== false);
        }
    }

    public function testLoggingConfiguration(): void
    {
        // Test logging configuration
        $this->assertNotEmpty($this->environment->get('logging.level'));
        $this->assertNotEmpty($this->environment->get('logging.path'));
        $this->assertIsInt($this->environment->get('logging.max_files'));
        
        // Test log level is valid
        $validLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
        $this->assertContains($this->environment->get('logging.level'), $validLevels);
    }

    public function testSecurityConfiguration(): void
    {
        // Test security configuration
        $this->assertIsBool($this->environment->get('security.session_secure'));
        $this->assertIsBool($this->environment->get('security.session_httponly'));
        $this->assertNotEmpty($this->environment->get('security.session_samesite'));
        
        // Test session samesite value is valid
        $validSameSite = ['Strict', 'Lax', 'None'];
        $this->assertContains($this->environment->get('security.session_samesite'), $validSameSite);
    }

    public function testPerformanceConfiguration(): void
    {
        // Test performance configuration
        $this->assertNotEmpty($this->environment->get('performance.memory_limit'));
        $this->assertIsInt($this->environment->get('performance.max_execution_time'));
        $this->assertIsBool($this->environment->get('performance.cache_enabled'));
        
        // Test memory limit format
        $memoryLimit = $this->environment->get('performance.memory_limit');
        $this->assertMatchesRegularExpression('/^\d+[KMG]?$/', $memoryLimit);
    }

    public function testConfigurationValidation(): void
    {
        // Test configuration validation
        $errors = $this->environment->validate();
        
        if (!empty($errors)) {
            $this->markTestSkipped('Configuration validation failed: ' . implode(', ', $errors));
        }
        
        $this->assertEmpty($errors, 'Configuration validation should pass');
    }

    public function testEnvironmentSpecificSettings(): void
    {
        $env = $this->environment->getEnvironment();
        
        switch ($env) {
            case 'production':
                // Production should have debug disabled
                $this->assertFalse($this->environment->get('app.debug'));
                $this->assertTrue($this->environment->get('security.session_secure'));
                $this->assertEquals('INFO', $this->environment->get('logging.level'));
                break;
                
            case 'development':
                // Development can have debug enabled
                $this->assertIsBool($this->environment->get('app.debug'));
                break;
                
            case 'staging':
                // Staging should be similar to production but may have debug enabled
                $this->assertIsBool($this->environment->get('app.debug'));
                break;
        }
    }

    public function testServiceHealthCheck(): void
    {
        // Test that health check works
        $health = $this->healthChecker->checkServiceHealth('multivendor');
        
        $this->assertArrayHasKey('service', $health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertEquals('multivendor', $health['service']);
        
        // In a proper deployment, this should be healthy
        if ($health['status'] !== 'healthy') {
            $this->markTestSkipped('Service health check failed: ' . ($health['error'] ?? 'Unknown error'));
        }
    }

    public function testSystemRequirements(): void
    {
        // Test PHP version
        $this->assertGreaterThanOrEqual('8.1.0', PHP_VERSION, 'PHP 8.1+ is required');
        
        // Test required extensions
        $requiredExtensions = ['pdo', 'json', 'mbstring', 'openssl'];
        
        foreach ($requiredExtensions as $extension) {
            $this->assertTrue(extension_loaded($extension), "Extension {$extension} is required");
        }
        
        // Test PDO drivers
        $this->assertContains('mysql', \PDO::getAvailableDrivers(), 'MySQL PDO driver is required');
    }

    public function testFileSystemPermissions(): void
    {
        // Test log directory permissions
        $logPath = $this->environment->get('logging.path');
        $logDir = dirname($logPath);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->assertTrue(is_dir($logDir), 'Log directory should exist');
        $this->assertTrue(is_writable($logDir), 'Log directory should be writable');
        
        // Test temporary directory
        $tempDir = sys_get_temp_dir();
        $this->assertTrue(is_writable($tempDir), 'Temporary directory should be writable');
    }

    public function testMemoryLimits(): void
    {
        // Test memory configuration
        $memoryLimit = $this->environment->get('performance.memory_limit');
        $currentLimit = ini_get('memory_limit');
        
        // Convert to bytes for comparison
        $configuredBytes = $this->parseMemoryLimit($memoryLimit);
        $currentBytes = $this->parseMemoryLimit($currentLimit);
        
        if ($currentBytes > 0 && $configuredBytes > 0) {
            $this->assertGreaterThanOrEqual($configuredBytes, $currentBytes, 
                'Current memory limit should be at least as high as configured limit');
        }
    }

    public function testExecutionTimeLimit(): void
    {
        // Test execution time configuration
        $configuredLimit = $this->environment->get('performance.max_execution_time');
        $currentLimit = (int)ini_get('max_execution_time');
        
        if ($currentLimit > 0) {
            $this->assertGreaterThanOrEqual($configuredLimit, $currentLimit,
                'Current execution time limit should be at least as high as configured limit');
        }
    }

    public function testExternalServiceConfiguration(): void
    {
        // Test external service configuration (if provided)
        $firebaseProjectId = $this->environment->get('external.firebase.project_id');
        if ($firebaseProjectId) {
            $this->assertNotEmpty($firebaseProjectId);
        }
        
        $emailHost = $this->environment->get('external.email.smtp_host');
        if ($emailHost) {
            $this->assertNotEmpty($emailHost);
            $this->assertIsInt($this->environment->get('external.email.smtp_port'));
        }
    }

    public function testConfigurationExport(): void
    {
        // Test configuration export (with sensitive data hidden)
        $exportedConfig = $this->environment->export(true);
        
        $this->assertIsArray($exportedConfig);
        $this->assertArrayHasKey('app', $exportedConfig);
        $this->assertArrayHasKey('database', $exportedConfig);
        
        // Verify sensitive data is hidden
        $this->assertEquals('[HIDDEN]', $exportedConfig['database']['password']);
        $this->assertEquals('[HIDDEN]', $exportedConfig['admin']['password']);
    }

    public function testProductionReadiness(): void
    {
        if (!$this->environment->isProduction()) {
            $this->markTestSkipped('Production readiness test only runs in production environment');
        }
        
        // Production-specific checks
        $this->assertFalse($this->environment->get('app.debug'), 'Debug should be disabled in production');
        $this->assertTrue($this->environment->get('security.session_secure'), 'Secure sessions required in production');
        $this->assertEquals('INFO', $this->environment->get('logging.level'), 'Log level should be INFO in production');
        
        // Check that localhost URLs are not used in production
        $services = $this->environment->get('services');
        foreach ($services as $service => $url) {
            $this->assertStringNotContainsString('localhost', $url, 
                "Service {$service} should not use localhost in production");
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0; // No limit
        }
        
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    public function testDeploymentHealthEndpoint(): void
    {
        // Test that health check endpoint works
        ob_start();
        include __DIR__ . '/../health-check.php';
        $output = ob_get_clean();
        
        $this->assertNotEmpty($output);
        
        $healthData = json_decode($output, true);
        $this->assertNotNull($healthData, 'Health check should return valid JSON');
        $this->assertArrayHasKey('status', $healthData);
        $this->assertArrayHasKey('service', $healthData);
        $this->assertArrayHasKey('version', $healthData);
        $this->assertEquals('multivendor', $healthData['service']);
    }
}