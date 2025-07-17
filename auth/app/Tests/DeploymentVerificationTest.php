<?php

namespace Antinna\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Auth\Config\Environment;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\ServiceHealthChecker;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\SessionManager;

class DeploymentVerificationTest extends TestCase
{
    private $connection;
    private $healthChecker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Load environment configuration
        Environment::load();
        
        $this->connection = Connection::getInstance();
        $this->healthChecker = new ServiceHealthChecker();
    }

    public function testEnvironmentConfiguration()
    {
        // Test that all required environment variables are set
        $requiredVars = [
            'DB_HOST',
            'DB_PORT',
            'DB_NAME', 
            'DB_USERNAME',
            'DB_PASSWORD',
            'JWT_SECRET',
            'ENCRYPTION_KEY'
        ];

        foreach ($requiredVars as $var) {
            $this->assertNotEmpty(Environment::get($var), "Environment variable {$var} must be set");
        }

        // Test environment validation
        $errors = Environment::validate();
        $this->assertEmpty($errors, 'Environment validation should pass: ' . implode(', ', $errors));
    }

    public function testDatabaseConnectivity()
    {
        // Test basic database connection
        $this->assertInstanceOf(Connection::class, $this->connection);
        
        // Test database query execution
        $stmt = $this->connection->prepare("SELECT 1 as test");
        $stmt->execute();
        $result = $stmt->fetch();
        
        $this->assertEquals(1, $result['test'], 'Database should be accessible and responsive');
    }

    public function testDatabaseSchema()
    {
        // Test that required tables exist
        $requiredTables = [
            'users',
            'sessions', 
            'audit_logs',
            'passkeys',
            'social_accounts',
            'token_blacklist',
            'magic_links',
            'rate_limits',
            'security_alerts',
            'service_health_checks'
        ];

        foreach ($requiredTables as $table) {
            $stmt = $this->connection->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $result = $stmt->fetch();
            
            $this->assertNotFalse($result, "Required table '{$table}' should exist");
        }
    }

    public function testJWTConfiguration()
    {
        $jwtManager = new JWTManager();
        
        // Test JWT token generation
        $testPayload = [
            'user_id' => 12345,
            'email' => 'test@example.com',
            'exp' => time() + 3600
        ];
        
        $token = $jwtManager->generateToken($testPayload);
        $this->assertNotEmpty($token, 'JWT token should be generated');
        
        // Test JWT token validation
        $decoded = $jwtManager->validateToken($token);
        $this->assertIsArray($decoded, 'JWT token should be valid');
        $this->assertEquals(12345, $decoded['user_id'], 'JWT payload should be correctly decoded');
        $this->assertEquals('test@example.com', $decoded['email'], 'JWT payload should contain correct data');
    }

    public function testSessionManagement()
    {
        $sessionManager = new SessionManager($this->connection);
        
        // Test session creation
        $sessionId = 'deployment-test-' . uniqid();
        $sessionData = [
            'user_id' => 12345,
            'device_id' => 'test-device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Deployment Test'
        ];
        
        $created = $sessionManager->createSession($sessionId, $sessionData);
        $this->assertTrue($created, 'Session should be created successfully');
        
        // Test session retrieval
        $retrieved = $sessionManager->getSession($sessionId);
        $this->assertIsArray($retrieved, 'Session should be retrievable');
        $this->assertEquals(12345, $retrieved['user_id'], 'Session data should be correct');
        
        // Test session cleanup
        $destroyed = $sessionManager->destroySession($sessionId);
        $this->assertTrue($destroyed, 'Session should be destroyed successfully');
        
        // Verify session is gone
        $notFound = $sessionManager->getSession($sessionId);
        $this->assertNull($notFound, 'Destroyed session should not be retrievable');
    }

    public function testServiceHealthStatus()
    {
        $health = $this->healthChecker->getOverallHealth();
        
        // Test health check structure
        $this->assertIsArray($health, 'Health check should return array');
        $this->assertArrayHasKey('status', $health, 'Health check should include status');
        $this->assertArrayHasKey('services', $health, 'Health check should include services');
        $this->assertArrayHasKey('timestamp', $health, 'Health check should include timestamp');
        
        // Test overall health status
        $this->assertContains($health['status'], ['healthy', 'degraded', 'unhealthy'], 'Health status should be valid');
        
        // Critical services should be healthy for successful deployment
        $criticalServices = ['database', 'jwt', 'session'];
        foreach ($criticalServices as $service) {
            $this->assertArrayHasKey($service, $health['services'], "Critical service '{$service}' should be checked");
            $serviceHealth = $health['services'][$service];
            $this->assertEquals('healthy', $serviceHealth['status'], "Critical service '{$service}' should be healthy");
        }
    }

    public function testHealthCheckEndpoints()
    {
        $baseUrl = 'http://localhost:' . (Environment::get('PORT') ?: '8080');
        
        $endpoints = [
            '/health' => 'Basic health check',
            '/health/ready' => 'Readiness probe',
            '/health/live' => 'Liveness probe',
            '/health/info' => 'Service information'
        ];

        foreach ($endpoints as $endpoint => $description) {
            $response = $this->makeHttpRequest($baseUrl . $endpoint);
            
            $this->assertGreaterThanOrEqual(200, $response['status'], "{$description} should return successful status");
            $this->assertLessThan(400, $response['status'], "{$description} should not return error status");
            
            $data = json_decode($response['body'], true);
            $this->assertIsArray($data, "{$description} should return valid JSON");
        }
    }

    public function testSecurityConfiguration()
    {
        // Test JWT secret strength
        $jwtSecret = Environment::get('JWT_SECRET');
        $this->assertGreaterThanOrEqual(32, strlen($jwtSecret), 'JWT secret should be at least 32 characters');
        
        // Test encryption key
        $encryptionKey = Environment::get('ENCRYPTION_KEY');
        $this->assertEquals(32, strlen($encryptionKey), 'Encryption key should be exactly 32 characters');
        
        // Test password policy configuration
        $minLength = Environment::getInt('PASSWORD_MIN_LENGTH');
        $this->assertGreaterThanOrEqual(8, $minLength, 'Password minimum length should be at least 8');
        
        // Test rate limiting configuration
        $rateLimitEnabled = Environment::getBool('RATE_LIMIT_ENABLED');
        $this->assertTrue($rateLimitEnabled, 'Rate limiting should be enabled in production');
        
        $maxAttempts = Environment::getInt('RATE_LIMIT_MAX_ATTEMPTS');
        $this->assertGreaterThan(0, $maxAttempts, 'Rate limit max attempts should be positive');
        $this->assertLessThanOrEqual(100, $maxAttempts, 'Rate limit max attempts should be reasonable');
    }

    public function testFeatureFlags()
    {
        $features = Environment::getFeatureFlags();
        
        // Test that feature flags are properly configured
        $this->assertIsArray($features, 'Feature flags should be an array');
        
        // Test critical features
        $criticalFeatures = [
            'magic_link_enabled',
            'biometric_enabled', 
            'passkey_enabled',
            'social_auth_enabled'
        ];

        foreach ($criticalFeatures as $feature) {
            $this->assertArrayHasKey($feature, $features, "Feature flag '{$feature}' should be defined");
            $this->assertIsBool($features[$feature], "Feature flag '{$feature}' should be boolean");
        }
    }

    public function testExternalServiceConfiguration()
    {
        // Test email service configuration if enabled
        if (Environment::getBool('EMAIL_SERVICE_ENABLED')) {
            $this->assertNotEmpty(Environment::get('EMAIL_API_KEY'), 'Email API key should be configured');
            $this->assertNotEmpty(Environment::get('EMAIL_FROM_ADDRESS'), 'Email from address should be configured');
        }

        // Test SMS service configuration if enabled
        if (Environment::getBool('SMS_SERVICE_ENABLED')) {
            $this->assertNotEmpty(Environment::get('SMS_API_KEY'), 'SMS API key should be configured');
            $this->assertNotEmpty(Environment::get('SMS_FROM_NUMBER'), 'SMS from number should be configured');
        }

        // Test Firebase configuration if enabled
        if (Environment::getBool('FIREBASE_ENABLED')) {
            $this->assertNotEmpty(Environment::get('FIREBASE_PROJECT_ID'), 'Firebase project ID should be configured');
            $this->assertNotEmpty(Environment::get('FIREBASE_PRIVATE_KEY'), 'Firebase private key should be configured');
        }
    }

    public function testPerformanceConfiguration()
    {
        // Test memory limit
        $memoryLimit = Environment::get('MEMORY_LIMIT');
        $this->assertNotEmpty($memoryLimit, 'Memory limit should be configured');
        
        // Test execution time limit
        $maxExecutionTime = Environment::getInt('MAX_EXECUTION_TIME');
        $this->assertGreaterThan(0, $maxExecutionTime, 'Max execution time should be positive');
        $this->assertLessThanOrEqual(300, $maxExecutionTime, 'Max execution time should be reasonable');
        
        // Test database pool size
        $poolSize = Environment::getInt('DB_POOL_SIZE');
        $this->assertGreaterThan(0, $poolSize, 'Database pool size should be positive');
        $this->assertLessThanOrEqual(100, $poolSize, 'Database pool size should be reasonable');
    }

    public function testLoggingConfiguration()
    {
        // Test log level
        $logLevel = Environment::get('LOG_LEVEL');
        $validLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
        $this->assertContains($logLevel, $validLevels, 'Log level should be valid');
        
        // Test audit logging
        $auditEnabled = Environment::getBool('AUDIT_LOG_ENABLED');
        $this->assertTrue($auditEnabled, 'Audit logging should be enabled');
        
        // Test security monitoring
        $securityMonitoring = Environment::getBool('SECURITY_MONITORING_ENABLED');
        $this->assertTrue($securityMonitoring, 'Security monitoring should be enabled');
    }

    public function testCORSConfiguration()
    {
        $corsEnabled = Environment::getBool('CORS_ENABLED');
        $this->assertTrue($corsEnabled, 'CORS should be enabled');
        
        $allowedOrigins = Environment::get('CORS_ALLOWED_ORIGINS');
        $this->assertNotEmpty($allowedOrigins, 'CORS allowed origins should be configured');
        
        $allowedMethods = Environment::get('CORS_ALLOWED_METHODS');
        $this->assertNotEmpty($allowedMethods, 'CORS allowed methods should be configured');
        $this->assertStringContainsString('POST', $allowedMethods, 'CORS should allow POST requests');
        $this->assertStringContainsString('GET', $allowedMethods, 'CORS should allow GET requests');
    }

    public function testServiceMetadata()
    {
        $serviceInfo = Environment::getServiceInfo();
        
        $this->assertArrayHasKey('name', $serviceInfo, 'Service should have a name');
        $this->assertArrayHasKey('version', $serviceInfo, 'Service should have a version');
        $this->assertArrayHasKey('environment', $serviceInfo, 'Service should have an environment');
        
        $this->assertEquals('auth-service', $serviceInfo['name'], 'Service name should be correct');
        $this->assertNotEmpty($serviceInfo['version'], 'Service version should not be empty');
        $this->assertContains($serviceInfo['environment'], ['development', 'staging', 'production'], 'Environment should be valid');
    }

    public function testDatabaseMigrationStatus()
    {
        // Check if migration history table exists
        $stmt = $this->connection->prepare("SHOW TABLES LIKE 'migration_history'");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if ($result) {
            // Check that migrations have been run
            $stmt = $this->connection->prepare("SELECT COUNT(*) as count FROM migration_history");
            $stmt->execute();
            $result = $stmt->fetch();
            
            $this->assertGreaterThan(0, $result['count'], 'Database migrations should have been executed');
        }
    }

    public function testResourceLimits()
    {
        // Test current memory usage is reasonable
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(Environment::get('MEMORY_LIMIT'));
        
        $memoryUsagePercent = ($memoryUsage / $memoryLimit) * 100;
        $this->assertLessThan(80, $memoryUsagePercent, 'Memory usage should be less than 80% of limit');
        
        // Test execution time is reasonable
        $executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        $this->assertLessThan(10, $executionTime, 'Test execution should complete within 10 seconds');
    }

    private function makeHttpRequest(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => $error
            ];
        }
        
        return [
            'status' => $httpCode,
            'body' => $response,
            'error' => null
        ];
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }
}