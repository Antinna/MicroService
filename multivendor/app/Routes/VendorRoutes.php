<?php

namespace Antinna\MultiVendor\Routes;

use Antinna\MultiVendor\Controllers\VendorController;

/**
 * Vendor management API routes
 */
class VendorRoutes
{
    private VendorController $controller;

    public function __construct()
    {
        $this->controller = new VendorController();
    }

    /**
     * Handle vendor API routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api prefix if present
        $path = preg_replace('/^\/api/', '', $path);
        
        // Parse path segments
        $segments = array_filter(explode('/', $path));
        $segments = array_values($segments); // Re-index array

        // Route matching
        switch ($method) {
            case 'GET':
                $this->handleGetRequests($segments);
                break;
                
            case 'POST':
                $this->handlePostRequests($segments);
                break;
                
            case 'PUT':
                $this->handlePutRequests($segments);
                break;
                
            case 'PATCH':
                $this->handlePatchRequests($segments);
                break;
                
            case 'DELETE':
                $this->handleDeleteRequests($segments);
                break;
                
            default:
                $this->sendMethodNotAllowed();
                break;
        }
    }

    /**
     * Handle GET requests
     */
    private function handleGetRequests(array $segments): void
    {
        if (empty($segments) || $segments[0] !== 'vendors') {
            $this->sendNotFound();
            return;
        }

        // GET /vendors - Get all vendors
        if (count($segments) === 1) {
            $this->controller->getVendors();
            return;
        }

        // GET /vendors/{id} - Get vendor profile
        if (count($segments) === 2 && is_numeric($segments[1])) {
            $this->controller->getProfile((int)$segments[1]);
            return;
        }

        // GET /vendors/{id}/kyc/status - Get KYC status
        if (count($segments) === 4 && is_numeric($segments[1]) && 
            $segments[2] === 'kyc' && $segments[3] === 'status') {
            $this->controller->getKYCStatus((int)$segments[1]);
            return;
        }

        // GET /vendors/{id}/compliance - Get compliance status
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'compliance') {
            $this->controller->getComplianceStatus((int)$segments[1]);
            return;
        }

        // GET /vendors/{id}/roles - Get vendor roles
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'roles') {
            $this->controller->getRoles((int)$segments[1]);
            return;
        }

        // GET /vendors/{id}/users/{userId}/permissions - Check user permissions
        if (count($segments) === 5 && is_numeric($segments[1]) && 
            $segments[2] === 'users' && is_numeric($segments[3]) && 
            $segments[4] === 'permissions') {
            $this->controller->checkPermissions((int)$segments[1], (int)$segments[3]);
            return;
        }

        // GET /vendors/{id}/stats - Get vendor statistics
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'stats') {
            $this->controller->getStatistics((int)$segments[1]);
            return;
        }

        // GET /vendors/{id}/documents - Get vendor documents
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'documents') {
            $this->controller->getDocuments((int)$segments[1]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle POST requests
     */
    private function handlePostRequests(array $segments): void
    {
        if (empty($segments) || $segments[0] !== 'vendors') {
            $this->sendNotFound();
            return;
        }

        // POST /vendors/register - Register new vendor
        if (count($segments) === 2 && $segments[1] === 'register') {
            $this->controller->register();
            return;
        }

        // POST /vendors/{id}/kyc/validate - Validate KYC documents
        if (count($segments) === 4 && is_numeric($segments[1]) && 
            $segments[2] === 'kyc' && $segments[3] === 'validate') {
            $this->controller->validateKYC((int)$segments[1]);
            return;
        }

        // POST /vendors/{id}/fssai/validate - Validate FSSAI license
        if (count($segments) === 4 && is_numeric($segments[1]) && 
            $segments[2] === 'fssai' && $segments[3] === 'validate') {
            $this->controller->validateFSSAI((int)$segments[1]);
            return;
        }

        // POST /vendors/{id}/roles - Assign role
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'roles') {
            $this->controller->assignRole((int)$segments[1]);
            return;
        }

        // POST /vendors/{id}/documents - Upload document
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'documents') {
            $this->controller->uploadDocument((int)$segments[1]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle PUT requests
     */
    private function handlePutRequests(array $segments): void
    {
        if (empty($segments) || $segments[0] !== 'vendors') {
            $this->sendNotFound();
            return;
        }

        // PUT /vendors/{id} - Update vendor profile
        if (count($segments) === 2 && is_numeric($segments[1])) {
            $this->controller->updateProfile((int)$segments[1]);
            return;
        }

        // PUT /vendors/{id}/roles/{userId} - Update user role
        if (count($segments) === 4 && is_numeric($segments[1]) && 
            $segments[2] === 'roles' && is_numeric($segments[3])) {
            $this->controller->updateRole((int)$segments[1], (int)$segments[3]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle PATCH requests
     */
    private function handlePatchRequests(array $segments): void
    {
        if (empty($segments) || $segments[0] !== 'vendors') {
            $this->sendNotFound();
            return;
        }

        // PATCH /vendors/{id}/status - Update vendor status
        if (count($segments) === 3 && is_numeric($segments[1]) && 
            $segments[2] === 'status') {
            $this->controller->updateStatus((int)$segments[1]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle DELETE requests
     */
    private function handleDeleteRequests(array $segments): void
    {
        if (empty($segments) || $segments[0] !== 'vendors') {
            $this->sendNotFound();
            return;
        }

        // DELETE /vendors/{id}/roles/{userId} - Remove user role
        if (count($segments) === 4 && is_numeric($segments[1]) && 
            $segments[2] === 'roles' && is_numeric($segments[3])) {
            $this->controller->removeRole((int)$segments[1], (int)$segments[3]);
            return;
        }

        $this->sendNotFound();
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
            'error' => 'Endpoint not found'
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
            'error' => 'Method not allowed'
        ]);
    }

    /**
     * Get available routes documentation
     */
    public static function getRouteDocumentation(): array
    {
        return [
            'vendor_management' => [
                'base_path' => '/api/vendors',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/register',
                        'description' => 'Register new vendor',
                        'parameters' => [
                            'business_name' => 'string (required)',
                            'business_type' => 'string (required)',
                            'contact_person' => 'string (required)',
                            'email' => 'string (required)',
                            'phone' => 'string (required)',
                            'address' => 'string (optional)',
                            'city' => 'string (optional)',
                            'state' => 'string (optional)',
                            'pincode' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/',
                        'description' => 'Get all vendors with pagination',
                        'query_parameters' => [
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)',
                            'status' => 'string (optional)',
                            'business_type' => 'string (optional)',
                            'search' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}',
                        'description' => 'Get vendor profile by ID'
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/{id}',
                        'description' => 'Update vendor profile'
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/{id}/status',
                        'description' => 'Update vendor status',
                        'parameters' => [
                            'status' => 'string (required): active|inactive|suspended|pending_approval',
                            'reason' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/stats',
                        'description' => 'Get vendor statistics'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/{id}/kyc/validate',
                        'description' => 'Validate KYC documents',
                        'parameters' => [
                            'documents' => 'array (required)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/kyc/status',
                        'description' => 'Get KYC validation status'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/{id}/fssai/validate',
                        'description' => 'Validate FSSAI license',
                        'parameters' => [
                            'license_number' => 'string (required)',
                            'issue_date' => 'string (optional)',
                            'document_path' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/compliance',
                        'description' => 'Get vendor compliance status'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/{id}/roles',
                        'description' => 'Assign role to vendor user',
                        'parameters' => [
                            'user_id' => 'int (required)',
                            'role' => 'string (required): owner|manager|staff',
                            'permissions' => 'array (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/roles',
                        'description' => 'Get vendor roles'
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/{id}/roles/{userId}',
                        'description' => 'Update user role',
                        'parameters' => [
                            'role' => 'string (required)',
                            'permissions' => 'array (optional)'
                        ]
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/{id}/roles/{userId}',
                        'description' => 'Remove user role'
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/users/{userId}/permissions',
                        'description' => 'Check user permissions',
                        'query_parameters' => [
                            'permission' => 'string (optional) - specific permission to check'
                        ]
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/{id}/documents',
                        'description' => 'Upload vendor document',
                        'parameters' => [
                            'document_type' => 'string (required)',
                            'document_path' => 'string (required)',
                            'expiry_date' => 'string (optional)',
                            'notes' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/documents',
                        'description' => 'Get vendor documents',
                        'query_parameters' => [
                            'document_type' => 'string (optional) - filter by document type'
                        ]
                    ]
                ]
            ]
        ];
    }
}