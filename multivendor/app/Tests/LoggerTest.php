<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\Logger;

class LoggerTest extends TestCase
{
    private Logger $logger;
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_logs';
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        
        $this->logger = new Logger();
        
        // Clean up any existing test logs
        if (is_dir($this->testLogPath)) {
            $this->cleanupTestLogs();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestLogs();
        putenv('LOG_PATH=');
        putenv('LOG_LEVEL=');
    }

    private function cleanupTestLogs(): void
    {
        $files = glob($this->testLogPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testLogPath)) {
            rmdir($this->testLogPath);
        }
    }

    public function testLoggerCreatesLogDirectory(): void
    {
        $this->assertTrue(is_dir($this->testLogPath));
    }

    public function testDebugLogging(): void
    {
        $message = 'Debug message';
        $context = ['key' => 'value'];

        $this->logger->debug($message, $context);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $this->assertTrue(file_exists($logFile));

        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('DEBUG', $logEntry['level']);
        $this->assertEquals($message, $logEntry['message']);
        $this->assertEquals($context, $logEntry['context']);
        $this->assertArrayHasKey('timestamp', $logEntry);
        $this->assertArrayHasKey('request_id', $logEntry);
    }

    public function testInfoLogging(): void
    {
        $message = 'Info message';
        $this->logger->info($message);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('INFO', $logEntry['level']);
        $this->assertEquals($message, $logEntry['message']);
    }

    public function testWarningLogging(): void
    {
        $message = 'Warning message';
        $this->logger->warning($message);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('WARNING', $logEntry['level']);
    }

    public function testErrorLogging(): void
    {
        $message = 'Error message';
        $this->logger->error($message);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('ERROR', $logEntry['level']);
    }

    public function testCriticalLogging(): void
    {
        $message = 'Critical message';
        $this->logger->critical($message);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('CRITICAL', $logEntry['level']);
    }

    public function testLogLevelFiltering(): void
    {
        // Set log level to ERROR
        putenv('LOG_LEVEL=ERROR');
        $logger = new Logger();

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            $logLines = array_filter(explode("\n", trim($logContent)));
            
            // Only ERROR level should be logged
            $this->assertCount(1, $logLines);
            
            $logEntry = json_decode($logLines[0], true);
            $this->assertEquals('ERROR', $logEntry['level']);
        }
    }

    public function testApiRequestLogging(): void
    {
        $method = 'POST';
        $endpoint = '/api/vendors';
        $params = ['name' => 'Test Vendor'];
        $userId = 'user123';

        $this->logger->logApiRequest($method, $endpoint, $params, $userId);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('INFO', $logEntry['level']);
        $this->assertEquals('API Request', $logEntry['message']);
        $this->assertEquals($method, $logEntry['context']['method']);
        $this->assertEquals($endpoint, $logEntry['context']['endpoint']);
        $this->assertEquals($params, $logEntry['context']['params']);
        $this->assertEquals($userId, $logEntry['context']['user_id']);
    }

    public function testApiResponseLogging(): void
    {
        $endpoint = '/api/vendors';
        $statusCode = 200;
        $executionTime = 0.123;
        $responseData = ['success' => true];

        $this->logger->logApiResponse($endpoint, $statusCode, $executionTime, $responseData);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('INFO', $logEntry['level']);
        $this->assertEquals('API Response', $logEntry['message']);
        $this->assertEquals($endpoint, $logEntry['context']['endpoint']);
        $this->assertEquals($statusCode, $logEntry['context']['status_code']);
        $this->assertEquals(123.0, $logEntry['context']['execution_time_ms']);
    }

    public function testDatabaseQueryLogging(): void
    {
        $query = 'SELECT * FROM vendors WHERE id = ?';
        $params = [123];
        $executionTime = 0.045;

        $this->logger->logDatabaseQuery($query, $params, $executionTime);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('DEBUG', $logEntry['level']);
        $this->assertEquals('Database Query', $logEntry['message']);
        $this->assertEquals($query, $logEntry['context']['query']);
        $this->assertEquals($params, $logEntry['context']['params']);
        $this->assertEquals(45.0, $logEntry['context']['execution_time_ms']);
    }

    public function testBusinessEventLogging(): void
    {
        $event = 'vendor_registered';
        $data = ['vendor_id' => 123, 'business_name' => 'Test Business'];

        $this->logger->logBusinessEvent($event, $data);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('INFO', $logEntry['level']);
        $this->assertEquals('Business Event', $logEntry['message']);
        $this->assertEquals($event, $logEntry['context']['event']);
        $this->assertEquals($data, $logEntry['context']['data']);
    }

    public function testSensitiveDataSanitization(): void
    {
        $params = [
            'username' => 'testuser',
            'password' => 'secret123',
            'token' => 'abc123',
            'api_key' => 'key456'
        ];

        $this->logger->logApiRequest('POST', '/login', $params);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('testuser', $logEntry['context']['params']['username']);
        $this->assertEquals('[REDACTED]', $logEntry['context']['params']['password']);
        $this->assertEquals('[REDACTED]', $logEntry['context']['params']['token']);
        $this->assertEquals('[REDACTED]', $logEntry['context']['params']['api_key']);
    }

    public function testRequestInformationLogging(): void
    {
        // Mock server variables
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/test';
        $_SERVER['HTTP_USER_AGENT'] = 'Test Agent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->logger->info('Test message');

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertArrayHasKey('request', $logEntry);
        $this->assertEquals('GET', $logEntry['request']['method']);
        $this->assertEquals('/api/test', $logEntry['request']['uri']);
        $this->assertEquals('Test Agent', $logEntry['request']['user_agent']);
        $this->assertEquals('127.0.0.1', $logEntry['request']['ip']);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], 
              $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
    }
}