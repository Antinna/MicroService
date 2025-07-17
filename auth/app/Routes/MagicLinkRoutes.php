<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\MagicLinkController;

/**
 * Magic Link API Routes
 */
class MagicLinkRoutes
{
    private MagicLinkController $controller;

    public function __construct()
    {
        $this->controller = new MagicLinkController();
    }

    /**
     * Register all magic link routes
     */
    public function register(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];
        
        // Remove query string and normalize path
        $path = strtok($requestUri, '?');
        $path = rtrim($path, '/');
        
        // Remove base path if present
        $basePath = '/api';
        if (strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }

        // Also handle direct magic link verification (without /api prefix)
        if (strpos($requestUri, '/auth/magic-link/verify') === 0) {
            $this->controller->verifyMagicLink();
            return;
        }

        // Route matching
        switch ($requestMethod) {
            case 'POST':
                $this->handlePostRoutes($path);
                break;
            case 'GET':
                $this->handleGetRoutes($path);
                break;
            case 'OPTIONS':
                $this->handleOptionsRequest();
                break;
            default:
                $this->sendMethodNotAllowed();
                break;
        }
    }

    /**
     * Handle POST routes
     */
    private function handlePostRoutes(string $path): void
    {
        switch ($path) {
            case '/magic-link/request':
                $this->controller->requestMagicLink();
                break;
            case '/magic-link/revoke':
                $this->controller->revokeMagicLinks();
                break;
            default:
                $this->sendNotFound();
                break;
        }
    }

    /**
     * Handle GET routes
     */
    private function handleGetRoutes(string $path): void
    {
        switch ($path) {
            case '/magic-link/verify':
                $this->controller->verifyMagicLink();
                break;
            case '/magic-link/status':
                $this->controller->getMagicLinkStatus();
                break;
            default:
                $this->sendNotFound();
                break;
        }
    }

    /**
     * Handle OPTIONS request for CORS
     */
    private function handleOptionsRequest(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        http_response_code(200);
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
            'error' => 'Endpoint not found',
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

    /**
     * Get all available routes for documentation
     */
    public static function getRoutes(): array
    {
        return [
            'POST /api/magic-link/request' => [
                'description' => 'Request a magic link for passwordless authentication',
                'auth_required' => false,
                'request_body' => [
                    'email' => 'string (required)',
                    'expires_in_minutes' => 'integer (optional, 1-60, default: 15)',
                    'redirect_url' => 'string (optional, valid URL)',
                    'mobile' => 'boolean (optional, for mobile deep linking)',
                    'app_scheme' => 'string (optional, mobile app scheme)',
                    'email_subject' => 'string (optional, custom email subject)'
                ],
                'response' => 'Magic link request confirmation'
            ],
            'GET /api/magic-link/verify' => [
                'description' => 'Verify magic link token and authenticate user',
                'auth_required' => false,
                'query_params' => [
                    'token' => 'string (required, magic link token)',
                    'redirect' => 'string (optional, redirect URL after success)',
                    'mobile' => 'string (optional, "1" for mobile response)',
                    'app_scheme' => 'string (optional, mobile app scheme)'
                ],
                'response' => 'Authentication result with tokens/session or redirect'
            ],
            'GET /auth/magic-link/verify' => [
                'description' => 'Direct magic link verification (from email)',
                'auth_required' => false,
                'query_params' => [
                    'token' => 'string (required, magic link token)',
                    'redirect' => 'string (optional, redirect URL after success)',
                    'mobile' => 'string (optional, "1" for mobile response)'
                ],
                'response' => 'HTML success/error page or mobile redirect'
            ],
            'GET /api/magic-link/status' => [
                'description' => 'Get user\'s magic link statistics',
                'auth_required' => true,
                'request_body' => null,
                'response' => 'Magic link usage statistics'
            ],
            'POST /api/magic-link/revoke' => [
                'description' => 'Revoke all active magic links for user',
                'auth_required' => true,
                'request_body' => null,
                'response' => 'Revocation confirmation with count'
            ]
        ];
    }

    /**
     * Get magic link request examples
     */
    public static function getExamples(): array
    {
        return [
            'basic_request' => [
                'description' => 'Basic magic link request',
                'method' => 'POST',
                'url' => '/api/magic-link/request',
                'body' => [
                    'email' => 'user@example.com'
                ]
            ],
            'custom_expiration' => [
                'description' => 'Magic link with custom expiration',
                'method' => 'POST',
                'url' => '/api/magic-link/request',
                'body' => [
                    'email' => 'user@example.com',
                    'expires_in_minutes' => 30,
                    'redirect_url' => 'https://app.example.com/dashboard'
                ]
            ],
            'mobile_request' => [
                'description' => 'Mobile app magic link request',
                'method' => 'POST',
                'url' => '/api/magic-link/request',
                'body' => [
                    'email' => 'user@example.com',
                    'mobile' => true,
                    'app_scheme' => 'myapp',
                    'email_subject' => 'Sign in to MyApp'
                ]
            ],
            'verification' => [
                'description' => 'Magic link verification',
                'method' => 'GET',
                'url' => '/api/magic-link/verify?token=abc123&redirect=https://app.example.com/dashboard'
            ],
            'mobile_verification' => [
                'description' => 'Mobile magic link verification',
                'method' => 'GET',
                'url' => '/auth/magic-link/verify?token=abc123&mobile=1&app_scheme=myapp'
            ]
        ];
    }
}