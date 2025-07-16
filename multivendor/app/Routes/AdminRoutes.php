<?php

use Antinna\Multivendor\Controllers\AdminController;
use Antinna\Multivendor\Helpers\ErrorHandlingHelper;

// Get the request URI and method
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Initialize admin controller
$adminController = new AdminController();

// Admin panel routes
switch (true) {
    // Admin panel HTML interface
    case $uri === '/admin' && $method === 'GET':
        $adminController->panel();
        break;
        
    // Admin authentication endpoints
    case $uri === '/admin/login' && $method === 'POST':
        $adminController->login();
        break;
        
    case $uri === '/admin/logout' && $method === 'POST':
        $adminController->logout();
        break;
        
    case $uri === '/admin/session' && $method === 'GET':
        $adminController->sessionInfo();
        break;
        
    case $uri === '/admin/extend-session' && $method === 'POST':
        $adminController->extendSession();
        break;
        
    case $uri === '/admin/validate' && $method === 'GET':
        $adminController->validateAuth();
        break;
        
    case $uri === '/admin/dashboard' && $method === 'GET':
        $adminController->dashboard();
        break;
        
    case $uri === '/admin/credentials-info' && $method === 'GET':
        $adminController->credentialsInfo();
        break;
        
    // Migration orchestration endpoints
    case $uri === '/admin/migration/start' && $method === 'POST':
        $adminController->startMigration();
        break;
        
    case preg_match('#^/admin/migration/progress/([^/]+)$#', $uri, $matches) && $method === 'GET':
        $adminController->getMigrationProgress($matches[1]);
        break;
        
    case preg_match('#^/admin/migration/progress/([^/]+)/stream$#', $uri, $matches) && $method === 'GET':
        $adminController->migrationProgressStream($matches[1]);
        break;
        
    case preg_match('#^/admin/migration/cancel/([^/]+)$#', $uri, $matches) && $method === 'POST':
        $adminController->cancelMigration($matches[1]);
        break;
        
    case $uri === '/admin/migration/history' && $method === 'GET':
        $adminController->getMigrationHistory();
        break;
        
    case $uri === '/admin/health' && $method === 'GET':
        $adminController->checkServiceHealth();
        break;
        
    case $uri === '/admin/health/stream' && $method === 'GET':
        $adminController->healthMonitoringStream();
        break;
        
    // Default admin route handler
    default:
        ErrorHandlingHelper::handleApiResponse(function() use ($uri) {
            http_response_code(404);
            return [
                'error' => 'Admin endpoint not found',
                'message' => "The admin endpoint '{$uri}' does not exist",
                'available_endpoints' => [
                    'GET /admin' => 'Admin panel interface',
                    'POST /admin/login' => 'Admin login',
                    'POST /admin/logout' => 'Admin logout',
                    'GET /admin/session' => 'Get session info',
                    'POST /admin/extend-session' => 'Extend session',
                    'GET /admin/validate' => 'Validate authentication',
                    'GET /admin/dashboard' => 'Admin dashboard',
                    'GET /admin/credentials-info' => 'Get credentials info'
                ]
            ];
        });
        break;
}