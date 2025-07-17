<?php

/**
 * Health Check Endpoint
 * 
 * This file provides a simple entry point for health checks
 * that can be used by load balancers, monitoring systems, and container orchestrators.
 */

// Set error reporting for health checks
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Set execution time limit for health checks
set_time_limit(30);

// Include the application bootstrap
require_once __DIR__ . '/bootstrap/app.php';

// Include health routes
require_once __DIR__ . '/Routes/HealthRoutes.php';

use Antinna\Auth\Routes\HealthRoutes;
use Antinna\Auth\Services\Logger;

try {
    // Initialize health routes
    $healthRoutes = new HealthRoutes();
    $healthRoutes->register();
    
    // If we reach here, no route was matched
    http_response_code(404);
    header('Content-Type: application/json');
    
    echo json_encode([
        'error' => 'Health check endpoint not found',
        'available_endpoints' => [
            '/health' => 'Basic health check',
            '/health/detailed' => 'Detailed health information',
            '/health/ready' => 'Readiness probe',
            '/health/live' => 'Liveness probe',
            '/health/metrics' => 'Service metrics',
            '/health/info' => 'Service information',
            '/healthz' => 'Kubernetes-style health check',
            '/status' => 'Alternative status endpoint',
            '/ping' => 'Simple ping test'
        ],
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    // Log the error
    try {
        $logger = new Logger();
        $logger->critical('Health check system failure', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } catch (Throwable $logError) {
        // If logging fails, write to error log
        error_log('Health check critical failure: ' . $e->getMessage());
        error_log('Logging also failed: ' . $logError->getMessage());
    }

    // Return error response
    http_response_code(503);
    header('Content-Type: application/json');
    
    echo json_encode([
        'status' => 'unhealthy',
        'error' => 'Health check system failure',
        'timestamp' => date('c'),
        'message' => 'The health check system encountered a critical error'
    ], JSON_PRETTY_PRINT);
}