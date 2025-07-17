<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\TokenValidationController;

/**
 * Token Validation API Routes
 */
class TokenValidationRoutes
{
    private TokenValidationController $controller;

    public function __construct()
    {
        $this->controller = new TokenValidationController();
    }

    /**
     * Handle token validation routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api/validate prefix from path
        $path = preg_replace('#^/api/validate#', '', $path);
        
        switch ($method) {
            case 'GET':
                $this->handleGetRequest($path);
                break;
            case 'POST':
                $this->handlePostRequest($path);
                break;
            default:
                $this->sendMethodNotAllowed();
        }
    }

    /**
     * Handle GET requests
     */
    private function handleGetRequest(string $path): void
    {
        switch ($path) {
            case '/health':
                $this->controller->healthCheck();
                break;
            case '/stats':
                $this->controller->getValidationStats();
                break;
            default:
                $this->sendNotFound();
        }
    }

    /**
     * Handle POST requests
     */
    private function handlePostRequest(string $path): void
    {
        switch ($path) {
            case '/token':
                $this->controller->validateToken();
                break;
            case '/user':
                $this->controller->validateUser();
                break;
            case '/service':
                $this->controller->validateService();
                break;
            case '/batch':
                $this->controller->validateBatch();
                break;
            case '/inspect':
                $this->controller->inspectToken();
                break;
            case '/cache/clear':
                $this->controller->clearCache();
                break;
            default:
                $this->sendNotFound();
        }
    }

    /**
     * Send 404 Not Found response
     */
    private function sendNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Token validation endpoint not found',
            'code' => 'NOT_FOUND',
            'timestamp' => date('c')
        ]);
    }

    /**
     * Send 405 Method Not Allowed response
     */
    private function sendMethodNotAllowed(): void
    {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
            'code' => 'METHOD_NOT_ALLOWED',
            'timestamp' => date('c')
        ]);
    }
}