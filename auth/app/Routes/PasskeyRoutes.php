<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\PasskeyController;

/**
 * Passkey API Routes
 */
class PasskeyRoutes
{
    private PasskeyController $controller;

    public function __construct()
    {
        $this->controller = new PasskeyController();
    }

    /**
     * Register all passkey routes
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

        // Route matching
        switch ($requestMethod) {
            case 'POST':
                $this->handlePostRoutes($path);
                break;
            case 'GET':
                $this->handleGetRoutes($path);
                break;
            case 'PUT':
                $this->handlePutRoutes($path);
                break;
            case 'DELETE':
                $this->handleDeleteRoutes($path);
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
            case '/passkeys/register/begin':
                $this->controller->beginRegistration();
                break;
            case '/passkeys/register/complete':
                $this->controller->completeRegistration();
                break;
            case '/passkeys/authenticate/begin':
                $this->controller->beginAuthentication();
                break;
            case '/passkeys/authenticate/complete':
                $this->controller->completeAuthentication();
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
        if ($path === '/passkeys/devices') {
            $this->controller->getDevices();
        } elseif (preg_match('/^\/passkeys\/devices\/(\d+)\/security$/', $path, $matches)) {
            $deviceId = (int)$matches[1];
            $this->controller->getDeviceSecurityStatus($deviceId);
        } else {
            $this->sendNotFound();
        }
    }

    /**
     * Handle PUT routes
     */
    private function handlePutRoutes(string $path): void
    {
        if (preg_match('/^\/passkeys\/devices\/(\d+)$/', $path, $matches)) {
            $deviceId = (int)$matches[1];
            $this->controller->updateDevice($deviceId);
        } else {
            $this->sendNotFound();
        }
    }

    /**
     * Handle DELETE routes
     */
    private function handleDeleteRoutes(string $path): void
    {
        if ($path === '/passkeys/devices/bulk') {
            $this->controller->bulkRemoveDevices();
        } elseif (preg_match('/^\/passkeys\/devices\/(\d+)$/', $path, $matches)) {
            $deviceId = (int)$matches[1];
            $this->controller->removeDevice($deviceId);
        } else {
            $this->sendNotFound();
        }
    }

    /**
     * Handle OPTIONS request for CORS
     */
    private function handleOptionsRequest(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
            'POST /api/passkeys/register/begin' => [
                'description' => 'Generate registration challenge for new passkey',
                'auth_required' => true,
                'request_body' => null,
                'response' => 'Registration options for WebAuthn'
            ],
            'POST /api/passkeys/register/complete' => [
                'description' => 'Complete passkey registration',
                'auth_required' => true,
                'request_body' => [
                    'id' => 'string (credential ID)',
                    'rawId' => 'string (raw credential ID)',
                    'response' => [
                        'clientDataJSON' => 'string',
                        'attestationObject' => 'string'
                    ],
                    'type' => 'string (public-key)',
                    'deviceName' => 'string'
                ],
                'response' => 'Registration result with passkey ID'
            ],
            'POST /api/passkeys/authenticate/begin' => [
                'description' => 'Generate authentication challenge',
                'auth_required' => false,
                'request_body' => [
                    'email' => 'string'
                ],
                'response' => 'Authentication options for WebAuthn'
            ],
            'POST /api/passkeys/authenticate/complete' => [
                'description' => 'Complete passkey authentication',
                'auth_required' => false,
                'request_body' => [
                    'id' => 'string (credential ID)',
                    'rawId' => 'string (raw credential ID)',
                    'response' => [
                        'clientDataJSON' => 'string',
                        'authenticatorData' => 'string',
                        'signature' => 'string',
                        'userHandle' => 'string (optional)'
                    ],
                    'type' => 'string (public-key)'
                ],
                'response' => 'Authentication result with tokens and session'
            ],
            'GET /api/passkeys/devices' => [
                'description' => 'Get user\'s passkey devices',
                'auth_required' => true,
                'request_body' => null,
                'response' => 'List of user devices with metadata'
            ],
            'PUT /api/passkeys/devices/{deviceId}' => [
                'description' => 'Update device name',
                'auth_required' => true,
                'request_body' => [
                    'device_name' => 'string'
                ],
                'response' => 'Update confirmation'
            ],
            'DELETE /api/passkeys/devices/{deviceId}' => [
                'description' => 'Remove a passkey device',
                'auth_required' => true,
                'request_body' => null,
                'response' => 'Removal confirmation'
            ],
            'GET /api/passkeys/devices/{deviceId}/security' => [
                'description' => 'Get device security status',
                'auth_required' => true,
                'request_body' => null,
                'response' => 'Security analysis and recommendations'
            ],
            'DELETE /api/passkeys/devices/bulk' => [
                'description' => 'Remove multiple devices',
                'auth_required' => true,
                'request_body' => [
                    'device_ids' => 'array of integers'
                ],
                'response' => 'Bulk removal results'
            ]
        ];
    }
}