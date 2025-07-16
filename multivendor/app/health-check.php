<?php

/**
 * Health check script for deployment verification
 * This script is used by Wasmer and monitoring systems to verify service health
 */

require_once __DIR__ . '/bootstrap/app.php';

use Antinna\Multivendor\Services\ServiceHealthChecker;
use Antinna\Multivendor\Services\Logger;

// Set up error handling
set_error_handler(function($severity, $message, $file, $line) {
    error_log("Health check error: $message in $file:$line");
    return true;
});

set_exception_handler(function($exception) {
    error_log("Health check exception: " . $exception->getMessage());
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'error' => 'Health check failed',
        'timestamp' => date('c')
    ]);
    exit(1);
});

try {
    $logger = new Logger();
    $healthChecker = new ServiceHealthChecker($logger);
    
    // Perform comprehensive health check
    $health = $healthChecker->checkServiceHealth('multivendor', false);
    
    // Check if service is healthy
    if ($health['status'] === 'healthy') {
        http_response_code(200);
        
        $response = [
            'status' => 'healthy',
            'service' => 'multivendor',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'checks' => $health['checks'] ?? [],
            'uptime' => $health['uptime'] ?? 'unknown'
        ];
        
        // Add system information
        $systemInfo = $healthChecker->getSystemInfo();
        $response['system'] = [
            'php_version' => $systemInfo['php']['version'],
            'memory_usage' => $systemInfo['memory']['current_usage'],
            'memory_limit' => $systemInfo['memory']['limit']
        ];
        
        echo json_encode($response);
        exit(0);
        
    } else {
        http_response_code(503);
        
        $response = [
            'status' => 'unhealthy',
            'service' => 'multivendor',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'error' => $health['error'] ?? 'Service health check failed',
            'checks' => $health['checks'] ?? []
        ];
        
        echo json_encode($response);
        exit(1);
    }
    
} catch (Exception $e) {
    http_response_code(503);
    
    echo json_encode([
        'status' => 'unhealthy',
        'service' => 'multivendor',
        'version' => '1.0.0',
        'timestamp' => date('c'),
        'error' => 'Health check exception: ' . $e->getMessage()
    ]);
    
    exit(1);
}