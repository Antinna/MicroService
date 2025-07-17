<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\UserController;

/**
 * User Management API Routes
 */
class UserRoutes
{
    private UserController $userController;

    public function __construct()
    {
        $this->userController = new UserController();
    }

    /**
     * Handle user management routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api/users prefix from path
        $path = preg_replace('#^/api/users#', '', $path);
        
        switch ($method) {
            case 'GET':
                $this->handleGetRequest($path);
                break;
            case 'POST':
                $this->handlePostRequest($path);
                break;
            case 'PUT':
                $this->handlePutRequest($path);
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
            case '/profile':
                $this->userController->getProfile();
                break;
            case '/security':
                $this->userController->getSecuritySettings();
                break;
            case '/sessions':
                $this->userController->getSessions();
                break;
            case '/activity':
                $this->userController->getActivityLog();
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
            case '/password':
                $this->userController->changePassword();
                break;
            default:
                $this->sendNotFound();
        }
    }

    /**
     * Handle PUT requests
     */
    private function handlePutRequest(string $path): void
    {
        switch ($path) {
            case '/profile':
                $this->userController->updateProfile();
                break;
            case '/security':
                $this->userController->updateSecuritySettings();
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
            $this->userController->revokeAllSessions();
        } elseif (preg_match('#^/sessions/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            $sessionId = $matches[1];
            $this->userController->revokeSession($sessionId);
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
}