<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\ErrorHandler;
use Antinna\Auth\Services\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for ErrorHandler
 */
class ErrorHandlerTest extends TestCase
{
    private ErrorHandler $errorHandler;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->errorHandler = new ErrorHandler();
        
        // Use reflection to inject mock logger
        $reflection = new \ReflectionClass($this->errorHandler);
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($this->errorHandler, $this->logger);
    }

    public function testHandleApplicationErrorWithValidCode(): void
    {
        // Mock logger expectations
        $this->logger->expects($this->once())
            ->method('log')
            ->with(
                Logger::LEVEL_WARNING,
                $this->stringContains('AUTH_001'),
                $this->isType('array')
            );

        // Test handling authentication error
        $result = $this->errorHandler->handleApplicationError('AUTH_001', [
            'email' => 'test@example.com',
            'ip_address' => '127.0.0.1'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('AUTH_001', $result['error']['code']);
        $this->assertEquals('Invalid credentials', $result['error']['message']);
        $this->assertEquals('authentication', $result['error']['category']);
        $this->assertArrayHasKey('timestamp', $result['error']);
        $this->assertArrayHasKey('request_id', $result['error']);
    }

    public function testHandleApplicationErrorWithUnknownCode(): void
    {
        // Mock logger expectations
        $this->logger->expects($this->once())
            ->method('log')
            ->with(
                Logger::LEVEL_ERROR,
                $this->stringContains('UNKNOWN_ERROR'),
                $this->isType('array')
            );

        // Test handling unknown error code
        $result = $this->errorHandler->handleApplicationError('UNKNOWN_ERROR');

        $this->assertFalse($result['success']);
        $this->assertEquals('UNKNOWN_ERROR', $result['error']['code']);
        $this->assertEquals('Unknown error', $result['error']['message']);
        $this->assertEquals('system', $result['error']['category']);
    }

    public function testHandleApplicationErrorWithException(): void
    {
        $exception = new \Exception('Test exception message', 123);

        // Mock logger expectations
        $this->logger->expects($this->once())
            ->method('log')
            ->with(
                Logger::LEVEL_HIGH,
                $this->stringContains('DB_001'),
                $this->callback(function($context) {
                    return isset($context['exception']) && 
                           $context['exception']['class'] === 'Exception' &&
                           $context['exception']['message'] === 'Test exception message';
                })
            );

        // Test handling database error with exception
        $result = $this->errorHandler->handleApplicationError('DB_001', [], $exception);

        $this->assertFalse($result['success']);
        $this->assertEquals('DB_001', $result['error']['code']);
        $this->assertEquals('Database connection failed', $result['error']['message']);
        $this->assertEquals('database', $result['error']['category']);
    }

    public function testGetErrorInfo(): void
    {
        // Test getting valid error info
        $errorInfo = $this->errorHandler->getErrorInfo('AUTH_001');
        
        $this->assertNotNull($errorInfo);
        $this->assertEquals('Invalid credentials', $errorInfo['message']);
        $this->assertEquals('medium', $errorInfo['severity']);
        $this->assertEquals('authentication', $errorInfo['category']);

        // Test getting invalid error info
        $invalidErrorInfo = $this->errorHandler->getErrorInfo('INVALID_CODE');
        $this->assertNull($invalidErrorInfo);
    }

    public function testHasErrorCode(): void
    {
        $this->assertTrue($this->errorHandler->hasErrorCode('AUTH_001'));
        $this->assertTrue($this->errorHandler->hasErrorCode('DB_001'));
        $this->assertTrue($this->errorHandler->hasErrorCode('VAL_001'));
        $this->assertFalse($this->errorHandler->hasErrorCode('INVALID_CODE'));
    }

    public function testGetErrorsByCategory(): void
    {
        // Test getting authentication errors
        $authErrors = $this->errorHandler->getErrorsByCategory('authentication');
        $this->assertNotEmpty($authErrors);
        $this->assertArrayHasKey('AUTH_001', $authErrors);
        $this->assertArrayHasKey('AUTH_002', $authErrors);

        // Test getting validation errors
        $validationErrors = $this->errorHandler->getErrorsByCategory('validation');
        $this->assertNotEmpty($validationErrors);
        $this->assertArrayHasKey('VAL_001', $validationErrors);
        $this->assertArrayHasKey('VAL_002', $validationErrors);

        // Test getting non-existent category
        $nonExistentErrors = $this->errorHandler->getErrorsByCategory('non_existent');
        $this->assertEmpty($nonExistentErrors);
    }

    public function testErrorSeverityLevels(): void
    {
        // Test critical error
        $criticalResult = $this->errorHandler->handleApplicationError('DB_001');
        $this->assertEquals('critical', $this->getErrorSeverity('DB_001'));

        // Test high severity error
        $highResult = $this->errorHandler->handleApplicationError('AUTH_002');
        $this->assertEquals('high', $this->getErrorSeverity('AUTH_002'));

        // Test medium severity error
        $mediumResult = $this->errorHandler->handleApplicationError('AUTH_001');
        $this->assertEquals('medium', $this->getErrorSeverity('AUTH_001'));

        // Test low severity error
        $lowResult = $this->errorHandler->handleApplicationError('AUTH_006');
        $this->assertEquals('low', $this->getErrorSeverity('AUTH_006'));
    }

    public function testErrorCategories(): void
    {
        // Test authentication category
        $this->assertEquals('authentication', $this->getErrorCategory('AUTH_001'));
        $this->assertEquals('authentication', $this->getErrorCategory('AUTH_010'));

        // Test authorization category
        $this->assertEquals('authorization', $this->getErrorCategory('AUTHZ_001'));
        $this->assertEquals('authorization', $this->getErrorCategory('AUTHZ_002'));

        // Test validation category
        $this->assertEquals('validation', $this->getErrorCategory('VAL_001'));
        $this->assertEquals('validation', $this->getErrorCategory('VAL_007'));

        // Test database category
        $this->assertEquals('database', $this->getErrorCategory('DB_001'));
        $this->assertEquals('database', $this->getErrorCategory('DB_006'));

        // Test external service category
        $this->assertEquals('external_service', $this->getErrorCategory('EXT_001'));
        $this->assertEquals('external_service', $this->getErrorCategory('EXT_005'));

        // Test system category
        $this->assertEquals('system', $this->getErrorCategory('SYS_001'));
        $this->assertEquals('system', $this->getErrorCategory('SYS_005'));

        // Test security category
        $this->assertEquals('security', $this->getErrorCategory('SEC_001'));
        $this->assertEquals('security', $this->getErrorCategory('SEC_005'));
    }

    public function testSecurityErrorLogging(): void
    {
        // Mock audit logger for security events
        $auditLogger = $this->createMock(\Antinna\Auth\Services\AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logSecurityIncident')
            ->with(
                'SEC_001',
                'Security violation detected',
                $this->isType('int'),
                \Antinna\Auth\Services\AuditLogger::SEVERITY_HIGH,
                $this->isType('array')
            );

        // Inject mock audit logger
        $reflection = new \ReflectionClass($this->errorHandler);
        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->errorHandler, $auditLogger);

        // Test security error handling
        $_SESSION['user_id'] = 123;
        $result = $this->errorHandler->handleApplicationError('SEC_001', [
            'violation_type' => 'suspicious_activity',
            'details' => 'Multiple failed login attempts'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('SEC_001', $result['error']['code']);
        $this->assertEquals('security', $result['error']['category']);
    }

    public function testContextPreservation(): void
    {
        $context = [
            'user_id' => 123,
            'action' => 'login_attempt',
            'ip_address' => '192.168.1.1',
            'additional_data' => [
                'browser' => 'Chrome',
                'os' => 'Windows'
            ]
        ];

        // Mock logger to verify context is preserved
        $this->logger->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function($logContext) use ($context) {
                    return $logContext['context']['user_id'] === $context['user_id'] &&
                           $logContext['context']['action'] === $context['action'] &&
                           $logContext['context']['ip_address'] === $context['ip_address'] &&
                           $logContext['context']['additional_data']['browser'] === $context['additional_data']['browser'];
                })
            );

        $result = $this->errorHandler->handleApplicationError('AUTH_001', $context);
        
        $this->assertFalse($result['success']);
    }

    /**
     * Helper method to get error severity
     */
    private function getErrorSeverity(string $errorCode): string
    {
        $errorInfo = $this->errorHandler->getErrorInfo($errorCode);
        return $errorInfo['severity'] ?? 'unknown';
    }

    /**
     * Helper method to get error category
     */
    private function getErrorCategory(string $errorCode): string
    {
        $errorInfo = $this->errorHandler->getErrorInfo($errorCode);
        return $errorInfo['category'] ?? 'unknown';
    }

    protected function tearDown(): void
    {
        // Clean up session
        if (isset($_SESSION['user_id'])) {
            unset($_SESSION['user_id']);
        }
    }
}