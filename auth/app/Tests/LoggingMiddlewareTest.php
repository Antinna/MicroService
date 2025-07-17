<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Middleware\LoggingMiddleware;
use Antinna\Auth\Services\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for LoggingMiddleware
 */
class LoggingMiddlewareTest extends TestCase
{
    private LoggingMiddleware $middleware;
    private MockObject $logger;
    private array $originalServer;

    protected function setUp(): void
    {
        // Backup original $_SERVER
        $this->originalServer = $_SERVER;
        
        // Set up test environment
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/auth/login';
        $_SERVER['HTTP_USER_AGENT'] = 'Test User Agent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        
        $this->middleware = new LoggingMiddleware();
        
        // Mock logger
        $this->logger = $this->createMock(Logger::class);
        
        // Use reflection to inject mock logger
        $reflection = new \ReflectionClass($this->middleware);
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($this->middleware, $this->logger);
    }

    public function testHandleRequestLogging(): void
    {
        // Mock logger expectations for request logging
        $this->logger->expects($this->once())
            ->method('logApiRequest')
            ->with(
                'POST',
                '/api/auth/login',
                $this->isType('array'),
                $this->isType('array'),
                $this->anything()
            );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    return $context['method'] === 'POST' &&
                           $context['uri'] === '/api/auth/login' &&
                           $context['ip_address'] === '127.0.0.1';
                }),
                Logger::CHANNEL_API
            );

        // Handle request
        $this->middleware->handleRequest();
    }

    public function testHandleResponseLogging(): void
    {
        $testResponse = json_encode(['success' => true, 'message' => 'Login successful']);
        
        // Mock logger expectations for response logging
        $this->logger->expects($this->once())
            ->method('logApiResponse')
            ->with(
                200,
                $this->isType('array'),
                $this->isType('float')
            );

        $this->logger->expects($this->once())
            ->method('log')
            ->with(
                Logger::LEVEL_INFO,
                'Outgoing Response',
                $this->callback(function($context) {
                    return $context['status_code'] === 200 &&
                           isset($context['execution_time']) &&
                           isset($context['content_length']);
                }),
                Logger::CHANNEL_API
            );

        // Set response code
        http_response_code(200);
        
        // Handle response
        $result = $this->middleware->handleResponse($testResponse);
        
        $this->assertEquals($testResponse, $result);
    }

    public function testSensitiveHeaderSanitization(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';
        $_SERVER['HTTP_COOKIE'] = 'session=abc123';
        $_SERVER['HTTP_X_API_KEY'] = 'api-key-secret';
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

        // Mock logger to verify sanitization
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    $headers = $context['headers'];
                    return $headers['authorization'] === '[REDACTED]' &&
                           $headers['cookie'] === '[REDACTED]' &&
                           $headers['x-api-key'] === '[REDACTED]' &&
                           $headers['content-type'] === 'application/json';
                }),
                Logger::CHANNEL_API
            );

        $this->middleware->handleRequest();
    }

    public function testRequestBodySanitization(): void
    {
        // Mock JSON input
        $jsonInput = json_encode([
            'email' => 'test@example.com',
            'password' => 'secret123',
            'remember_me' => true
        ]);

        // Create a stream for php://input
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $jsonInput);
        rewind($stream);

        // Mock logger to verify body sanitization
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    $body = $context['body'];
                    return $body['email'] === 'test@example.com' &&
                           $body['password'] === '[REDACTED]' &&
                           $body['remember_me'] === true;
                }),
                Logger::CHANNEL_API
            );

        $this->middleware->handleRequest();
    }

    public function testPerformanceLogging(): void
    {
        // Enable performance logging
        $_ENV['LOG_PERFORMANCE'] = true;
        $middleware = new LoggingMiddleware();
        
        // Inject mock logger
        $reflection = new \ReflectionClass($middleware);
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($middleware, $this->logger);

        // Mock logger expectations for performance logging
        $this->logger->expects($this->once())
            ->method('logPerformance')
            ->with(
                'POST /api/auth/login',
                $this->isType('float'),
                $this->isType('array')
            );

        // Set response code
        http_response_code(200);
        
        // Handle response (which triggers performance logging)
        $middleware->handleResponse('{"success": true}');
    }

    public function testSlowRequestWarning(): void
    {
        // Mock a slow request by manipulating start time
        $reflection = new \ReflectionClass($this->middleware);
        $startTimeProperty = $reflection->getProperty('startTime');
        $startTimeProperty->setAccessible(true);
        $startTimeProperty->setValue($this->middleware, microtime(true) - 3.0); // 3 seconds ago

        // Mock logger expectations for slow request warning
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Slow Request Detected',
                $this->callback(function($context) {
                    return $context['execution_time'] > 2.0;
                }),
                Logger::CHANNEL_PERFORMANCE
            );

        // Set response code
        http_response_code(200);
        
        // Handle response
        $this->middleware->handleResponse('{"success": true}');
    }

    public function testErrorLogging(): void
    {
        // Mock logger expectations for error logging
        $this->logger->expects($this->never())
            ->method('logApiRequest');

        // Mock error handler
        $errorHandler = $this->createMock(\Antinna\Auth\Services\ErrorHandler::class);
        $errorHandler->expects($this->once())
            ->method('handleApplicationError')
            ->with(
                'AUTH_001',
                $this->callback(function($context) {
                    return $context['request_method'] === 'POST' &&
                           $context['request_uri'] === '/api/auth/login' &&
                           $context['ip_address'] === '127.0.0.1';
                }),
                $this->isNull()
            );

        // Inject mock error handler
        $reflection = new \ReflectionClass($this->middleware);
        $errorHandlerProperty = $reflection->getProperty('errorHandler');
        $errorHandlerProperty->setAccessible(true);
        $errorHandlerProperty->setValue($this->middleware, $errorHandler);

        // Log error
        $this->middleware->logError('AUTH_001', ['additional' => 'context']);
    }

    public function testExcludedPathsNotLogged(): void
    {
        $_SERVER['REQUEST_URI'] = '/health';

        // Logger should not be called for excluded paths
        $this->logger->expects($this->never())
            ->method('logApiRequest');

        $this->logger->expects($this->never())
            ->method('info');

        $this->middleware->handleRequest();
    }

    public function testClientIpDetection(): void
    {
        // Test various IP headers
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.2, 192.168.1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // Mock logger to verify IP detection
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    // Should use Cloudflare IP first
                    return $context['ip_address'] === '203.0.113.1';
                }),
                Logger::CHANNEL_API
            );

        $this->middleware->handleRequest();
    }

    public function testRequestIdGeneration(): void
    {
        // Test without existing request ID
        unset($_SERVER['HTTP_X_REQUEST_ID']);

        // Mock logger to verify request ID is generated
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    return isset($context['request_id']) && 
                           strpos($context['request_id'], 'req_') === 0;
                }),
                Logger::CHANNEL_API
            );

        $this->middleware->handleRequest();
        
        // Verify request ID was set in $_SERVER
        $this->assertArrayHasKey('HTTP_X_REQUEST_ID', $_SERVER);
        $this->assertStringStartsWith('req_', $_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function testExistingRequestIdPreserved(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'existing-request-id';

        // Mock logger to verify existing request ID is preserved
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    return $context['request_id'] === 'existing-request-id';
                }),
                Logger::CHANNEL_API
            );

        $this->middleware->handleRequest();
        
        // Verify request ID was preserved
        $this->assertEquals('existing-request-id', $_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function testContextManagement(): void
    {
        // Add custom context
        $this->middleware->addContext('custom_key', 'custom_value');
        $this->middleware->addContext('user_role', 'admin');

        $context = $this->middleware->getContext();
        
        $this->assertEquals('custom_value', $context['custom_key']);
        $this->assertEquals('admin', $context['user_role']);
    }

    public function testExecutionTimeTracking(): void
    {
        // Wait a small amount to ensure execution time > 0
        usleep(1000); // 1ms
        
        $executionTime = $this->middleware->getExecutionTime();
        
        $this->assertGreaterThan(0, $executionTime);
        $this->assertLessThan(1, $executionTime); // Should be less than 1 second
    }

    public function testLargeBodyHandling(): void
    {
        // Create large JSON body (over 10KB limit)
        $largeData = str_repeat('x', 11000);
        $jsonInput = json_encode(['large_field' => $largeData]);

        // Create stream for large input
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $jsonInput);
        rewind($stream);

        // Mock logger to verify large body is handled
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Incoming Request',
                $this->callback(function($context) {
                    return $context['body']['raw'] === '[BODY_TOO_LARGE]';
                }),
                Logger::CHANNEL_API
            );

        $this->middleware->handleRequest();
    }

    protected function tearDown(): void
    {
        // Restore original $_SERVER
        $_SERVER = $this->originalServer;
        
        // Clean up environment variables
        if (isset($_ENV['LOG_PERFORMANCE'])) {
            unset($_ENV['LOG_PERFORMANCE']);
        }
        
        // Clean up any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
    }
}