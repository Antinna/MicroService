<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Structured logging service
 */
class Logger
{
    private PDO $db;
    private string $logPath;
    private array $context = [];

    // Log levels
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logPath = 'logs/';
        $this->createLogTables();
        $this->ensureLogDirectory();
    }

    /**
     * Log emergency message
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Log alert message
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Log critical message
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log notice message
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Log info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Log API request
     */
    public function logApiRequest(string $method, string $endpoint, array $data = [], int $responseCode = 200): void
    {
        $logData = [
            'type' => 'api_request',
            'method' => $method,
            'endpoint' => $endpoint,
            'request_data' => $this->sanitizeLogData($data),
            'response_code' => $responseCode,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'execution_time' => $this->getExecutionTime()
        ];

        $this->log(self::INFO, "API Request: {$method} {$endpoint}", $logData);
    }

    /**
     * Log API response
     */
    public function logApiResponse(string $endpoint, array $response, int $statusCode, float $executionTime): void
    {
        $logData = [
            'type' => 'api_response',
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'response_size' => strlen(json_encode($response)),
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_usage' => memory_get_usage(true),
            'success' => $response['success'] ?? null
        ];

        $level = $statusCode >= 400 ? self::ERROR : self::INFO;
        $this->log($level, "API Response: {$endpoint} ({$statusCode})", $logData);
    }

    /**
     * Log database query
     */
    public function logDatabaseQuery(string $query, array $params = [], float $executionTime = 0): void
    {
        $logData = [
            'type' => 'database_query',
            'query' => $query,
            'parameters' => $this->sanitizeLogData($params),
            'execution_time_ms' => round($executionTime * 1000, 4),
            'memory_usage' => memory_get_usage(true)
        ];

        $this->log(self::DEBUG, "Database Query", $logData);
    }

    /**
     * Log business event
     */
    public function logBusinessEvent(string $event, array $data = []): void
    {
        $logData = [
            'type' => 'business_event',
            'event' => $event,
            'data' => $this->sanitizeLogData($data),
            'user_id' => $this->context['user_id'] ?? null,
            'session_id' => session_id() ?: null
        ];

        $this->log(self::INFO, "Business Event: {$event}", $logData);
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $logData = [
            'type' => 'security_event',
            'event' => $event,
            'data' => $this->sanitizeLogData($data),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => date('c')
        ];

        $this->log(self::WARNING, "Security Event: {$event}", $logData);
    }

    /**
     * Set logging context
     */
    public function setContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /**
     * Get logs with filtering
     */
    public function getLogs(array $filters = []): array
    {
        try {
            $conditions = [];
            $params = [];

            // Build WHERE conditions
            if (!empty($filters['level'])) {
                $conditions[] = "level = ?";
                $params[] = $filters['level'];
            }

            if (!empty($filters['date_from'])) {
                $conditions[] = "DATE(created_at) >= ?";
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $conditions[] = "DATE(created_at) <= ?";
                $params[] = $filters['date_to'];
            }

            if (!empty($filters['message'])) {
                $conditions[] = "message LIKE ?";
                $params[] = '%' . $filters['message'] . '%';
            }

            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $page = $filters['page'] ?? 1;
            $limit = $filters['limit'] ?? 100;
            $offset = ($page - 1) * $limit;

            $sql = "SELECT * FROM application_logs 
                    {$whereClause}
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

            // Get total count
            $countSql = "SELECT COUNT(*) FROM application_logs {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
            $totalCount = $countStmt->fetchColumn();

            return [
                'success' => true,
                'logs' => $logs,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalCount,
                    'pages' => ceil($totalCount / $limit)
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to retrieve logs: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clear old logs
     */
    public function clearOldLogs(int $daysToKeep = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

            // Clear database logs
            $sql = "DELETE FROM application_logs WHERE DATE(created_at) < ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cutoffDate]);
            $deletedDbLogs = $stmt->rowCount();

            // Clear file logs
            $deletedFiles = $this->clearOldLogFiles($daysToKeep);

            return [
                'success' => true,
                'message' => "Cleared logs older than {$daysToKeep} days",
                'deleted_db_logs' => $deletedDbLogs,
                'deleted_log_files' => $deletedFiles,
                'cutoff_date' => $cutoffDate
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to clear old logs: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Core logging method
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $logEntry = [
            'level' => $level,
            'message' => $message,
            'context' => array_merge($this->context, $context),
            'timestamp' => date('c'),
            'memory_usage' => memory_get_usage(true),
            'request_id' => $this->getRequestId()
        ];

        // Log to database
        $this->logToDatabase($logEntry);

        // Log to file
        $this->logToFile($logEntry);

        // Send to external service for critical logs
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL])) {
            $this->sendToExternalService($logEntry);
        }
    }

    /**
     * Log to database
     */
    private function logToDatabase(array $logEntry): void
    {
        try {
            $sql = "INSERT INTO application_logs (
                        level, message, context, request_id, 
                        memory_usage, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $logEntry['level'],
                $logEntry['message'],
                json_encode($logEntry['context']),
                $logEntry['request_id'],
                $logEntry['memory_usage']
            ]);

        } catch (Exception $e) {
            // Fallback to file logging if database fails
            error_log("Failed to log to database: " . $e->getMessage());
            $this->logToFile($logEntry);
        }
    }

    /**
     * Log to file
     */
    private function logToFile(array $logEntry): void
    {
        try {
            $filename = $this->logPath . date('Y-m-d') . '.log';
            $logLine = json_encode($logEntry) . PHP_EOL;
            
            file_put_contents($filename, $logLine, FILE_APPEND | LOCK_EX);

        } catch (Exception $e) {
            error_log("Failed to write to log file: " . $e->getMessage());
        }
    }

    /**
     * Send to external logging service
     */
    private function sendToExternalService(array $logEntry): void
    {
        // Mock external service integration
        // In production, this would send to services like ELK Stack, Splunk, etc.
        error_log("EXTERNAL_LOG: " . json_encode($logEntry));
    }

    /**
     * Sanitize log data to remove sensitive information
     */
    private function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'credential'];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
            
            if (is_array($value)) {
                $data[$key] = $this->sanitizeLogData($value);
            }
        }

        return $data;
    }

    /**
     * Get execution time since request start
     */
    private function getExecutionTime(): float
    {
        return microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    /**
     * Get unique request ID
     */
    private function getRequestId(): string
    {
        static $requestId = null;
        
        if ($requestId === null) {
            $requestId = 'REQ_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
        }
        
        return $requestId;
    }

    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory(): void
    {
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * Clear old log files
     */
    private function clearOldLogFiles(int $daysToKeep): int
    {
        $deletedCount = 0;
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
        
        if (is_dir($this->logPath)) {
            $files = glob($this->logPath . '*.log');
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }
        
        return $deletedCount;
    }

    /**
     * Create log tables
     */
    private function createLogTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS application_logs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context JSON,
            request_id VARCHAR(50),
            memory_usage BIGINT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_level (level),
            INDEX idx_created_at (created_at),
            INDEX idx_request_id (request_id)
        )";

        $this->db->exec($sql);
    }
}