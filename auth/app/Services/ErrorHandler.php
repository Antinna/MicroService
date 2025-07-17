<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Services\Logger;
use Antinna\Auth\Services\AuditLogger;
use Exception;
use Throwable;

/**
 * Comprehensive Error Handler for Authentication Service
 */
class ErrorHandler
{
    private Logger $logger;
    private AuditLogger $auditLogger;
    private bool $debugMode;
    private array $errorCodes;

    // Error severity levels
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    // Error categories
    public const CATEGORY_AUTHENTICATION = 'authentication';
    public const CATEGORY_AUTHORIZATION = 'authorization';
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_DATABASE = 'database';
    public const CATEGORY_EXTERNAL_SERVICE = 'external_service';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_SECURITY = 'security';

    public function __construct()
    {
        $this->logger = new Logger();
        $this->auditLogger = new AuditLogger();
        $this->debugMode = $_ENV['APP_DEBUG'] ?? false;
        $this->initializeErrorCodes();
        
        // Set up global error and exception handlers
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Initialize error codes mapping
     */
    private function initializeErrorCodes(): void
    {
        $this->errorCodes = [
            // Authentication errors (1000-1999)
            'AUTH_001' => ['message' => 'Invalid credentials', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_002' => ['message' => 'Account locked', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_003' => ['message' => 'Account inactive', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_004' => ['message' => 'MFA required', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_005' => ['message' => 'Invalid MFA code', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_006' => ['message' => 'Session expired', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_007' => ['message' => 'Invalid token', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_008' => ['message' => 'Token expired', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_009' => ['message' => 'Password reset required', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHENTICATION],
            'AUTH_010' => ['message' => 'Rate limit exceeded', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_SECURITY],

            // Authorization errors (2000-2999)
            'AUTHZ_001' => ['message' => 'Insufficient permissions', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHORIZATION],
            'AUTHZ_002' => ['message' => 'Access denied', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHORIZATION],
            'AUTHZ_003' => ['message' => 'Admin privileges required', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_AUTHORIZATION],
            'AUTHZ_004' => ['message' => 'Service token required', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHORIZATION],
            'AUTHZ_005' => ['message' => 'Invalid service scope', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_AUTHORIZATION],

            // Validation errors (3000-3999)
            'VAL_001' => ['message' => 'Invalid input format', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_VALIDATION],
            'VAL_002' => ['message' => 'Required field missing', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_VALIDATION],
            'VAL_003' => ['message' => 'Invalid email format', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_VALIDATION],
            'VAL_004' => ['message' => 'Password too weak', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_VALIDATION],
            'VAL_005' => ['message' => 'Invalid phone number', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_VALIDATION],
            'VAL_006' => ['message' => 'Invalid JSON format', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_VALIDATION],
            'VAL_007' => ['message' => 'Data length exceeded', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_VALIDATION],

            // Database errors (4000-4999)
            'DB_001' => ['message' => 'Database connection failed', 'severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_DATABASE],
            'DB_002' => ['message' => 'Query execution failed', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_DATABASE],
            'DB_003' => ['message' => 'Transaction failed', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_DATABASE],
            'DB_004' => ['message' => 'Record not found', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_DATABASE],
            'DB_005' => ['message' => 'Duplicate entry', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_DATABASE],
            'DB_006' => ['message' => 'Foreign key constraint', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_DATABASE],

            // External service errors (5000-5999)
            'EXT_001' => ['message' => 'External service unavailable', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_EXTERNAL_SERVICE],
            'EXT_002' => ['message' => 'SMS service failed', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_EXTERNAL_SERVICE],
            'EXT_003' => ['message' => 'Email service failed', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_EXTERNAL_SERVICE],
            'EXT_004' => ['message' => 'OAuth provider error', 'severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_EXTERNAL_SERVICE],
            'EXT_005' => ['message' => 'Push notification failed', 'severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_EXTERNAL_SERVICE],

            // System errors (6000-6999)
            'SYS_001' => ['message' => 'Internal server error', 'severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_SYSTEM],
            'SYS_002' => ['message' => 'Configuration error', 'severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_SYSTEM],
            'SYS_003' => ['message' => 'File system error', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_SYSTEM],
            'SYS_004' => ['message' => 'Memory limit exceeded', 'severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_SYSTEM],
            'SYS_005' => ['message' => 'Timeout error', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_SYSTEM],

            // Security errors (7000-7999)
            'SEC_001' => ['message' => 'Security violation detected', 'severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_SECURITY],
            'SEC_002' => ['message' => 'Suspicious activity detected', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_SECURITY],
            'SEC_003' => ['message' => 'IP address blocked', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_SECURITY],
            'SEC_004' => ['message' => 'Malicious request detected', 'severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_SECURITY],
            'SEC_005' => ['message' => 'CSRF token mismatch', 'severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_SECURITY]
        ];
    }

    /**
     * Handle application errors
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Don't handle suppressed errors
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $errorData = [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'timestamp' => date('Y-m-d H:i:s'),
            'request_id' => $this->getRequestId(),
            'user_id' => $this->getCurrentUserId(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];

        // Log the error
        $this->logError('PHP_ERROR', $errorData);

        // Don't execute PHP internal error handler
        return true;
    }

    /**
     * Handle uncaught exceptions
     */
    public function handleException(Throwable $exception): void
    {
        $errorData = [
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
            'request_id' => $this->getRequestId(),
            'user_id' => $this->getCurrentUserId(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];

        // Log the exception
        $this->logError('UNCAUGHT_EXCEPTION', $errorData);

        // Send error response
        $this->sendErrorResponse('SYS_001', $exception);
    }

    /**
     * Handle fatal errors during shutdown
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $errorData = [
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'timestamp' => date('Y-m-d H:i:s'),
                'request_id' => $this->getRequestId(),
                'user_id' => $this->getCurrentUserId(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];

            // Log the fatal error
            $this->logError('FATAL_ERROR', $errorData);

            // Send error response if headers not sent
            if (!headers_sent()) {
                $this->sendErrorResponse('SYS_001');
            }
        }
    }

    /**
     * Handle application-specific errors
     */
    public function handleApplicationError(string $errorCode, array $context = [], ?Throwable $exception = null): array
    {
        $errorInfo = $this->errorCodes[$errorCode] ?? [
            'message' => 'Unknown error',
            'severity' => self::SEVERITY_MEDIUM,
            'category' => self::CATEGORY_SYSTEM
        ];

        $errorData = [
            'error_code' => $errorCode,
            'message' => $errorInfo['message'],
            'severity' => $errorInfo['severity'],
            'category' => $errorInfo['category'],
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'request_id' => $this->getRequestId(),
            'user_id' => $this->getCurrentUserId(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];

        if ($exception) {
            $errorData['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->debugMode ? $exception->getTraceAsString() : 'Hidden in production'
            ];
        }

        // Log the error
        $this->logError('APPLICATION_ERROR', $errorData);

        // Log security events if needed
        if ($errorInfo['category'] === self::CATEGORY_SECURITY) {
            $this->auditLogger->logSecurityIncident(
                $errorCode,
                $errorInfo['message'],
                $this->getCurrentUserId(),
                AuditLogger::SEVERITY_HIGH,
                $context
            );
        }

        return [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $this->getSafeErrorMessage($errorCode, $errorInfo['message']),
                'category' => $errorInfo['category'],
                'timestamp' => date('c'),
                'request_id' => $this->getRequestId()
            ],
            'debug' => $this->debugMode ? $errorData : null
        ];
    }

    /**
     * Log error with appropriate level
     */
    private function logError(string $type, array $data): void
    {
        $logLevel = $this->getLogLevel($data['severity'] ?? self::SEVERITY_MEDIUM);
        
        $logMessage = sprintf(
            '[%s] %s: %s',
            $type,
            $data['error_code'] ?? 'UNKNOWN',
            $data['message'] ?? 'No message'
        );

        $this->logger->log($logLevel, $logMessage, $data);

        // Also log to audit trail for security-related errors
        if (isset($data['category']) && $data['category'] === self::CATEGORY_SECURITY) {
            $this->auditLogger->logSystemEvent(
                'security_error',
                $logMessage,
                AuditLogger::SEVERITY_ERROR,
                $data
            );
        }
    }

    /**
     * Get appropriate log level for error severity
     */
    private function getLogLevel(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_LOW => Logger::LEVEL_INFO,
            self::SEVERITY_MEDIUM => Logger::LEVEL_WARNING,
            self::SEVERITY_HIGH => Logger::LEVEL_ERROR,
            self::SEVERITY_CRITICAL => Logger::LEVEL_CRITICAL,
            default => Logger::LEVEL_ERROR
        };
    }

    /**
     * Get safe error message for public consumption
     */
    private function getSafeErrorMessage(string $errorCode, string $originalMessage): string
    {
        // In production, don't expose sensitive error details
        if (!$this->debugMode) {
            $sensitiveErrors = ['DB_001', 'DB_002', 'SYS_001', 'SYS_002'];
            if (in_array($errorCode, $sensitiveErrors)) {
                return 'An internal error occurred. Please try again later.';
            }
        }

        return $originalMessage;
    }

    /**
     * Send JSON error response
     */
    private function sendErrorResponse(string $errorCode, ?Throwable $exception = null): void
    {
        if (headers_sent()) {
            return;
        }

        $response = $this->handleApplicationError($errorCode, [], $exception);
        
        // Set appropriate HTTP status code
        $httpStatus = $this->getHttpStatusCode($errorCode);
        http_response_code($httpStatus);
        
        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
    }

    /**
     * Get HTTP status code for error
     */
    private function getHttpStatusCode(string $errorCode): int
    {
        $statusMap = [
            // Authentication errors
            'AUTH_001' => 401, 'AUTH_002' => 423, 'AUTH_003' => 403,
            'AUTH_004' => 401, 'AUTH_005' => 401, 'AUTH_006' => 401,
            'AUTH_007' => 401, 'AUTH_008' => 401, 'AUTH_009' => 401,
            'AUTH_010' => 429,
            
            // Authorization errors
            'AUTHZ_001' => 403, 'AUTHZ_002' => 403, 'AUTHZ_003' => 403,
            'AUTHZ_004' => 401, 'AUTHZ_005' => 403,
            
            // Validation errors
            'VAL_001' => 400, 'VAL_002' => 400, 'VAL_003' => 400,
            'VAL_004' => 400, 'VAL_005' => 400, 'VAL_006' => 400,
            'VAL_007' => 400,
            
            // Database errors
            'DB_001' => 503, 'DB_002' => 500, 'DB_003' => 500,
            'DB_004' => 404, 'DB_005' => 409, 'DB_006' => 409,
            
            // External service errors
            'EXT_001' => 503, 'EXT_002' => 502, 'EXT_003' => 502,
            'EXT_004' => 502, 'EXT_005' => 502,
            
            // System errors
            'SYS_001' => 500, 'SYS_002' => 500, 'SYS_003' => 500,
            'SYS_004' => 500, 'SYS_005' => 504,
            
            // Security errors
            'SEC_001' => 403, 'SEC_002' => 403, 'SEC_003' => 403,
            'SEC_004' => 403, 'SEC_005' => 403
        ];

        return $statusMap[$errorCode] ?? 500;
    }

    /**
     * Get current request ID
     */
    private function getRequestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
    }

    /**
     * Get current user ID if available
     */
    private function getCurrentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get error information by code
     */
    public function getErrorInfo(string $errorCode): ?array
    {
        return $this->errorCodes[$errorCode] ?? null;
    }

    /**
     * Check if error code exists
     */
    public function hasErrorCode(string $errorCode): bool
    {
        return isset($this->errorCodes[$errorCode]);
    }

    /**
     * Get all error codes by category
     */
    public function getErrorsByCategory(string $category): array
    {
        return array_filter($this->errorCodes, function($error) use ($category) {
            return $error['category'] === $category;
        });
    }
}