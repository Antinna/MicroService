<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\SecurityDashboardController;

/**
 * Security Dashboard API Routes
 */
class SecurityRoutes
{
    private SecurityDashboardController $securityController;

    public function __construct()
    {
        $this->securityController = new SecurityDashboardController();
    }

    /**
     * Handle security dashboard routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api/security prefix from path
        $path = preg_replace('#^/api/security#', '', $path);
        
        switch ($method) {
            case 'GET':
                $this->handleGetRequest($path);
                break;
            case 'DELETE':
                $this->handleDeleteRequest($path);
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
            case '/dashboard':
                $this->securityController->getDashboard();
                break;
            case '/login-history':
                $this->securityController->getLoginHistory();
                break;
            case '/sessions':
                $this->securityController->getActiveSessions();
                break;
            case '/events':
                $this->securityController->getSecurityEvents();
                break;
            case '/metrics':
                $this->securityController->getSecurityMetrics();
                break;
            default:
                $this->sendNotFound();
        }
    }

    /**
     * Handle DELETE requests
     */
    private function handleDeleteRequest(string $path): void
    {
        if ($path === '/sessions') {
            $this->securityController->revokeAllSessions();
        } elseif (preg_match('#^/sessions/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            $sessionId = $matches[1];
            $this->securityController->revokeSession($sessionId);
        } else {
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
            'error' => 'Security endpoint not found',
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