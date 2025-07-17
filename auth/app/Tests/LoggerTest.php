<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for Logger
 */
class LoggerTest extends TestCase
{
    private Logger $logger;
    private string $testLogPath;

    protected function setUp(): void
    {
        // Create temporary log directory for testing
        $this->testLogPath = sys_get_temp_dir() . '/auth_test_logs_' . uniqid();
        mkdir($this->testLogPath, 0755, true);
        
        // Set environment variable for test
        $_ENV['LOG_PATH'] = $this->testLogPath;
        $_ENV['LOG_LEVEL'] = Logger::LEVEL_DEBUG;
        
        $this->logger = new Logger();
    }

    public function testBasicLogging(): void
    {
        // Test info level logging
        $this->logger->info('Test info message', ['key' => 'value']);
        
        $logFile = $this->testLogPath . '/application-' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContains('INFO.APPLICATION: Test info message', $logContent);
        $this->assertStringContains('"key":"value"', $logContent);
    }

    public function testAllLogLevels(): void
    {
        $testMessage = 'Test message';
        $context = ['test' => true];

        // Test all log levels
        $this->logger->emergency($testMessage, $context);
        $this->logger->alert($testMessage, $context);
        $this->logger->critical($testMessage, $context);
        $this->logger->error($testMessage, $context);
        $this->logger->warning($testMessage, $context);
        $this->logger->notice($testMessage, $context);
        $this->logger->info($testMessage, $context);
        $this->logger->debug($testMessage, $context);

        $logFile = $this->testLogPath . '/application-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);

        // Verify all levels are logged
        $this->assertStringContains('EMERGENCY.APPLICATION', $logContent);
        $this->assertStringContains('ALERT.APPLICATION', $logContent);
        $this->assertStringContains('CRITICAL.APPLICATION', $logContent);
        $this->assertStringContains('ERROR.APPLICATION', $logContent);
        $this->assertStringContains('WARNING.APPLICATION', $logContent);
        $this->assertStringContains('NOTICE.APPLICATION', $logContent);
        $this->assertStringContains('INFO.APPLICATION', $logContent);
        $this->assertStringContains('DEBUG.APPLICATION', $logContent);
    }

    public function testChannelLogging(): void
    {
        // Test different channels
        $this->logger->info('API message', [], Logger::CHANNEL_API);
        $this->logger->error('Security message', [], Logger::CHANNEL_SECURITY);
        $this->logger->debug('Database message', [], Logger::CHANNEL_DATABASE);

        // Check API channel log
        $apiLogFile = $this->testLogPath . '/api-' . date('Y-m-d') . '.log';
        $this->assertFileExists($apiLogFile);
        $apiContent = file_get_contents($apiLogFile);
        $this->assertStringContains('INFO.API: API message', $apiContent);

        // Check Security channel log
        $securityLogFile = $this->testLogPath . '/security-' . date('Y-m-d') . '.log';
        $this->assertFileExists($securityLogFile);
        $securityContent = file_get_contents($securityLogFile);
        $this->assertStringContains('ERROR.SECURITY: Security message', $securityContent);

        // Check Database channel log
        $dbLogFile = $this->testLogPath . '/database-' . date('Y-m-d') . '.log';
        $this->assertFileExists($dbLogFile);
        $dbContent = file_get_contents($dbLogFile);
        $this->assertStringContains('DEBUG.DATABASE: Database message', $dbContent);
    }

    public function testApiRequestLogging(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'test-request-123';
        
        $this->logger->logApiRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            ['email' => 'test@example.com'],
            123
        );

        $apiLogFile = $this->testLogPath . '/api-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($apiLogFile);
        
        $this->assertStringContains('API Request: POST /api/auth/login', $logContent);
        $this->assertStringContains('test-request-123', $logContent);
        $this->assertStringContains('"user_id":123', $logContent);
    }

    public function testApiResponseLogging(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'test-request-456';
        
        $this->logger->logApiResponse(200, ['success' => true], 0.5);

        $apiLogFile = $this->testLogPath . '/api-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($apiLogFile);
        
        $this->assertStringContains('API Response: 200', $logContent);
        $this->assertStringContains('"execution_time":0.5', $logContent);
        $this->assertStringContains('test-request-456', $logContent);
    }

    public function testDatabaseQueryLogging(): void
    {
        $this->logger->logDatabaseQuery(
            'SELECT * FROM users WHERE email = ?',
            ['test@example.com'],
            0.025,
            null
        );

        $dbLogFile = $this->testLogPath . '/database-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($dbLogFile);
        
        $this->assertStringContains('Database Query Executed', $logContent);
        $this->assertStringContains('"execution_time":0.025', $logContent);
        $this->assertStringContains('SELECT * FROM users', $logContent);
    }

    public function testDatabaseErrorLogging(): void
    {
        $this->logger->logDatabaseQuery(
            'SELECT * FROM invalid_table',
            [],
            null,
            'Table does not exist'
        );

        $dbLogFile = $this->testLogPath . '/database-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($dbLogFile);
        
        $this->assertStringContains('Database Error: Table does not exist', $logContent);
        $this->assertStringContains('ERROR.DATABASE', $logContent);
    }

    public function testPerformanceLogging(): void
    {
        $this->logger->logPerformance(
            'user_authentication',
            2.5,
            ['cache_hits' => 3, 'db_queries' => 2]
        );

        $perfLogFile = $this->testLogPath . '/performance-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($perfLogFile);
        
        $this->assertStringContains('Performance: user_authentication', $logContent);
        $this->assertStringContains('"execution_time":2.5', $logContent);
        $this->assertStringContains('"cache_hits":3', $logContent);
    }

    public function testSlowPerformanceWarning(): void
    {
        // Test slow operation (> 5 seconds)
        $this->logger->logPerformance('slow_operation', 6.0);

        $perfLogFile = $this->testLogPath . '/performance-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($perfLogFile);
        
        $this->assertStringContains('WARNING.PERFORMANCE', $logContent);
        $this->assertStringContains('"execution_time":6', $logContent);
    }

    public function testExternalServiceLogging(): void
    {
        // Test successful external service call
        $this->logger->logExternalService(
            'email_service',
            'send_email',
            true,
            ['recipient' => 'test@example.com']
        );

        // Test failed external service call
        $this->logger->logExternalService(
            'sms_service',
            'send_sms',
            false,
            ['error' => 'Invalid phone number']
        );

        $extLogFile = $this->testLogPath . '/external-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($extLogFile);
        
        $this->assertStringContains('External Service Success: email_service', $logContent);
        $this->assertStringContains('External Service Failed: sms_service', $logContent);
        $this->assertStringContains('INFO.EXTERNAL', $logContent);
        $this->assertStringContains('ERROR.EXTERNAL', $logContent);
    }

    public function testLogLevelFiltering(): void
    {
        // Set log level to WARNING (should filter out INFO and DEBUG)
        $_ENV['LOG_LEVEL'] = Logger::LEVEL_WARNING;
        $logger = new Logger();

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');

        $logFile = $this->testLogPath . '/application-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);

        // Should not contain debug and info messages
        $this->assertStringNotContains('DEBUG.APPLICATION: Debug message', $logContent);
        $this->assertStringNotContains('INFO.APPLICATION: Info message', $logContent);
        
        // Should contain warning and error messages
        $this->assertStringContains('WARNING.APPLICATION: Warning message', $logContent);
        $this->assertStringContains('ERROR.APPLICATION: Error message', $logContent);
    }

    public function testLogStats(): void
    {
        // Write some log entries
        $this->logger->info('Test message 1');
        $this->logger->error('Test message 2');
        $this->logger->debug('Test message 3', [], Logger::CHANNEL_DATABASE);

        // Get log stats
        $stats = $this->logger->getLogStats();

        $this->assertArrayHasKey('application', $stats);
        $this->assertArrayHasKey('database', $stats);
        
        $this->assertGreaterThan(0, $stats['application']['file_size']);
        $this->assertGreaterThan(0, $stats['application']['line_count']);
        $this->assertGreaterThan(0, $stats['database']['file_size']);
        $this->assertEquals(1, $stats['database']['line_count']);
    }

    public function testLogSearch(): void
    {
        // Write test log entries
        $this->logger->info('User login successful', ['user_id' => 123]);
        $this->logger->error('Database connection failed');
        $this->logger->warning('Rate limit exceeded', ['ip' => '192.168.1.1']);

        // Search for specific patterns
        $loginResults = $this->logger->searchLogs('login');
        $this->assertNotEmpty($loginResults);
        $this->assertArrayHasKey('application', $loginResults);

        $errorResults = $this->logger->searchLogs('failed');
        $this->assertNotEmpty($errorResults);

        $ipResults = $this->logger->searchLogs('192.168.1.1');
        $this->assertNotEmpty($ipResults);
    }

    public function testSensitiveDataSanitization(): void
    {
        // Test API request with sensitive headers
        $this->logger->logApiRequest(
            'POST',
            '/api/auth/login',
            [
                'Authorization' => 'Bearer secret-token',
                'Cookie' => 'session=abc123',
                'Content-Type' => 'application/json'
            ],
            [
                'email' => 'test@example.com',
                'password' => 'secret-password'
            ]
        );

        $apiLogFile = $this->testLogPath . '/api-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($apiLogFile);

        // Sensitive data should be redacted
        $this->assertStringContains('[REDACTED]', $logContent);
        $this->assertStringNotContains('secret-token', $logContent);
        $this->assertStringNotContains('secret-password', $logContent);
        $this->assertStringNotContains('session=abc123', $logContent);
        
        // Non-sensitive data should be preserved
        $this->assertStringContains('application/json', $logContent);
        $this->assertStringContains('test@example.com', $logContent);
    }

    public function testContextSanitization(): void
    {
        $context = [
            'user_email' => 'test@example.com',
            'password' => 'secret123',
            'token' => 'abc-token',
            'api_key' => 'key-123',
            'normal_field' => 'normal_value',
            'nested' => [
                'secret' => 'nested-secret',
                'public' => 'public-value'
            ]
        ];

        $this->logger->info('Test with sensitive context', $context);

        $logFile = $this->testLogPath . '/application-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);

        // Sensitive fields should be redacted
        $this->assertStringContains('[REDACTED]', $logContent);
        $this->assertStringNotContains('secret123', $logContent);
        $this->assertStringNotContains('abc-token', $logContent);
        $this->assertStringNotContains('key-123', $logContent);
        $this->assertStringNotContains('nested-secret', $logContent);

        // Non-sensitive fields should be preserved
        $this->assertStringContains('test@example.com', $logContent);
        $this->assertStringContains('normal_value', $logContent);
        $this->assertStringContains('public-value', $logContent);
    }

    protected function tearDown(): void
    {
        // Clean up test log directory
        if (is_dir($this->testLogPath)) {
            $files = glob($this->testLogPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testLogPath);
        }

        // Clean up environment variables
        unset($_ENV['LOG_PATH']);
        unset($_ENV['LOG_LEVEL']);
        
        // Clean up server variables
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            unset($_SERVER['HTTP_X_REQUEST_ID']);
        }
    }
}