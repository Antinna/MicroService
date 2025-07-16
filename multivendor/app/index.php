<?php

// Bootstrap the application
$config = require_once __DIR__ . '/bootstrap/app.php';

// Set content type
header('Content-Type: application/json');

// Basic routing (will be expanded later)
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove query string from URI
$uri = parse_url($requestUri, PHP_URL_PATH);

// Basic health check endpoint
if ($uri === '/health' && $requestMethod === 'GET') {
    echo json_encode([
        'status' => 'healthy',
        'service' => $config->get('name'),
        'version' => $config->get('version'),
        'timestamp' => date('c')
    ]);
    exit;
}

// Basic info endpoint
if ($uri === '/info' && $requestMethod === 'GET') {
    echo json_encode([
        'service' => $config->get('name'),
        'version' => $config->get('version'),
        'environment' => $config->get('environment'),
        'php_version' => PHP_VERSION,
        'timestamp' => date('c')
    ]);
    exit;
}

// Default response
http_response_code(404);
echo json_encode([
    'error' => 'Endpoint not found',
    'message' => 'The requested endpoint does not exist',
    'timestamp' => date('c')
]);