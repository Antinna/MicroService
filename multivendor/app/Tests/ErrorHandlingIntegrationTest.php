<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Helpers\ErrorHandlingHelper;
use Antinna\Multivendor\Services\VendorRegistrationService;
use Antinna\Multivendor\Exceptions\ValidationException;
use Antinna\Multivendor\Exceptions\ConflictException;

class ErrorHandlingIntegrationTest extends TestCase
{
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_error_logs';
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        putenv('APP_ENV=development');
        
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
        putenv('APP_ENV=');
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

    public function testCompleteErrorHandlingFlow(): void
    {
        $logger = ErrorHandlingHelper::getLogger();
        $errorHandler = ErrorHandlingHelper::getErrorHandler();

        // Test validation exception handling
        $validationErrors = ['email' => 'Invalid email format'];
        $validationException = new ValidationException($validationErrors);

        $response = $errorHandler->handleException($validationException);

        $this->assertTrue($response['error']);
        $this->assertEquals(400, $response['code']);
        $this->assertEquals('Validation failed', $response['message']);

        // Verify logging occurred
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $this->assertTrue(file_exists($logFile));

        $logContent = file_get_contents($logFile);
        $this->assertStringContains('ValidationException', $logContent);
        $this->assertStringContains('ERROR', $logContent);
    }

    public function testApiResponseHandling(): void
    {
        // Mock server variables for request logging
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/vendors';

        $testExecuted = false;

        ErrorHandlingHelper::handleApiResponse(function() use (&$testExecuted) {
            $testExecuted = true;
            return ['success' => true, 'message' => 'Operation completed'];
        });

        $this->assertTrue($testExecuted);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testApiResponseWithException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/vendors';

        // Capture output
        ob_start();

        ErrorHandlingHelper::handleApiResponse(function() {
            throw new ConflictException('Vendor', 'Email already exists');
        });

        $output = ob_get_clean();
        $response = json_decode($output, true);

        $this->assertTrue($response['error']);
        $this->assertEquals(409, $response['code']);
        $this->assertStringContains('already exists', $response['message']);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testLoggingMiddlewareIntegration(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/vendors/123';

        $result = ErrorHandlingHelper::withLogging(function() {
            return ['vendor_id' => 123, 'name' => 'Test Vendor'];
        });

        $this->assertEquals(['vendor_id' => 123, 'name' => 'Test Vendor'], $result);

        // Verify request/response logging
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $this->assertTrue(file_exists($logFile));

        $logContent = file_get_contents($logFile);
        $this->assertStringContains('API Request', $logContent);
        $this->assertStringContains('API Response', $logContent);
        $this->assertStringContains('/api/vendors/123', $logContent);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testBusinessEventLogging(): void
    {
        $logger = ErrorHandlingHelper::getLogger();

        $logger->logBusinessEvent('vendor_registered', [
            'vendor_id' => 123,
            'business_name' => 'Test Business',
            'registration_time' => time()
        ]);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $logEntry = json_decode(trim($logContent), true);

        $this->assertEquals('INFO', $logEntry['level']);
        $this->assertEquals('Business Event', $logEntry['message']);
        $this->assertEquals('vendor_registered', $logEntry['context']['event']);
        $this->assertEquals(123, $logEntry['context']['data']['vendor_id']);
    }

    public function testDatabaseErrorHandling(): void
    {
        $errorHandler = ErrorHandlingHelper::getErrorHandler();
        $dbException = new \PDOException('SQLSTATE[42S02]: Base table or view not found');

        $response = $errorHandler->handleDatabaseError($dbException);

        $this->assertTrue($response['error']);
        $this->assertEquals(500, $response['code']);
        $this->assertStringContains('SQLSTATE', $response['message']); // In development mode

        // Verify error logging
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        $this->assertStringContains('Database error occurred', $logContent);
        $this->assertStringContains('SQLSTATE', $logContent);
    }

    public function testProductionModeErrorHandling(): void
    {
        // Switch to production mode
        putenv('APP_ENV=production');

        $errorHandler = ErrorHandlingHelper::getErrorHandler();
        $exception = new \RuntimeException('Sensitive internal error details');

        $response = $errorHandler->handleException($exception);

        $this->assertTrue($response['error']);
        $this->assertEquals(500, $response['code']);
        $this->assertEquals('Internal Server Error - Something went wrong', $response['message']);
        $this->assertNull($response['details']); // No details in production

        // Switch back to development
        putenv('APP_ENV=development');
    }

    public function testSensitiveDataRedaction(): void
    {
        $logger = ErrorHandlingHelper::getLogger();

        $sensitiveData = [
            'username' => 'testuser',
            'password' => 'secret123',
            'api_key' => 'sk_test_123456',
            'token' => 'bearer_token_abc',
            'normal_field' => 'normal_value'
        ];

        $logger->logApiRequest('POST', '/api/login', $sensitiveData);

        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);

        $this->assertStringContains('testuser', $logContent);
        $this->assertStringContains('normal_value', $logContent);
        $this->assertStringContains('[REDACTED]', $logContent);
        $this->assertStringNotContains('secret123', $logContent);
        $this->assertStringNotContains('sk_test_123456', $logContent);
        $this->assertStringNotContains('bearer_token_abc', $logContent);
    }

    public function testMultipleErrorTypesHandling(): void
    {
        $errorHandler = ErrorHandlingHelper::getErrorHandler();

        // Test different exception types
        $exceptions = [
            new ValidationException(['field' => 'error']),
            new ConflictException('Resource'),
            new \InvalidArgumentException('Invalid argument'),
            new \RuntimeException('Runtime error')
        ];

        $expectedCodes = [400, 409, 500, 500];

        foreach ($exceptions as $index => $exception) {
            $response = $errorHandler->handleException($exception);
            
            $this->assertTrue($response['error']);
            $this->assertEquals($expectedCodes[$index], $response['code']);
            $this->assertArrayHasKey('timestamp', $response);
        }

        // Verify all errors were logged
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        
        $this->assertStringContains('ValidationException', $logContent);
        $this->assertStringContains('ConflictException', $logContent);
        $this->assertStringContains('InvalidArgumentException', $logContent);
        $this->assertStringContains('RuntimeException', $logContent);
    }
}