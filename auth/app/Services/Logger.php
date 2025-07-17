<?php

namespace Antinna\Auth\Services;

use Exception;

/**
 * Comprehensive Logging Service for Authentication Service
 */
class Logger
{
    // Log levels (PSR-3 compatible)
    public const LEVEL_EMERGENCY = 'emergency';
    public const LEVEL_ALERT = 'alert';
    public const LEVEL_CRITICAL = 'critical';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTICE = 'notice';
    public const LEVEL_INFO = 'info';
    public const LEVEL_DEBUG = 'debug';

    // Log channels
    public const CHANNEL_APPLICATION = 'application';
    public const CHANNEL_SECURITY = 'security';
    public const CHANNEL_PERFORMANCE = 'performance';
    public const CHANNEL_API = 'api';
    public const CHANNEL_DATABASE = 'database';
    public const CHANNEL_EXTERNAL = 'external';

    private string $logPath;
    private string $defaultChannel;
    private array $logLevels;
    private bool $enableRotation;
    private int $maxFileSize;
    private int $maxFiles;

    public function __construct()
    {
        $this->logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../logs';
        $this->defaultChannel = self::CHANNEL_APPLICATION;
        $this->enableRotation = true;
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB
        $this->maxFiles = 30; // Keep 30 files

        // Initialize log levels hierarchy
        $this->logLevels = [
            self::LEVEL_EMERGENCY => 0,
            self::LEVEL_ALERT => 1,
            self::LEVEL_CRITICAL => 2,
            self::LEVEL_ERROR => 3,
            self::LEVEL_WARNING => 4,
            self::LEVEL_NOTICE => 5,
            self::LEVEL_INFO => 6,
            self::LEVEL_DEBUG => 7
        ];

        // Ensure log directory exists
        $this->ensureLogDirectory();
    }

    /**
     * Log a message with specified level
     */
    public function log(string $level, string $message, array $context = [], string $channel = null): void
    {
        $channel = $channel ?? $this->defaultChannel;
        
        // Check if we should log this level
        if (!$this->shouldLog($level)) {
            return;
        }

        $logEntry = $this->formatLogEntry($level, $message, $context, $channel);
        $this->writeLog($logEntry, $channel);
    }

    /**
     * Emergency: system is unusable
     */
    public function emergency(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_EMERGENCY, $message, $context, $channel);
    }

    /**
     * Alert: action must be taken immediately
     */
    public function alert(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_ALERT, $message, $context, $channel);
    }

    /**
     * Critical: critical conditions
     */
    public function critical(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context, $channel);
    }

    /**
     * Error: error conditions
     */
    public function error(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context, $channel);
    }

    /**
     * Warning: warning conditions
     */
    public function warning(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context, $channel);
    }

    /**
     * Notice: normal but significant condition
     */
    public function notice(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_NOTICE, $message, $context, $channel);
    }

    /**
     * Info: informational messages
     */
    public function info(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_INFO, $message, $context, $channel);
    }

    /**
     * Debug: debug-level messages
     */
    public function debug(string $message, array $context = [], string $channel = null): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context, $channel);
    }

    /**
     * Log API request/response
     */
    public function logApiRequest(string $method, string $uri, array $headers = [], array $body = [], ?int $userId = null): void
    {
        $context = [
            'method' => $method,
            'uri' => $uri,
            'headers' => $this->sanitizeHeaders($headers),
            'body' => $this->sanitizeBody($body),
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_id' => $this->getRequestId()
        ];

        $this->info("API Request: {$method} {$uri}", $context, self::CHANNEL_API);
    }

    /**
     * Log API response
     */
    public function logApiResponse(int $statusCode, array $responseData = [], float $executionTime = null): void
    {
        $context = [
            'status_code' => $statusCode,
            'response_size' => strlen(json_encode($responseData)),
            'execution_time' => $executionTime,
            'request_id' => $this->getRequestId()
        ];

        $level = $statusCode >= 500 ? self::LEVEL_ERROR : 
                ($statusCode >= 400 ? self::LEVEL_WARNING : self::LEVEL_INFO);

        $this->log($level, "API Response: {$statusCode}", $context, self::CHANNEL_API);
    }

    /**
     * Log database query
     */
    public function logDatabaseQuery(string $query, array $params = [], float $executionTime = null, ?string $error = null): void
    {
        $context = [
            'query' => $this->sanitizeQuery($query),
            'params' => $this->sanitizeParams($params),
            'execution_time' => $executionTime,
            'error' => $error,
            'request_id' => $this->getRequestId()
        ];

        $level = $error ? self::LEVEL_ERROR : self::LEVEL_DEBUG;
        $message = $error ? "Database Error: {$error}" : "Database Query Executed";

        $this->log($level, $message, $context, self::CHANNEL_DATABASE);
    }

    /**
     * Log performance metrics
     */
    public function logPerformance(string $operation, float $executionTime, array $metrics = []): void
    {
        $context = array_merge([
            'operation' => $operation,
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'request_id' => $this->getRequestId()
        ], $metrics);

        $level = $executionTime > 5.0 ? self::LEVEL_WARNING : self::LEVEL_INFO;
        $this->log($level, "Performance: {$operation}", $context, self::CHANNEL_PERFORMANCE);
    }

    /**
     * Log external service call
     */
    public function logExternalService(string $service, string $operation, bool $success, array $context = []): void
    {
        $logContext = array_merge([
            'service' => $service,
            'operation' => $operation,
            'success' => $success,
            'request_id' => $this->getRequestId()
        ], $context);

        $level = $success ? self::LEVEL_INFO : self::LEVEL_ERROR;
        $message = $success ? "External Service Success: {$service}" : "External Service Failed: {$service}";

        $this->log($level, $message, $logContext, self::CHANNEL_EXTERNAL);
    }

    /**
     * Format log entry
     */
    private function formatLogEntry(string $level, string $message, array $context, string $channel): string
    {
        $timestamp = date('Y-m-d H:i:s.u');
        $levelUpper = strtoupper($level);
        $channelUpper = strtoupper($channel);
        
        // Basic log format
        $logEntry = "[{$timestamp}] {$levelUpper}.{$channelUpper}: {$message}";
        
        // Add context if present
        if (!empty($context)) {
            $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $logEntry .= " " . $contextJson;
        }
        
        return $logEntry . PHP_EOL;
    }

    /**
     * Write log entry to file
     */
    private function writeLog(string $logEntry, string $channel): void
    {
        try {
            $logFile = $this->getLogFile($channel);
            
            // Check if rotation is needed
            if ($this->enableRotation && file_exists($logFile) && filesize($logFile) > $this->maxFileSize) {
                $this->rotateLogFile($logFile);
            }
            
            // Write log entry
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
        } catch (Exception $e) {
            // Fallback to error_log if file writing fails
            error_log("Logger Error: " . $e->getMessage());
            error_log($logEntry);
        }
    }

    /**
     * Get log file path for channel
     */
    private function getLogFile(string $channel): string
    {
        $date = date('Y-m-d');
        return "{$this->logPath}/{$channel}-{$date}.log";
    }

    /**
     * Rotate log file when it gets too large
     */
    private function rotateLogFile(string $logFile): void
    {
        $pathInfo = pathinfo($logFile);
        $timestamp = date('Y-m-d_H-i-s');
        $rotatedFile = "{$pathInfo['dirname']}/{$pathInfo['filename']}-{$timestamp}.{$pathInfo['extension']}";
        
        // Move current log file
        rename($logFile, $rotatedFile);
        
        // Clean up old log files
        $this->cleanupOldLogs($pathInfo['dirname'], $pathInfo['filename']);
    }

    /**
     * Clean up old log files
     */
    private function cleanupOldLogs(string $directory, string $basename): void
    {
        $pattern = "{$directory}/{$basename}-*.log";
        $files = glob($pattern);
        
        if (count($files) > $this->maxFiles) {
            // Sort by modification time (oldest first)
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest files
            $filesToRemove = array_slice($files, 0, count($files) - $this->maxFiles);
            foreach ($filesToRemove as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Check if we should log this level
     */
    private function shouldLog(string $level): bool
    {
        $minLevel = $_ENV['LOG_LEVEL'] ?? self::LEVEL_INFO;
        $minLevelValue = $this->logLevels[$minLevel] ?? $this->logLevels[self::LEVEL_INFO];
        $currentLevelValue = $this->logLevels[$level] ?? 999;
        
        return $currentLevelValue <= $minLevelValue;
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
     * Sanitize headers for logging
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];
        
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $headers[$key] = '[REDACTED]';
            }
        }
        
        return $headers;
    }

    /**
     * Sanitize request body for logging
     */
    private function sanitizeBody(array $body): array
    {
        $sensitiveFields = ['password', 'password_confirmation', 'token', 'secret', 'api_key', 'private_key'];
        
        return $this->sanitizeArray($body, $sensitiveFields);
    }

    /**
     * Sanitize database query for logging
     */
    private function sanitizeQuery(string $query): string
    {
        // Remove potential sensitive data patterns
        $patterns = [
            '/password\s*=\s*[\'"][^\'"]*[\'"]/i' => 'password = [REDACTED]',
            '/token\s*=\s*[\'"][^\'"]*[\'"]/i' => 'token = [REDACTED]',
            '/secret\s*=\s*[\'"][^\'"]*[\'"]/i' => 'secret = [REDACTED]'
        ];
        
        return preg_replace(array_keys($patterns), array_values($patterns), $query);
    }

    /**
     * Sanitize database parameters for logging
     */
    private function sanitizeParams(array $params): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'private_key'];
        return $this->sanitizeArray($params, $sensitiveFields);
    }

    /**
     * Recursively sanitize array
     */
    private function sanitizeArray(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value, $sensitiveFields);
            } elseif (in_array(strtolower($key), $sensitiveFields)) {
                $data[$key] = '[REDACTED]';
            }
        }
        
        return $data;
    }

    /**
     * Get current request ID
     */
    private function getRequestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
    }

    /**
     * Get log statistics
     */
    public function getLogStats(string $channel = null, string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $stats = [];
        
        if ($channel) {
            $channels = [$channel];
        } else {
            $channels = [
                self::CHANNEL_APPLICATION,
                self::CHANNEL_SECURITY,
                self::CHANNEL_PERFORMANCE,
                self::CHANNEL_API,
                self::CHANNEL_DATABASE,
                self::CHANNEL_EXTERNAL
            ];
        }
        
        foreach ($channels as $ch) {
            $logFile = "{$this->logPath}/{$ch}-{$date}.log";
            
            if (file_exists($logFile)) {
                $stats[$ch] = [
                    'file_size' => filesize($logFile),
                    'line_count' => $this->countLines($logFile),
                    'last_modified' => filemtime($logFile)
                ];
            } else {
                $stats[$ch] = [
                    'file_size' => 0,
                    'line_count' => 0,
                    'last_modified' => null
                ];
            }
        }
        
        return $stats;
    }

    /**
     * Count lines in log file
     */
    private function countLines(string $file): int
    {
        $count = 0;
        $handle = fopen($file, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $count++;
            }
            fclose($handle);
        }
        
        return $count;
    }

    /**
     * Search logs
     */
    public function searchLogs(string $pattern, string $channel = null, string $date = null, int $limit = 100): array
    {
        $date = $date ?? date('Y-m-d');
        $results = [];
        
        if ($channel) {
            $channels = [$channel];
        } else {
            $channels = [
                self::CHANNEL_APPLICATION,
                self::CHANNEL_SECURITY,
                self::CHANNEL_PERFORMANCE,
                self::CHANNEL_API,
                self::CHANNEL_DATABASE,
                self::CHANNEL_EXTERNAL
            ];
        }
        
        foreach ($channels as $ch) {
            $logFile = "{$this->logPath}/{$ch}-{$date}.log";
            
            if (file_exists($logFile)) {
                $matches = $this->searchInFile($logFile, $pattern, $limit);
                if (!empty($matches)) {
                    $results[$ch] = $matches;
                }
            }
        }
        
        return $results;
    }

    /**
     * Search pattern in log file
     */
    private function searchInFile(string $file, string $pattern, int $limit): array
    {
        $matches = [];
        $count = 0;
        $handle = fopen($file, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false && $count < $limit) {
                if (stripos($line, $pattern) !== false) {
                    $matches[] = trim($line);
                    $count++;
                }
            }
            fclose($handle);
        }
        
        return $matches;
    }
}