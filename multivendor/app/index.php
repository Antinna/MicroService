<?php

// Bootstrap the application
$config = require_once __DIR__ . '/bootstrap/app.php';

use Antinna\Multivendor\Helpers\ErrorHandlingHelper;

// Set up global error handlers
ErrorHandlingHelper::setupGlobalHandlers();

// Set content type
header('Content-Type: application/json');

// Wrap the entire request with logging middleware
ErrorHandlingHelper::withLogging(function() use ($config) {
    // Basic routing (will be expanded later)
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Remove query string from URI
    $uri = parse_url($requestUri, PHP_URL_PATH);

    // Basic routing with error handling
    ErrorHandlingHelper::handleApiResponse(function() use ($uri, $requestMethod, $config) {
        // Basic health check endpoint
        if ($uri === '/health' && $requestMethod === 'GET') {
            return [
                'status' => 'healthy',
                'service' => $config->get('name'),
                'version' => $config->get('version'),
                'timestamp' => date('c')
            ];
        }

        // Basic info endpoint
        if ($uri === '/info' && $requestMethod === 'GET') {
            return [
                'service' => $config->get('name'),
                'version' => $config->get('version'),
                'environment' => $config->get('environment'),
                'php_version' => PHP_VERSION,
                'timestamp' => date('c')
            ];
        }

        // Load route files
        if (strpos($uri, '/api/vendors') === 0) {
            require_once __DIR__ . '/Routes/VendorRoutes.php';
            return null; // Route file handles response
        } elseif (strpos($uri, '/api/products') === 0) {
            require_once __DIR__ . '/Routes/ProductRoutes.php';
            return null; // Route file handles response
        } elseif (strpos($uri, '/api/subscriptions') === 0) {
            require_once __DIR__ . '/Routes/SubscriptionRoutes.php';
            return null; // Route file handles response
        }

        // Default response
        http_response_code(404);
        return [
            'error' => 'Endpoint not found',
            'message' => 'The requested endpoint does not exist',
            'timestamp' => date('c')
        ];
    });
});