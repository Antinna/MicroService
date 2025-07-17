<?php

namespace Antinna\Auth\Middleware;

use Antinna\Auth\Services\Logger;
use Antinna\Auth\Services\ErrorHandler;

/**
 * Logging Middleware for Request/Response Logging
 */
class LoggingMiddleware
{
    private Logger $logger;
    private ErrorHandler $errorHandler;
    private float $startTime;
    private array $config;

    public function __construct()
    {
        $this->logger = new Logger();
        $this->errorHandler = new ErrorHandler();
        $this->startTime = microtime(true);
        
        $this->config = [
            'log_requests' => $_ENV['LOG_REQUESTS'] ?? true,
            'log_responses' => $_ENV['LOG_RESPONSES'] ?? true,
            'log_performance' => $_ENV['LOG_PERFORMANCE'] ?? true,
            'log_errors' => $_ENV['LOG_ERRORS'] ?? true,
            'sensitive_fields' => ['password', 'token', 'secret', 'api_key', 'private_key', 'authorization'],
            'max_body_size' => 10240, // 10KB
            'exclude_paths' => ['/health', '/ping', '/favicon.ico']
        ];
    }

    /**
     * Process incoming request
     */
    public function handleRequest(): void
    {
        if (!$this->shouldLog()) {
            return;
        }

        $this->startTime = microtime(true);
        
        if ($this->config['log_requests']) {
            $this->logIncomingRequest();
        }

        // Set up output buffering to capture response
        if ($this->config['log_responses']) {
            ob_start([$this, 'handleResponse']);
        }
    }

    /**
     * Log incoming request
     */
    private function logIncomingRequest(): void
    {
        $requestData = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'headers' => $this->getRequestHeaders(),
            'body' => $this->getRequestBody(),
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0,
            'user_id' => $this->getCurrentUserId(),
            'session_id' => session_id() ?: null,
            'request_id' => $this->getRequestId(),
            'timestamp' => date('Y-m-d H:i:s.u')
        ];

        $this->logger->logApiRequest(
            $requestData['method'],
            $requestData['uri'],
            $requestData['headers'],
            $requestData['body'],
            $requestData['user_id']
        );

        // Log detailed request info
        $this->logger->info('Incoming Request', $requestData, Logger::CHANNEL_API);
    }

    /**
     * Handle and log response
     */
    public function handleResponse(string $output): string
    {
        if (!$this->config['log_responses']) {
            return $output;
        }

        $executionTime = microtime(true) - $this->startTime;
        $statusCode = http_response_code();
        
        $responseData = [
            'status_code' => $statusCode,
            'content_length' => strlen($output),
            'content_type' => $this->getResponseContentType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'response_body' => $this->sanitizeResponseBody($output),
            'headers' => $this->getResponseHeaders(),
            'request_id' => $this->getRequestId(),
            'timestamp' => date('Y-m-d H:i:s.u')
        ];

        // Log API response
        $this->logger->logApiResponse($statusCode, $responseData, $executionTime);

        // Log performance metrics if enabled
        if ($this->config['log_performance']) {
            $this->logPerformanceMetrics($executionTime, $responseData);
        }

        // Log detailed response info
        $level = $statusCode >= 500 ? Logger::LEVEL_ERROR : 
                ($statusCode >= 400 ? Logger::LEVEL_WARNING : Logger::LEVEL_INFO);
        
        $this->logger->log($level, 'Outgoing Response', $responseData, Logger::CHANNEL_API);

        return $output;
    }

    /**
     * Log performance metrics
     */
    private function logPerformanceMetrics(float $executionTime, array $responseData): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        
        $metrics = [
            'response_size' => $responseData['content_length'],
            'status_code' => $responseData['status_code'],
            'method' => $method,
            'uri' => $uri
        ];

        $this->logger->logPerformance(
            "{$method} {$uri}",
            $executionTime,
            $metrics
        );

        // Log slow requests
        if ($executionTime > 2.0) {
            $this->logger->warning(
                "Slow Request Detected",
                array_merge($metrics, ['execution_time' => $executionTime]),
                Logger::CHANNEL_PERFORMANCE
            );
        }

        // Log high memory usage
        if ($responseData['memory_usage'] > 50 * 1024 * 1024) { // 50MB
            $this->logger->warning(
                "High Memory Usage Detected",
                [
                    'memory_usage' => $responseData['memory_usage'],
                    'peak_memory' => $responseData['peak_memory'],
                    'uri' => $uri,
                    'method' => $method
                ],
                Logger::CHANNEL_PERFORMANCE
            );
        }
    }

    /**
     * Log error with context
     */
    public function logError(string $errorCode, array $context = [], ?\Throwable $exception = null): void
    {
        if (!$this->config['log_errors']) {
            return;
        }

        $errorContext = array_merge($context, [
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
            'user_id' => $this->getCurrentUserId(),
            'request_id' => $this->getRequestId(),
            'execution_time' => microtime(true) - $this->startTime
        ]);

        $this->errorHandler->handleApplicationError($errorCode, $errorContext, $exception);
    }

    /**
     * Get request headers
     */
    private function getRequestHeaders(): array
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = $this->sanitizeHeaderValue($headerName, $value);
            }
        }

        return $headers;
    }

    /**
     * Get response headers
     */
    private function getResponseHeaders(): array
    {
        $headers = [];
        
        if (function_exists('headers_list')) {
            foreach (headers_list() as $header) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $headers[$name] = $this->sanitizeHeaderValue($name, $value);
                }
            }
        }

        return $headers;
    }

    /**
     * Get request body
     */
    private function getRequestBody(): array
    {
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // Handle JSON requests
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = file_get_contents('php://input');
            if ($rawBody && strlen($rawBody) <= $this->config['max_body_size']) {
                $decoded = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $body = $this->sanitizeArray($decoded);
                } else {
                    $body = ['raw' => '[INVALID_JSON]'];
                }
            } else {
                $body = ['raw' => '[BODY_TOO_LARGE]'];
            }
        }
        // Handle form data
        elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false ||
                strpos($contentType, 'multipart/form-data') !== false) {
            $body = $this->sanitizeArray($_POST);
        }

        return $body;
    }

    /**
     * Sanitize response body for logging
     */
    private function sanitizeResponseBody(string $output): array
    {
        if (strlen($output) > $this->config['max_body_size']) {
            return ['response' => '[RESPONSE_TOO_LARGE]'];
        }

        // Try to decode JSON response
        $decoded = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->sanitizeArray($decoded);
        }

        // For non-JSON responses, just indicate the type
        return ['response' => '[NON_JSON_RESPONSE]'];
    }

    /**
     * Get response content type
     */
    private function getResponseContentType(): ?string
    {
        $headers = $this->getResponseHeaders();
        return $headers['content-type'] ?? null;
    }

    /**
     * Sanitize header value
     */
    private function sanitizeHeaderValue(string $name, string $value): string
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];
        
        if (in_array(strtolower($name), $sensitiveHeaders)) {
            return '[REDACTED]';
        }

        return $value;
    }

    /**
     * Sanitize array recursively
     */
    private function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            } elseif (in_array(strtolower($key), $this->config['sensitive_fields'])) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get current user ID
     */
    private function getCurrentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get request ID
     */
    private function getRequestId(): string
    {
        if (!isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $_SERVER['HTTP_X_REQUEST_ID'] = uniqid('req_', true);
        }
        
        return $_SERVER['HTTP_X_REQUEST_ID'];
    }

    /**
     * Check if we should log this request
     */
    private function shouldLog(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Skip excluded paths
        foreach ($this->config['exclude_paths'] as $excludePath) {
            if (strpos($uri, $excludePath) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get execution time since middleware start
     */
    public function getExecutionTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Add custom context to logs
     */
    public function addContext(string $key, $value): void
    {
        if (!isset($_SERVER['LOG_CONTEXT'])) {
            $_SERVER['LOG_CONTEXT'] = [];
        }
        
        $_SERVER['LOG_CONTEXT'][$key] = $value;
    }

    /**
     * Get custom context
     */
    public function getContext(): array
    {
        return $_SERVER['LOG_CONTEXT'] ?? [];
    }
}