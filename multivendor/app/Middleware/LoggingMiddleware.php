<?php

namespace Antinna\Multivendor\Middleware;

use Antinna\Multivendor\Services\Logger;

class LoggingMiddleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(callable $next)
    {
        $startTime = microtime(true);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Log incoming request
        $this->logger->logApiRequest($method, $uri, $this->getRequestParams());

        try {
            // Execute the request
            $response = $next();
            
            // Log successful response
            $executionTime = microtime(true) - $startTime;
            $statusCode = http_response_code() ?: 200;
            
            $this->logger->logApiResponse($uri, $statusCode, $executionTime, $response);
            
            return $response;
            
        } catch (\Throwable $e) {
            // Log error response
            $executionTime = microtime(true) - $startTime;
            $statusCode = http_response_code() ?: 500;
            
            $this->logger->logApiResponse($uri, $statusCode, $executionTime);
            
            // Re-throw the exception to be handled by error handler
            throw $e;
        }
    }

    private function getRequestParams(): array
    {
        $params = [];
        
        // Get query parameters
        if (!empty($_GET)) {
            $params['query'] = $_GET;
        }
        
        // Get POST data
        if (!empty($_POST)) {
            $params['post'] = $_POST;
        }
        
        // Get JSON body
        $input = file_get_contents('php://input');
        if ($input && $this->isJson($input)) {
            $params['json'] = json_decode($input, true);
        }
        
        return $params;
    }

    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}