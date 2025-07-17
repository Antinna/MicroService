<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\AdminController;

/**
 * Admin Security API Routes
 */
class AdminRoutes
{
    private AdminController $adminController;

    public function __construct()
    {
        $this->adminController = new AdminController();
    }

    /**
     * Handle admin routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api/admin prefix from path
        $path = preg_replace('#^/api/admin#', '', $path);
        
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
        switch (true) {
            case $path === '/dashboard':
                $this->adminController->getDashboard();
                break;
            case $path === '/users':
                $this->adminController->getUsers();
                break;
            case preg_match('#^/users/(\d+)$#', $path, $matches):
                $userId = (int)$matches[1];
                $this->adminController->getUser($userId);
                break;
            case $path === '/security/metrics':
                $this->adminController->getSecurityMetrics();
                break;
            case $path === '/security/policies':
                $this->adminController->getSecurityPolicies();
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
        if (preg_match('#^/users/(\d+)/reset-password$#', $path, $matches)) {
            $userId = (int)$matches[1];
            $this->adminController->resetUserPassword($userId);
        } else {
            $this->sendNotFound();
        }
    }

    /**
     * Handle PUT requests
     */
    private function handlePutRequest(string $path): void
    {
        switch (true) {
            case preg_match('#^/users/(\d+)/status$#', $path, $matches):
                $userId = (int)$matches[1];
                $this->adminController->updateUserStatus($userId);
                break;
            case $path === '/security/policies':
                $this->adminController->updateSecurityPolicies();
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
        if (preg_match('#^/users/(\d+)/sessions$#', $path, $matches)) {
            $userId = (int)$matches[1];
            $this->adminController->revokeUserSessions($userId);
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
            'error' => 'Admin endpoint not found',
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