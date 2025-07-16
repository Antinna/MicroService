<?php

namespace Antinna\Multivendor\Services;

class Logger
{
    private string $logPath;
    private string $logLevel;
    private array $logLevels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];

    public function __construct()
    {
        $this->logPath = getenv('LOG_PATH') ?: __DIR__ . '/../../logs';
        $this->logLevel = strtoupper(getenv('LOG_LEVEL') ?: 'INFO');
        
        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        // Check if we should log this level
        if (!$this->shouldLog($level)) {
            return;
        }

        $logEntry = $this->formatLogEntry($level, $message, $context);
        $this->writeToFile($logEntry);
        
        // Also write to error log for ERROR and CRITICAL levels
        if (in_array($level, ['ERROR', 'CRITICAL'])) {
            error_log($logEntry);
        }
    }

    private function shouldLog(string $level): bool
    {
        $currentLevelValue = $this->logLevels[$this->logLevel] ?? 1;
        $messageLevelValue = $this->logLevels[$level] ?? 1;
        
        return $messageLevelValue >= $currentLevelValue;
    }

    private function formatLogEntry(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $requestId = $this->getRequestId();
        
        $logData = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'request_id' => $requestId,
            'context' => $context
        ];

        // Add request information if available
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $logData['request'] = [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip' => $this->getClientIp()
            ];
        }

        return json_encode($logData, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function writeToFile(string $logEntry): void
    {
        $filename = $this->logPath . '/multivendor-' . date('Y-m-d') . '.log';
        file_put_contents($filename, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function getRequestId(): string
    {
        // Try to get request ID from header first
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            return $_SERVER['HTTP_X_REQUEST_ID'];
        }

        // Generate one if not present
        if (!isset($_SERVER['REQUEST_ID'])) {
            $_SERVER['REQUEST_ID'] = uniqid('req_', true);
        }

        return $_SERVER['REQUEST_ID'];
    }

    private function getClientIp(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return 'unknown';
    }

    public function logApiRequest(string $method, string $endpoint, array $params = [], ?string $userId = null): void
    {
        $this->info('API Request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => $this->sanitizeParams($params),
            'user_id' => $userId,
            'timestamp' => microtime(true)
        ]);
    }

    public function logApiResponse(string $endpoint, int $statusCode, float $executionTime, ?array $responseData = null): void
    {
        $this->info('API Response', [
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'response_size' => $responseData ? strlen(json_encode($responseData)) : 0
        ]);
    }

    public function logDatabaseQuery(string $query, array $params = [], float $executionTime = 0): void
    {
        $this->debug('Database Query', [
            'query' => $query,
            'params' => $this->sanitizeParams($params),
            'execution_time_ms' => round($executionTime * 1000, 2)
        ]);
    }

    public function logBusinessEvent(string $event, array $data = []): void
    {
        $this->info('Business Event', [
            'event' => $event,
            'data' => $this->sanitizeParams($data)
        ]);
    }

    private function sanitizeParams(array $params): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'authorization'];
        
        return array_map(function ($value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                return '[REDACTED]';
            }
            
            if (is_array($value)) {
                return $this->sanitizeParams($value);
            }
            
            return $value;
        }, $params, array_keys($params));
    }
}