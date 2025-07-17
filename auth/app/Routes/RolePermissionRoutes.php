<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\RolePermissionController;

/**
 * Role and Permission API Routes
 */
class RolePermissionRoutes
{
    private RolePermissionController $controller;

    public function __construct()
    {
        $this->controller = new RolePermissionController();
    }

    /**
     * Handle role and permission routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api prefix from path
        $path = preg_replace('#^/api#', '', $path);
        
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
        switch (true) {
            case preg_match('#^/permissions/user/(\d+)$#', $path, $matches):
                $userId = (int)$matches[1];
                $this->controller->getUserPermissions($userId);
                break;
            case $path === '/roles/hierarchy':
                $this->controller->getRoleHierarchy();
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
            case '/permissions/check':
                $this->controller->checkPermission();
                break;
            case '/permissions/check-multiple':
                $this->controller->checkMultiplePermissions();
                break;
            case '/permissions/grant':
                $this->controller->grantPermission();
                break;
            case '/permissions/revoke':
                $this->controller->revokePermission();
                break;
            case '/roles/validate':
                $this->controller->validateRole();
                break;
            case '/roles/can-manage':
                $this->controller->canManageRole();
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
            'error' => 'Role/Permission endpoint not found',
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