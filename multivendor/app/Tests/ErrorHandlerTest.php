<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\ErrorHandler;
use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Exceptions\ValidationException;
use Antinna\Multivendor\Exceptions\NotFoundException;
use Antinna\Multivendor\Exceptions\UnauthorizedException;
use Antinna\Multivendor\Exceptions\ConflictException;

class ErrorHandlerTest extends TestCase
{
    private ErrorHandler $errorHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->errorHandler = new ErrorHandler($this->logger);
    }

    public function testHandleValidationException(): void
    {
        $errors = ['field1' => 'Required field', 'field2' => 'Invalid format'];
        $exception = new ValidationException($errors);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Exception occurred', $this->callback(function ($context) {
                return $context['exception'] === 'ValidationException' &&
                       $context['http_code'] === 400;
            }));

        $result = $this->errorHandler->handleException($exception);

        $this->assertTrue($result['error']);
        $this->assertEquals(400, $result['code']);
        $this->assertEquals('Validation failed', $result['message']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    public function testHandleNotFoundException(): void
    {
        $exception = new NotFoundException('User', '123');

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->errorHandler->handleException($exception);

        $this->assertTrue($result['error']);
        $this->assertEquals(404, $result['code']);
        $this->assertStringContains('not found', $result['message']);
    }

    public function testHandleUnauthorizedException(): void
    {
        $exception = new UnauthorizedException();

        $result = $this->errorHandler->handleException($exception);

        $this->assertTrue($result['error']);
        $this->assertEquals(401, $result['code']);
    }

    public function testHandleConflictException(): void
    {
        $exception = new ConflictException('Email', 'Email already exists');

        $result = $this->errorHandler->handleException($exception);

        $this->assertTrue($result['error']);
        $this->assertEquals(409, $result['code']);
    }

    public function testHandleGenericException(): void
    {
        $exception = new \RuntimeException('Something went wrong');

        $result = $this->errorHandler->handleException($exception);

        $this->assertTrue($result['error']);
        $this->assertEquals(500, $result['code']);
    }

    public function testHandleValidationErrors(): void
    {
        $errors = ['name' => 'Required', 'email' => 'Invalid format'];

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Validation errors occurred', $this->callback(function ($context) use ($errors) {
                return $context['errors'] === $errors;
            }));

        $result = $this->errorHandler->handleValidationErrors($errors);

        $this->assertTrue($result['error']);
        $this->assertEquals(422, $result['code']);
        $this->assertEquals('Validation failed', $result['message']);
        $this->assertEquals($errors, $result['validation_errors']);
    }

    public function testHandleDatabaseError(): void
    {
        $exception = new \PDOException('Connection failed');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Database error occurred');

        $result = $this->errorHandler->handleDatabaseError($exception);

        $this->assertTrue($result['error']);
        $this->assertEquals(500, $result['code']);
        $this->assertStringContains('Database operation failed', $result['message']);
    }

    public function testHandleApiError(): void
    {
        $service = 'payment-service';
        $statusCode = 503;
        $message = 'Service unavailable';

        $this->logger->expects($this->once())
            ->method('error')
            ->with('External API error', [
                'service' => $service,
                'status_code' => $statusCode,
                'message' => $message
            ]);

        $result = $this->errorHandler->handleApiError($service, $statusCode, $message);

        $this->assertTrue($result['error']);
        $this->assertEquals(502, $result['code']); // 5xx errors become 502
        $this->assertStringContains($service, $result['message']);
    }

    public function testProductionEnvironmentHidesDetails(): void
    {
        // Mock production environment
        putenv('APP_ENV=production');

        $exception = new \RuntimeException('Internal error with sensitive data');

        $result = $this->errorHandler->handleException($exception);

        $this->assertNull($result['details']);
        $this->assertEquals('Internal Server Error - Something went wrong', $result['message']);

        // Clean up
        putenv('APP_ENV=');
    }

    public function testDevelopmentEnvironmentShowsDetails(): void
    {
        // Mock development environment
        putenv('APP_ENV=development');

        $exception = new \RuntimeException('Internal error with debug info');

        $result = $this->errorHandler->handleException($exception);

        $this->assertNotNull($result['details']);
        $this->assertArrayHasKey('file', $result['details']);
        $this->assertArrayHasKey('line', $result['details']);
        $this->assertArrayHasKey('trace', $result['details']);

        // Clean up
        putenv('APP_ENV=');
    }
}