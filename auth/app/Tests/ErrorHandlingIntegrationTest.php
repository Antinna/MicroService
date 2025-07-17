<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\ErrorHandler;
use Antinna\Auth\Services\Logger;
use Antinna\Auth\Middleware\LoggingMiddleware;
use Antinna\Auth\Helpers\ErrorHandlingHelper;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for Error Handling and Logging System
 */
class ErrorHandlingIntegrationTest extends TestCase
{
    private string $testLogPath;
    private ErrorHandler $errorHandler;
    private Logger $logger;
    private LoggingMiddleware $middleware;

    protected function setUp(): void
    {
        // Create temporary log directory for testing
        $this->testLogPath = sys_get_temp_dir() . '/auth_error_test_logs_' . uniqid();
        mkdir($this->testLogPath, 0755, true);
        
        // Set environment variables for test
        $_ENV['LOG_PATH'] = $this->testLogPath;
        $_ENV['LOG_LEVEL'] = Logger::LEVEL_DEBUG;
        $_ENV['APP_DEBUG'] = true;
        
        // Initialize services
        $this->errorHandler = new ErrorHandler();
        $this->logger = new Logger();
        $this->middleware = new LoggingMiddleware();

        // Set up test server environment
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/auth/login';
        $_SERVER['HTTP_USER_AGENT'] = 'Test Integration Suite';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
    }

    public function testCompleteErrorHandlingWorkflow(): void
    {
        echo "Testing complete error handling and logging workflow...\n";

        // 1. Test request logging
        $this->testRequestLogging();

        // 2. Test error handling and logging
        $this->testErrorHandlingAndLogging();

        // 3. Test response logging
        $this->testResponseLogging();

        // 4. Test performance logging
        $this->testPerformanceLogging();

        // 5. Test security error handling
        $this->testSecurityErrorHandling();

        // 6. Test helper functions
        $this->testHelperFunctions();

        // 7. Test log analysis
        $this->testLogAnalysis();

        echo "✓ Complete error handling and logging workflow tested successfully\n";
    }

    private function testRequestLogging(): void
    {
        echo "  Testing request logging...\n";

        // Simulate incoming request
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        $_POST['email'] = 'test@example.com';
        $_POST['password'] = 'secret123';

        // Handle request
        $this->middleware->handleRequest();

        // Verify API log was created
        $apiLogFile = $this->testLogPath . '/api-' . date('Y-m-d') . '.log';
        $this->assertFileExists($apiLogFile);

        $logContent = file_get_contents($apiLogFile);
        $this->assertStringContains('Incoming Request', $logContent);
        $this->assertStringContains('POST', $logContent);
        $this->assertStringContains('/api/auth/login', $logContent);
        $this->assertStringContains('[REDACTED]', $logContent); // Password should be redacted

        echo "    ✓ Request logging\n";
    }

    private function testErrorHandlingAndLogging(): void
    {
        echo "  Testing error handling and logging...\n";

        // Test authentication error
        $result = $this->errorHandler->handleApplicationError('AUTH_001', [
            'email' => 'invalid@example.com',
            'ip_address' => '127.0.0.1',
            'attempt_count' => 3
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('AUTH_001', $result['error']['code']);
        $this->assertEquals('Invalid credentials', $result['error']['message']);

        // Verify error was logged
        $appLogFile = $this->testLogPath . '/application-' . date('Y-m-d') . '.log';
        $this->assertFileExists($appLogFile);

        $logContent = file_get_contents($appLogFile);
        $this->assertStringContains('APPLICATION_ERROR', $logContent);
        $this->assertStringContains('AUTH_001', $logContent);
        $this->assertStringContains('invalid@example.com', $logContent);

        echo "    ✓ Error handling and logging\n";

        // Test database error
        $dbException = new \PDOException('Connection failed', 2002);
        $dbResult = $this->errorHandler->handleApplicationError('DB_001', [
            'operation' => 'user_lookup',
            'table' => 'users'
        ], $dbException);

        $this->assertFalse($dbResult['success']);
        $this->assertEquals('DB_001', $dbResult['error']['code']);

        // Verify database error was logged
        $dbLogContent = file_get_contents($appLogFile);
        $this->assertStringContains('DB_001', $dbLogContent);
        $this->assertStringContains('user_lookup', $dbLogContent);

        echo "    ✓ Database error handling\n";
    }

    private function testResponseLogging(): void
    {
        echo "  Testing response logging...\n";

        $responseData = json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => 123,
                'email' => 'test@example.com'
            ]
        ]);

        // Set response code
        http_response_code(200);

        // Handle response
        $result = $this->middleware->handleResponse($responseData);
        $this->assertEquals($responseData, $result);

        // Verify response was logged
        $apiLogFile = $this->testLogPath . '/api-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($apiLogFile);
        
        $this->assertStringContains('Outgoing Response', $logContent);
        $this->assertStringContains('"status_code":200', $logContent);
        $this->assertStringContains('execution_time', $logContent);

        echo "    ✓ Response logging\n";
    }

    private function testPerformanceLogging(): void
    {
        echo "  Testing performance logging...\n";

        // Log performance metrics
        $this->logger->logPerformance(
            'user_authentication',
            1.5,
            [
                'db_queries' => 3,
                'cache_hits' => 2,
                'external_calls' => 1
            ]
        );

        // Verify performance log
        $perfLogFile = $this->testLogPath . '/performance-' . date('Y-m-d') . '.log';
        $this->assertFileExists($perfLogFile);

        $logContent = file_get_contents($perfLogFile);
        $this->assertStringContains('Performance: user_authentication', $logContent);
        $this->assertStringContains('"execution_time":1.5', $logContent);
        $this->assertStringContains('"db_queries":3', $logContent);

        echo "    ✓ Performance logging\n";

        // Test slow operation warning
        $this->logger->logPerformance('slow_operation', 6.0);
        
        $slowLogContent = file_get_contents($perfLogFile);
        $this->assertStringContains('WARNING.PERFORMANCE', $slowLogContent);

        echo "    ✓ Slow operation warning\n";
    }

    private function testSecurityErrorHandling(): void
    {
        echo "  Testing security error handling...\n";

        // Simulate security incident
        $_SESSION['user_id'] = 123;
        
        $securityResult = $this->errorHandler->handleApplicationError('SEC_001', [
            'violation_type' => 'brute_force_attempt',
            'ip_address' => '192.168.1.100',
            'failed_attempts' => 10,
            'time_window' => '5 minutes'
        ]);

        $this->assertFalse($securityResult['success']);
        $this->assertEquals('SEC_001', $securityResult['error']['code']);
        $this->assertEquals('security', $securityResult['error']['category']);

        // Verify security log
        $securityLogFile = $this->testLogPath . '/security-' . date('Y-m-d') . '.log';
        
        // Security events might be logged to application log as well
        $appLogFile = $this->testLogPath . '/application-' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($appLogFile);
        
        $this->assertStringContains('SEC_001', $logContent);
        $this->assertStringContains('brute_force_attempt', $logContent);

        echo "    ✓ Security error handling\n";

        // Clean up session
        unset($_SESSION['user_id']);
    }

    private function testHelperFunctions(): void
    {
        echo "  Testing helper functions...\n";

        // Test validation helpers
        try {
            ErrorHandlingHelper::validateRequired(['email' => 'test@example.com'], ['email', 'password']);
            $this->fail('Should have thrown validation error');
        } catch (\Exception $e) {
            // Expected - missing password field
        }

        // Test email validation
        try {
            ErrorHandlingHelper::validateEmail('invalid-email');
            $this->fail('Should have thrown email validation error');
        } catch (\Exception $e) {
            // Expected - invalid email format
        }

        // Test password validation
        try {
            ErrorHandlingHelper::validatePassword('weak');
            $this->fail('Should have thrown password validation error');
        } catch (\Exception $e) {
            // Expected - weak password
        }

        echo "    ✓ Validation helpers\n";

        // Test error response creation
        $errorResponse = ErrorHandlingHelper::createErrorResponse('VAL_001', [
            'field' => 'email',
            'value' => 'invalid'
        ]);

        $this->assertFalse($errorResponse['success']);
        $this->assertEquals('VAL_001', $errorResponse['error']['code']);

        // Test success response creation
        $successResponse = ErrorHandlingHelper::createSuccessResponse([
            'user_id' => 123
        ], 'User created successfully');

        $this->assertTrue($successResponse['success']);
        $this->assertEquals('User created successfully', $successResponse['message']);
        $this->assertEquals(123, $successResponse['data']['user_id']);

        echo "    ✓ Response helpers\n";
    }

    private function testLogAnalysis(): void
    {
        echo "  Testing log analysis...\n";

        // Get log statistics
        $stats = $this->logger->getLogStats();
        
        $this->assertArrayHasKey('application', $stats);
        $this->assertArrayHasKey('api', $stats);
        $this->assertArrayHasKey('performance', $stats);
        
        $this->assertGreaterThan(0, $stats['application']['file_size']);
        $this->assertGreaterThan(0, $stats['api']['file_size']);

        echo "    ✓ Log statistics\n";

        // Search logs
        $searchResults = $this->logger->searchLogs('AUTH_001');
        $this->assertNotEmpty($searchResults);
        $this->assertArrayHasKey('application', $searchResults);

        $loginResults = $this->logger->searchLogs('login');
        $this->assertNotEmpty($loginResults);

        echo "    ✓ Log search\n";

        // Test external service logging
        $this->logger->logExternalService('email_service', 'send_welcome_email', true, [
            'recipient' => 'test@example.com',
            'template' => 'welcome'
        ]);

        $this->logger->logExternalService('sms_service', 'send_verification', false, [
            'phone' => '+1234567890',
            'error' => 'Invalid phone number'
        ]);

        $extLogFile = $this->testLogPath . '/external-' . date('Y-m-d') . '.log';
        $this->assertFileExists($extLogFile);

        $extLogContent = file_get_contents($extLogFile);
        $this->assertStringContains('External Service Success: email_service', $extLogContent);
        $this->assertStringContains('External Service Failed: sms_service', $extLogContent);

        echo "    ✓ External service logging\n";
    }

    public function testErrorCodeCoverage(): void
    {
        echo "  Testing error code coverage...\n";

        $errorCodes = [
            'AUTH_001', 'AUTH_002', 'AUTH_010',
            'AUTHZ_001', 'AUTHZ_002',
            'VAL_001', 'VAL_002', 'VAL_006',
            'DB_001', 'DB_004', 'DB_005',
            'EXT_001', 'EXT_002', 'EXT_003',
            'SYS_001', 'SYS_002',
            'SEC_001', 'SEC_002'
        ];

        foreach ($errorCodes as $errorCode) {
            $this->assertTrue($this->errorHandler->hasErrorCode($errorCode), "Error code {$errorCode} should exist");
            
            $errorInfo = $this->errorHandler->getErrorInfo($errorCode);
            $this->assertNotNull($errorInfo, "Error info for {$errorCode} should not be null");
            $this->assertArrayHasKey('message', $errorInfo);
            $this->assertArrayHasKey('severity', $errorInfo);
            $this->assertArrayHasKey('category', $errorInfo);
        }

        echo "    ✓ Error code coverage\n";
    }

    public function testLogChannelSeparation(): void
    {
        echo "  Testing log channel separation...\n";

        // Log to different channels
        $this->logger->info('Application message', [], Logger::CHANNEL_APPLICATION);
        $this->logger->error('Security incident', [], Logger::CHANNEL_SECURITY);
        $this->logger->debug('Database query', [], Logger::CHANNEL_DATABASE);
        $this->logger->warning('External service timeout', [], Logger::CHANNEL_EXTERNAL);

        // Verify separate log files
        $channels = [
            Logger::CHANNEL_APPLICATION,
            Logger::CHANNEL_SECURITY,
            Logger::CHANNEL_DATABASE,
            Logger::CHANNEL_EXTERNAL
        ];

        foreach ($channels as $channel) {
            $logFile = $this->testLogPath . '/' . $channel . '-' . date('Y-m-d') . '.log';
            $this->assertFileExists($logFile, "Log file for channel {$channel} should exist");
            
            $content = file_get_contents($logFile);
            $this->assertStringContains(strtoupper($channel), $content);
        }

        echo "    ✓ Log channel separation\n";
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
        unset($_ENV['APP_DEBUG']);
        
        // Clean up server variables
        $_SERVER = [];
        
        // Clean up session
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
        
        // Clean up POST data
        $_POST = [];
        
        // Clean up any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
    }
}