<?php

require_once __DIR__ . '/bootstrap/app.php';

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Routes\PasskeyRoutes;
use Antinna\Auth\Routes\MagicLinkRoutes;
use Antinna\Auth\Routes\ServiceTokenRoutes;
use Antinna\Auth\Routes\AdminRoutes;

// Initialize environment
Environment::load();

// Set CORS headers for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Basic routing
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string from URI
$uri = parse_url($requestUri, PHP_URL_PATH);

// Basic health check endpoint
if ($uri === '/health' && $requestMethod === 'GET') {
    echo json_encode([
        'success' => true,
        'service' => 'auth-service',
        'status' => 'healthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0.0'
    ]);
    exit();
}

// Basic info endpoint
if ($uri === '/info' && $requestMethod === 'GET') {
    echo json_encode([
        'success' => true,
        'service' => 'auth-service',
        'description' => 'Authentication microservice for the platform',
        'version' => '1.0.0',
        'endpoints' => [
            'GET /health' => 'Health check',
            'GET /info' => 'Service information',
            'GET /api/docs' => 'API documentation',
            'POST /auth/login' => 'User login',
            'POST /auth/register' => 'User registration',
            'POST /internal/validate-token' => 'Token validation (internal)',
            'Passkey APIs' => 'See /api/docs for complete passkey endpoints',
            'Magic Link APIs' => 'See /api/docs for complete magic link endpoints',
            'Service Token APIs' => 'See /api/docs for complete service token endpoints',
            'Admin APIs' => 'See /api/docs for complete admin endpoints'
        ]
    ]);
    exit();
}

// API documentation endpoint
if ($uri === '/api/docs' && $requestMethod === 'GET') {
    echo json_encode([
        'success' => true,
        'service' => 'auth-service',
        'description' => 'Complete API documentation',
        'version' => '1.0.0',
        'passkey_endpoints' => PasskeyRoutes::getRoutes(),
        'magic_link_endpoints' => MagicLinkRoutes::getRoutes(),
        'magic_link_examples' => MagicLinkRoutes::getExamples(),
        'authentication' => [
            'methods' => ['Session-based', 'JWT Bearer token'],
            'session' => 'Include session cookie or set $_SESSION[\'user_id\']',
            'jwt' => 'Include Authorization: Bearer <token> header'
        ],
        'response_format' => [
            'success_response' => [
                'success' => true,
                'message' => 'string',
                'data' => 'object',
                'timestamp' => 'ISO 8601 datetime'
            ],
            'error_response' => [
                'success' => false,
                'error' => 'string',
                'code' => 'string',
                'timestamp' => 'ISO 8601 datetime'
            ]
        ]
    ]);
    exit();
}

// Handle passkey API routes
if (strpos($uri, '/api/passkeys') === 0) {
    $passkeyRoutes = new PasskeyRoutes();
    $passkeyRoutes->register();
    exit();
}

// Handle magic link API routes
if (strpos($uri, '/api/magic-link') === 0 || strpos($uri, '/auth/magic-link') === 0) {
    $magicLinkRoutes = new MagicLinkRoutes();
    $magicLinkRoutes->register();
    exit();
}

// Handle service token API routes
if (strpos($uri, '/api/service-tokens') === 0 || strpos($uri, '/api/services') === 0 || strpos($uri, '/api/api-keys') === 0) {
    $serviceTokenRoutes = new ServiceTokenRoutes();
    $serviceTokenRoutes->handleRequest($requestMethod, $uri);
    exit();
}

// Handle admin API routes
if (strpos($uri, '/api/admin') === 0) {
    $adminRoutes = new AdminRoutes();
    $adminRoutes->handleRequest($requestMethod, $uri);
    exit();
}

// Default response for unmatched routes
http_response_code(404);
echo json_encode([
    'success' => false,
    'error' => [
        'code' => 'AUTH_404',
        'message' => 'Endpoint not found',
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);