<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Middleware\LoggingMiddleware;
use Antinna\Multivendor\Services\Logger;

class LoggingMiddlewareTest extends TestCase
{
    private LoggingMiddleware $middleware;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->middleware = new LoggingMiddleware($this->logger);
    }

    public function testSuccessfulRequestLogging(): void
    {
        // Mock server variables
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/vendors';

        $expectedResponse = ['success' => true, 'data' => []];

        // Expect API request logging
        $this->logger->expects($this->once())
            ->method('logApiRequest')
            ->with('GET', '/api/vendors', []);

        // Expect API response logging
        $this->logger->expects($this->once())
            ->method('logApiResponse')
            ->with('/api/vendors', 200, $this->isType('float'), $expectedResponse);

        $next = function() use ($expectedResponse) {
            return $expectedResponse;
        };

        $result = $this->middleware->handle($next);

        $this->assertEquals($expectedResponse, $result);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testExceptionHandling(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/vendors';

        $exception = new \RuntimeException('Test exception');

        // Expect API request logging
        $this->logger->expects($this->once())
            ->method('logApiRequest')
            ->with('POST', '/api/vendors', []);

        // Expect API response logging for error
        $this->logger->expects($this->once())
            ->method('logApiResponse')
            ->with('/api/vendors', 500, $this->isType('float'));

        $next = function() use ($exception) {
            throw $exception;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $this->middleware->handle($next);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testRequestParameterExtraction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/vendors';
        $_GET = ['filter' => 'active'];
        $_POST = ['name' => 'Test Vendor'];

        $expectedParams = [
            'query' => ['filter' => 'active'],
            'post' => ['name' => 'Test Vendor']
        ];

        $this->logger->expects($this->once())
            ->method('logApiRequest')
            ->with('POST', '/api/vendors', $expectedParams);

        $this->logger->expects($this->once())
            ->method('logApiResponse');

        $next = function() {
            return ['success' => true];
        };

        $this->middleware->handle($next);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_GET, $_POST);
    }

    public function testJsonBodyExtraction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/api/vendors/123';

        // Mock JSON input
        $jsonData = ['name' => 'Updated Vendor', 'status' => 'active'];
        $jsonInput = json_encode($jsonData);

        // We can't easily mock file_get_contents('php://input') in unit tests
        // This would be better tested in integration tests
        $this->logger->expects($this->once())
            ->method('logApiRequest')
            ->with('PUT', '/api/vendors/123', []);

        $this->logger->expects($this->once())
            ->method('logApiResponse');

        $next = function() {
            return ['success' => true];
        };

        $this->middleware->handle($next);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testExecutionTimeTracking(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/slow-endpoint';

        $this->logger->expects($this->once())
            ->method('logApiRequest');

        $this->logger->expects($this->once())
            ->method('logApiResponse')
            ->with(
                '/api/slow-endpoint',
                200,
                $this->callback(function($executionTime) {
                    // Execution time should be a positive float
                    return is_float($executionTime) && $executionTime > 0;
                }),
                ['result' => 'slow operation complete']
            );

        $next = function() {
            // Simulate some processing time
            usleep(1000); // 1ms
            return ['result' => 'slow operation complete'];
        };

        $this->middleware->handle($next);

        // Clean up
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testMissingServerVariables(): void
    {
        // Test with minimal server variables
        $this->logger->expects($this->once())
            ->method('logApiRequest')
            ->with('UNKNOWN', '', []);

        $this->logger->expects($this->once())
            ->method('logApiResponse')
            ->with('', 200, $this->isType('float'), ['success' => true]);

        $next = function() {
            return ['success' => true];
        };

        $this->middleware->handle($next);
    }
}