<?php

namespace Antinna\Auth\Helpers;

use Antinna\Auth\Services\ErrorHandler;
use Antinna\Auth\Services\Logger;

/**
 * Error Handling Helper Functions
 */
class ErrorHandlingHelper
{
    private static ?ErrorHandler $errorHandler = null;
    private static ?Logger $logger = null;

    /**
     * Get error handler instance
     */
    private static function getErrorHandler(): ErrorHandler
    {
        if (self::$errorHandler === null) {
            self::$errorHandler = new ErrorHandler();
        }
        return self::$errorHandler;
    }

    /**
     * Get logger instance
     */
    private static function getLogger(): Logger
    {
        if (self::$logger === null) {
            self::$logger = new Logger();
        }
        return self::$logger;
    }

    /**
     * Send standardized error response
     */
    public static function sendError(string $errorCode, array $context = [], int $httpStatus = null): void
    {
        $errorHandler = self::getErrorHandler();
        $response = $errorHandler->handleApplicationError($errorCode, $context);
        
        // Set HTTP status code
        if ($httpStatus) {
            http_response_code($httpStatus);
        } else {
            // Use default status code for error
            $statusMap = [
                'AUTH_001' => 401, 'AUTH_002' => 423, 'AUTH_003' => 403,
                'AUTHZ_001' => 403, 'AUTHZ_002' => 403,
                'VAL_001' => 400, 'VAL_002' => 400, 'VAL_003' => 400,
                'DB_001' => 503, 'DB_004' => 404,
                'SYS_001' => 500
            ];
            http_response_code($statusMap[$errorCode] ?? 500);
        }

        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }

    /**
     * Send success response
     */
    public static function sendSuccess(array $data = [], string $message = 'Success'): void
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true)
        ];

        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }

    /**
     * Validate required fields
     */
    public static function validateRequired(array $data, array $requiredFields): void
    {
        $missing = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            self::sendError('VAL_002', [
                'missing_fields' => $missing,
                'message' => 'Required fields missing: ' . implode(', ', $missing)
            ]);
        }
    }

    /**
     * Validate email format
     */
    public static function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::sendError('VAL_003', ['email' => $email]);
        }
    }

    /**
     * Validate password strength
     */
    public static function validatePassword(string $password): void
    {
        $minLength = 8;
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        if (!empty($errors)) {
            self::sendError('VAL_004', [
                'validation_errors' => $errors,
                'message' => 'Password does not meet security requirements'
            ]);
        }
    }

    /**
     * Validate JSON input
     */
    public static function validateJsonInput(): array
    {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            self::sendError('VAL_002', ['message' => 'Request body is required']);
        }

        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::sendError('VAL_006', [
                'json_error' => json_last_error_msg(),
                'message' => 'Invalid JSON format'
            ]);
        }

        return $data;
    }

    /**
     * Validate phone number
     */
    public static function validatePhoneNumber(string $phone): void
    {
        // Basic phone number validation (can be enhanced based on requirements)
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
            self::sendError('VAL_005', [
                'phone' => $phone,
                'message' => 'Invalid phone number format'
            ]);
        }
    }

    /**
     * Log and handle database errors
     */
    public static function handleDatabaseError(\Exception $e, string $operation = 'database operation'): void
    {
        $logger = self::getLogger();
        
        $context = [
            'operation' => $operation,
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];

        $logger->error("Database Error: {$operation}", $context, Logger::CHANNEL_DATABASE);

        // Determine specific error code
        $errorCode = 'DB_002'; // Default to query execution failed
        
        if (strpos($e->getMessage(), 'Connection') !== false) {
            $errorCode = 'DB_001';
        } elseif (strpos($e->getMessage(), 'Duplicate') !== false) {
            $errorCode = 'DB_005';
        } elseif (strpos($e->getMessage(), 'foreign key') !== false) {
            $errorCode = 'DB_006';
        }

        self::sendError($errorCode, $context);
    }

    /**
     * Log and handle external service errors
     */
    public static function handleExternalServiceError(string $service, \Exception $e, array $context = []): void
    {
        $logger = self::getLogger();
        
        $logContext = array_merge($context, [
            'service' => $service,
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode()
        ]);

        $logger->logExternalService($service, 'error', false, $logContext);

        // Determine specific error code based on service
        $errorCode = match ($service) {
            'sms' => 'EXT_002',
            'email' => 'EXT_003',
            'oauth' => 'EXT_004',
            'push_notification' => 'EXT_005',
            default => 'EXT_001'
        };

        self::sendError($errorCode, $logContext);
    }

    /**
     * Try-catch wrapper with automatic error handling
     */
    public static function tryExecute(callable $callback, string $operation = 'operation', array $context = [])
    {
        try {
            return $callback();
        } catch (\Exception $e) {
            $logger = self::getLogger();
            
            $errorContext = array_merge($context, [
                'operation' => $operation,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            $logger->error("Operation Failed: {$operation}", $errorContext);

            // Re-throw for specific handling or send generic error
            if ($e instanceof \PDOException) {
                self::handleDatabaseError($e, $operation);
            } else {
                self::sendError('SYS_001', $errorContext);
            }
        }
    }

    /**
     * Sanitize output for security
     */
    public static function sanitizeOutput(array $data): array
    {
        $sensitiveFields = [
            'password', 'password_hash', 'password_confirmation',
            'token', 'access_token', 'refresh_token', 'session_token',
            'secret', 'api_key', 'private_key', 'mfa_secret',
            'backup_codes', 'recovery_codes'
        ];

        return self::sanitizeArrayRecursive($data, $sensitiveFields);
    }

    /**
     * Recursively sanitize array
     */
    private static function sanitizeArrayRecursive(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeArrayRecursive($value, $sensitiveFields);
            } elseif (in_array(strtolower($key), $sensitiveFields)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Rate limit check with error handling
     */
    public static function checkRateLimit(string $identifier, string $type = 'general', int $limit = 60, int $window = 3600): void
    {
        // This would integrate with your rate limiter
        // For now, we'll simulate the check
        $rateLimitExceeded = false; // This would be actual rate limit check

        if ($rateLimitExceeded) {
            self::sendError('AUTH_010', [
                'identifier' => $identifier,
                'type' => $type,
                'limit' => $limit,
                'window' => $window,
                'message' => 'Rate limit exceeded. Please try again later.'
            ], 429);
        }
    }

    /**
     * Authentication check with error handling
     */
    public static function requireAuthentication(): int
    {
        $userId = $_SESSION['user_id'] ?? null;
        
        if (!$userId) {
            // Try JWT token
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                // This would validate JWT token
                // For now, we'll simulate failure
                self::sendError('AUTH_007', ['message' => 'Invalid or missing authentication token']);
            } else {
                self::sendError('AUTH_007', ['message' => 'Authentication required']);
            }
        }

        return (int)$userId;
    }

    /**
     * Authorization check with error handling
     */
    public static function requirePermission(string $permission, ?int $userId = null): void
    {
        $userId = $userId ?? self::requireAuthentication();
        
        // This would check user permissions
        $hasPermission = false; // This would be actual permission check

        if (!$hasPermission) {
            self::sendError('AUTHZ_001', [
                'user_id' => $userId,
                'required_permission' => $permission,
                'message' => 'Insufficient permissions'
            ]);
        }
    }

    /**
     * Admin check with error handling
     */
    public static function requireAdmin(): int
    {
        $userId = self::requireAuthentication();
        
        // This would check if user is admin
        $isAdmin = false; // This would be actual admin check

        if (!$isAdmin) {
            self::sendError('AUTHZ_003', [
                'user_id' => $userId,
                'message' => 'Admin privileges required'
            ]);
        }

        return $userId;
    }

    /**
     * Create error response without sending
     */
    public static function createErrorResponse(string $errorCode, array $context = []): array
    {
        $errorHandler = self::getErrorHandler();
        return $errorHandler->handleApplicationError($errorCode, $context);
    }

    /**
     * Create success response without sending
     */
    public static function createSuccessResponse(array $data = [], string $message = 'Success'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true)
        ];
    }
}