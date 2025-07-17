<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\ServiceTokenController;

/**
 * Service Token API Routes
 */
class ServiceTokenRoutes
{
    private ServiceTokenController $controller;

    public function __construct()
    {
        $this->controller = new ServiceTokenController();
    }

    /**
     * Handle service token routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove common prefixes from path
        $path = preg_replace('#^/api/(service-tokens|services|api-keys)#', '', $path);
        
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
            case 'OPTIONS':
                $this->handleOptionsRequest();
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
            case $path === '' || $path === '/':
                // GET /api/services - List all services
                $this->controller->listServices();
                break;
            case preg_match('#^/([a-zA-Z0-9_-]+)$#', $path, $matches):
                // GET /api/services/{serviceId} - Get service info
                $serviceId = $matches[1];
                $this->controller->getServiceInfo($serviceId);
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
            case '/generate':
                // POST /api/service-tokens/generate
                $this->controller->generateToken();
                break;
            case '/validate':
                // POST /api/service-tokens/validate
                $this->controller->validateToken();
                break;
            case '/revoke':
                // POST /api/service-tokens/revoke
                $this->controller->revokeToken();
                break;
            case '/register':
                // POST /api/services/register
                $this->controller->registerService();
                break;
            default:
                // Check for API key endpoints
                $this->handleApiKeyPostRequest($path);
        }
    }

    /**
     * Handle API key POST requests
     */
    private function handleApiKeyPostRequest(string $path): void
    {
        switch ($path) {
            case '/generate':
                // POST /api/api-keys/generate
                $this->controller->generateApiKey();
                break;
            case '/validate':
                // POST /api/api-keys/validate
                $this->controller->validateApiKey();
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
        // Currently no PUT endpoints defined
        $this->sendNotFound();
    }

    /**
     * Handle DELETE requests
     */
    private function handleDeleteRequest(string $path): void
    {
        // Currently no DELETE endpoints defined
        $this->sendNotFound();
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
            'error' => 'Service token endpoint not found',
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
            'POST /api/service-tokens/generate' => [
                'description' => 'Generate service token for service-to-service authentication',
                'auth_required' => true,
                'auth_type' => 'service_admin',
                'request_body' => [
                    'service_id' => 'string (target service identifier)',
                    'scopes' => 'array of strings (optional, default: service_access)',
                    'expiration_time' => 'integer (seconds, max 86400, default: 3600)'
                ],
                'response' => 'Service token with metadata'
            ],
            'POST /api/service-tokens/validate' => [
                'description' => 'Validate service token',
                'auth_required' => false,
                'request_body' => [
                    'token' => 'string (service token to validate)',
                    'expected_service' => 'string (optional, expected service ID)',
                    'required_scopes' => 'array of strings (optional, required scopes)'
                ],
                'response' => 'Validation result with token metadata'
            ],
            'POST /api/service-tokens/revoke' => [
                'description' => 'Revoke service token',
                'auth_required' => true,
                'auth_type' => 'service_admin',
                'request_body' => [
                    'token_id' => 'string (token ID to revoke)',
                    'reason' => 'string (optional, revocation reason)'
                ],
                'response' => 'Revocation confirmation'
            ],
            'POST /api/services/register' => [
                'description' => 'Register new service',
                'auth_required' => true,
                'auth_type' => 'super_admin',
                'request_body' => [
                    'service_id' => 'string (unique service identifier)',
                    'config' => [
                        'name' => 'string (service display name)',
                        'description' => 'string (service description)',
                        'allowed_scopes' => 'array of strings (allowed scopes for this service)'
                    ]
                ],
                'response' => 'Registration confirmation'
            ],
            'GET /api/services' => [
                'description' => 'List all registered services',
                'auth_required' => true,
                'auth_type' => 'service',
                'request_body' => null,
                'response' => 'List of services with metadata'
            ],
            'GET /api/services/{serviceId}' => [
                'description' => 'Get service information',
                'auth_required' => true,
                'auth_type' => 'service',
                'request_body' => null,
                'response' => 'Service information and configuration'
            ],
            'POST /api/api-keys/generate' => [
                'description' => 'Generate API key for external integration',
                'auth_required' => true,
                'auth_type' => 'service_admin',
                'request_body' => [
                    'service_id' => 'string (target service identifier)',
                    'scopes' => 'array of strings (optional, default: api_read)',
                    'expiration_days' => 'integer (days, max 1095, default: 365)'
                ],
                'response' => 'API key with metadata'
            ],
            'POST /api/api-keys/validate' => [
                'description' => 'Validate API key',
                'auth_required' => false,
                'request_body' => [
                    'api_key' => 'string (API key to validate)',
                    'required_scopes' => 'array of strings (optional, required scopes)'
                ],
                'response' => 'Validation result with key metadata'
            ]
        ];
    }

    /**
     * Get examples for documentation
     */
    public static function getExamples(): array
    {
        return [
            'generate_service_token' => [
                'description' => 'Generate a service token for payment service',
                'request' => [
                    'method' => 'POST',
                    'url' => '/api/service-tokens/generate',
                    'headers' => [
                        'Authorization' => 'Bearer {admin_service_token}',
                        'Content-Type' => 'application/json'
                    ],
                    'body' => [
                        'service_id' => 'payment-service',
                        'scopes' => ['service_access', 'payment:process', 'payment:refund'],
                        'expiration_time' => 7200
                    ]
                ],
                'response' => [
                    'success' => true,
                    'data' => [
                        'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
                        'token_id' => 'st_1234567890abcdef',
                        'service_id' => 'payment-service',
                        'scopes' => ['service_access', 'payment:process', 'payment:refund'],
                        'expires_at' => '2024-01-01 14:00:00',
                        'expires_in' => 7200
                    ]
                ]
            ],
            'validate_service_token' => [
                'description' => 'Validate a service token',
                'request' => [
                    'method' => 'POST',
                    'url' => '/api/service-tokens/validate',
                    'headers' => [
                        'Content-Type' => 'application/json'
                    ],
                    'body' => [
                        'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
                        'expected_service' => 'payment-service',
                        'required_scopes' => ['service_access']
                    ]
                ],
                'response' => [
                    'success' => true,
                    'data' => [
                        'valid' => true,
                        'service_id' => 'payment-service',
                        'service_name' => 'Payment Service',
                        'token_id' => 'st_1234567890abcdef',
                        'scopes' => ['service_access', 'payment:process', 'payment:refund'],
                        'issued_at' => '2024-01-01 12:00:00',
                        'expires_at' => '2024-01-01 14:00:00',
                        'validation_time' => 0.002
                    ]
                ]
            ],
            'generate_api_key' => [
                'description' => 'Generate an API key for external integration',
                'request' => [
                    'method' => 'POST',
                    'url' => '/api/api-keys/generate',
                    'headers' => [
                        'Authorization' => 'Bearer {admin_service_token}',
                        'Content-Type' => 'application/json'
                    ],
                    'body' => [
                        'service_id' => 'delivery-service',
                        'scopes' => ['api_read', 'api_write'],
                        'expiration_days' => 90
                    ]
                ],
                'response' => [
                    'success' => true,
                    'data' => [
                        'api_key' => 'ak_1234567890abcdef1234567890abcdef',
                        'key_id' => 'ak_1234567890abcdef',
                        'service_id' => 'delivery-service',
                        'scopes' => ['api_read', 'api_write'],
                        'expires_at' => '2024-04-01 12:00:00',
                        'expires_in_days' => 90
                    ]
                ]
            ]
        ];
    }
}