<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use Throwable;

/**
 * Comprehensive error handling service
 */
class ErrorHandler
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->createErrorTables();
        $this->registerErrorHandlers();
    }

    /**
     * Handle application errors
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        $errorData = [
            'type' => 'error',
            'severity' => $this->getSeverityName($severity),
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'context' => $this->getContext(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Log the error
        $this->logger->error($message, $errorData);

        // Store in database if critical
        if ($severity <= E_WARNING) {
            $this->storeError($errorData);
        }

        // Send to monitoring service for critical errors
        if ($severity <= E_ERROR) {
            $this->sendToMonitoring($errorData);
        }

        return true; // Don't execute PHP internal error handler
    }

    /**
     * Handle exceptions
     */
    public function handleException(Throwable $exception): void
    {
        $errorData = [
            'type' => 'exception',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'context' => $this->getContext(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Log the exception
        $this->logger->error('Uncaught Exception: ' . $exception->getMessage(), $errorData);

        // Store in database
        $this->storeError($errorData);

        // Send to monitoring
        $this->sendToMonitoring($errorData);

        // Send appropriate HTTP response
        $this->sendErrorResponse($exception);
    }

    /**
     * Handle fatal errors
     */
    public function handleFatalError(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $errorData = [
                'type' => 'fatal_error',
                'severity' => $this->getSeverityName($error['type']),
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'context' => $this->getContext(),
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Log the fatal error
            $this->logger->critical('Fatal Error: ' . $error['message'], $errorData);

            // Store in database
            $this->storeError($errorData);

            // Send to monitoring
            $this->sendToMonitoring($errorData);
        }
    }

    /**
     * Create standardized error response
     */
    public function createErrorResponse(string $message, int $code = 500, array $details = []): array
    {
        $errorId = $this->generateErrorId();
        
        $response = [
            'success' => false,
            'error' => [
                'id' => $errorId,
                'message' => $message,
                'code' => $code,
                'timestamp' => date('c')
            ]
        ];

        // Add details in development mode
        if ($this->isDevelopmentMode()) {
            $response['error']['details'] = $details;
            $response['error']['debug'] = [
                'file' => debug_backtrace()[1]['file'] ?? 'unknown',
                'line' => debug_backtrace()[1]['line'] ?? 0,
                'trace' => array_slice(debug_backtrace(), 0, 5)
            ];
        }

        // Log the error response
        $this->logger->error("Error Response Generated: {$message}", [
            'error_id' => $errorId,
            'code' => $code,
            'details' => $details
        ]);

        return $response;
    }

    /**
     * Validate and sanitize input data
     */
    public function validateAndSanitize(array $data, array $rules): array
    {
        $sanitized = [];
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            // Apply sanitization
            if (isset($rule['sanitize'])) {
                $value = $this->sanitizeValue($value, $rule['sanitize']);
            }

            // Apply validation
            if (isset($rule['validate'])) {
                $validation = $this->validateValue($value, $rule['validate'], $field);
                if (!$validation['valid']) {
                    $errors[$field] = $validation['error'];
                    continue;
                }
            }

            $sanitized[$field] = $value;
        }

        return [
            'valid' => empty($errors),
            'data' => $sanitized,
            'errors' => $errors
        ];
    }

    /**
     * Get error statistics
     */
    public function getErrorStatistics(string $dateFrom = null, string $dateTo = null): array
    {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $dateTo ?? date('Y-m-d');

            // Error counts by type
            $sql = "SELECT 
                        error_type,
                        severity,
                        COUNT(*) as count
                    FROM error_logs 
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY error_type, severity
                    ORDER BY count DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFrom, $dateTo]);
            $errorCounts = $stmt->fetchAll();

            // Daily error trends
            $sql = "SELECT 
                        DATE(created_at) as error_date,
                        COUNT(*) as total_errors,
                        SUM(CASE WHEN severity IN ('critical', 'error') THEN 1 ELSE 0 END) as critical_errors
                    FROM error_logs 
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY error_date";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFrom, $dateTo]);
            $dailyTrends = $stmt->fetchAll();

            // Most frequent errors
            $sql = "SELECT 
                        message,
                        COUNT(*) as occurrences,
                        MAX(created_at) as last_occurrence
                    FROM error_logs 
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY message
                    ORDER BY occurrences DESC
                    LIMIT 10";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFrom, $dateTo]);
            $frequentErrors = $stmt->fetchAll();

            return [
                'success' => true,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'error_counts' => $errorCounts,
                'daily_trends' => $dailyTrends,
                'frequent_errors' => $frequentErrors
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get error statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Register error handlers
     */
    private function registerErrorHandlers(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    /**
     * Get severity name
     */
    private function getSeverityName(int $severity): string
    {
        $severities = [
            E_ERROR => 'error',
            E_WARNING => 'warning',
            E_PARSE => 'parse',
            E_NOTICE => 'notice',
            E_CORE_ERROR => 'core_error',
            E_CORE_WARNING => 'core_warning',
            E_COMPILE_ERROR => 'compile_error',
            E_COMPILE_WARNING => 'compile_warning',
            E_USER_ERROR => 'user_error',
            E_USER_WARNING => 'user_warning',
            E_USER_NOTICE => 'user_notice',
            E_STRICT => 'strict',
            E_RECOVERABLE_ERROR => 'recoverable_error',
            E_DEPRECATED => 'deprecated',
            E_USER_DEPRECATED => 'user_deprecated'
        ];

        return $severities[$severity] ?? 'unknown';
    }

    /**
     * Get current context
     */
    private function getContext(): array
    {
        return [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Store error in database
     */
    private function storeError(array $errorData): void
    {
        try {
            $sql = "INSERT INTO error_logs (
                        error_type, severity, message, file, line, 
                        context, trace, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $errorData['type'],
                $errorData['severity'] ?? 'unknown',
                $errorData['message'],
                $errorData['file'] ?? '',
                $errorData['line'] ?? 0,
                json_encode($errorData['context'] ?? []),
                $errorData['trace'] ?? ''
            ]);

        } catch (Exception $e) {
            // Fallback to file logging if database fails
            error_log("Failed to store error in database: " . $e->getMessage());
        }
    }

    /**
     * Send error to monitoring service
     */
    private function sendToMonitoring(array $errorData): void
    {
        // Mock monitoring service integration
        // In production, this would send to services like Sentry, Bugsnag, etc.
        error_log("MONITORING: " . json_encode($errorData));
    }

    /**
     * Send appropriate error response
     */
    private function sendErrorResponse(Throwable $exception): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            
            $response = [
                'success' => false,
                'error' => [
                    'message' => 'Internal server error',
                    'code' => 500
                ]
            ];

            if ($this->isDevelopmentMode()) {
                $response['error']['debug'] = [
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine()
                ];
            }

            echo json_encode($response);
        }
    }

    /**
     * Generate unique error ID
     */
    private function generateErrorId(): string
    {
        return 'ERR_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    }

    /**
     * Check if in development mode
     */
    private function isDevelopmentMode(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'production') === 'development';
    }

    /**
     * Sanitize value based on type
     */
    private function sanitizeValue($value, string $type)
    {
        switch ($type) {
            case 'string':
                return is_string($value) ? trim(strip_tags($value)) : '';
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'email':
                return filter_var($value, FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var($value, FILTER_SANITIZE_URL);
            case 'html':
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            default:
                return $value;
        }
    }

    /**
     * Validate value based on rules
     */
    private function validateValue($value, array $rules, string $field): array
    {
        foreach ($rules as $rule) {
            switch ($rule) {
                case 'required':
                    if (empty($value)) {
                        return ['valid' => false, 'error' => "{$field} is required"];
                    }
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        return ['valid' => false, 'error' => "{$field} must be a valid email"];
                    }
                    break;
                case 'numeric':
                    if (!is_numeric($value)) {
                        return ['valid' => false, 'error' => "{$field} must be numeric"];
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        return ['valid' => false, 'error' => "{$field} must be a valid URL"];
                    }
                    break;
            }
        }

        return ['valid' => true];
    }

    /**
     * Create error tables
     */
    private function createErrorTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS error_logs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            error_type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            file VARCHAR(500),
            line INT,
            context JSON,
            trace TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_error_type (error_type),
            INDEX idx_severity (severity),
            INDEX idx_created_at (created_at)
        )";

        $this->db->exec($sql);
    }
}