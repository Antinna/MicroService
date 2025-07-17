<?php

namespace Antinna\MultiVendor\Routes;

use Antinna\MultiVendor\Controllers\ProductController;

/**
 * Product and inventory management API routes
 */
class ProductRoutes
{
    private ProductController $controller;

    public function __construct()
    {
        $this->controller = new ProductController();
    }

    /**
     * Handle product API routes
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
        // GET /products - Get all products
        if (count($segments) === 1 && $segments[0] === 'products') {
            $this->controller->getProducts();
            return;
        }

        // GET /products/categories - Get product categories
        if (count($segments) === 2 && $segments[0] === 'products' && $segments[1] === 'categories') {
            $this->controller->getCategories();
            return;
        }

        // GET /products/{id} - Get product by ID
        if (count($segments) === 2 && $segments[0] === 'products' && is_numeric($segments[1])) {
            $this->controller->getProduct((int)$segments[1]);
            return;
        }

        // GET /products/{id}/batches - Get product batches
        if (count($segments) === 3 && $segments[0] === 'products' && 
            is_numeric($segments[1]) && $segments[2] === 'batches') {
            $this->controller->getProductBatches((int)$segments[1]);
            return;
        }

        // GET /products/{id}/stock - Get stock levels
        if (count($segments) === 3 && $segments[0] === 'products' && 
            is_numeric($segments[1]) && $segments[2] === 'stock') {
            $this->controller->getStock((int)$segments[1]);
            return;
        }

        // GET /vendors/{vendorId}/products - Get vendor products
        if (count($segments) === 3 && $segments[0] === 'vendors' && 
            is_numeric($segments[1]) && $segments[2] === 'products') {
            $this->controller->getVendorProducts((int)$segments[1]);
            return;
        }

        // GET /batches/{batchId} - Get batch by ID
        if (count($segments) === 2 && $segments[0] === 'batches' && is_numeric($segments[1])) {
            $this->controller->getBatch((int)$segments[1]);
            return;
        }

        // GET /batches/{batchNumber}/track - Track batch journey
        if (count($segments) === 3 && $segments[0] === 'batches' && $segments[2] === 'track') {
            $this->controller->trackBatch($segments[1]);
            return;
        }

        // GET /batches/{batchNumber}/traceability - Get batch traceability
        if (count($segments) === 3 && $segments[0] === 'batches' && $segments[2] === 'traceability') {
            $this->controller->getBatchTraceability($segments[1]);
            return;
        }

        // GET /inventory/summary - Get inventory summary
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'summary') {
            $this->controller->getInventorySummary();
            return;
        }

        // GET /inventory/low-stock - Get low stock alerts
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'low-stock') {
            $this->controller->getLowStockAlerts();
            return;
        }

        // GET /inventory/expiring - Get expiring products
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'expiring') {
            $this->controller->getExpiringProducts();
            return;
        }

        // GET /inventory/expired - Get expired products
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'expired') {
            $this->controller->getExpiredProducts();
            return;
        }

        // GET /inventory/movements - Get inventory movements
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'movements') {
            $this->controller->getInventoryMovements();
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle POST requests
     */
    private function handlePostRequests(array $segments): void
    {
        // POST /products - Create new product
        if (count($segments) === 1 && $segments[0] === 'products') {
            $this->controller->createProduct();
            return;
        }

        // POST /products/validate - Validate product data
        if (count($segments) === 2 && $segments[0] === 'products' && $segments[1] === 'validate') {
            $this->controller->validateProduct();
            return;
        }

        // POST /products/{id}/batches - Add inventory batch
        if (count($segments) === 3 && $segments[0] === 'products' && 
            is_numeric($segments[1]) && $segments[2] === 'batches') {
            $this->controller->addBatch((int)$segments[1]);
            return;
        }

        // POST /batches/{batchNumber}/orders - Map batch to order
        if (count($segments) === 3 && $segments[0] === 'batches' && $segments[2] === 'orders') {
            $this->controller->mapBatchToOrder($segments[1]);
            return;
        }

        // POST /inventory/cleanup - Process expiry cleanup
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'cleanup') {
            $this->controller->processExpiryCleanup();
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle PUT requests
     */
    private function handlePutRequests(array $segments): void
    {
        // PUT /products/{id} - Update product
        if (count($segments) === 2 && $segments[0] === 'products' && is_numeric($segments[1])) {
            $this->controller->updateProduct((int)$segments[1]);
            return;
        }

        // PUT /batches/{batchId} - Update batch
        if (count($segments) === 2 && $segments[0] === 'batches' && is_numeric($segments[1])) {
            $this->controller->updateBatch((int)$segments[1]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle PATCH requests
     */
    private function handlePatchRequests(array $segments): void
    {
        // PATCH /products/{id}/stock - Update stock levels
        if (count($segments) === 3 && $segments[0] === 'products' && 
            is_numeric($segments[1]) && $segments[2] === 'stock') {
            $this->controller->updateStock((int)$segments[1]);
            return;
        }

        // PATCH /products/bulk - Bulk update products
        if (count($segments) === 2 && $segments[0] === 'products' && $segments[1] === 'bulk') {
            $this->controller->bulkUpdateProducts();
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle DELETE requests
     */
    private function handleDeleteRequests(array $segments): void
    {
        // DELETE /products/{id} - Delete product
        if (count($segments) === 2 && $segments[0] === 'products' && is_numeric($segments[1])) {
            $this->controller->deleteProduct((int)$segments[1]);
            return;
        }

        // DELETE /inventory/expired - Remove expired products
        if (count($segments) === 2 && $segments[0] === 'inventory' && $segments[1] === 'expired') {
            $this->controller->removeExpiredProducts();
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
            'product_management' => [
                'base_path' => '/api/products',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/',
                        'description' => 'Create new product',
                        'parameters' => [
                            'vendor_id' => 'int (required)',
                            'name' => 'string (required)',
                            'category' => 'string (required)',
                            'unit' => 'string (required)',
                            'price' => 'decimal (required)',
                            'description' => 'string (optional)',
                            'is_perishable' => 'boolean (optional)',
                            'shelf_life_days' => 'int (optional)',
                            'storage_temperature' => 'decimal (optional)',
                            'organic_certified' => 'boolean (optional)',
                            'packaging_type' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/',
                        'description' => 'Get all products with filtering',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'category' => 'string (optional)',
                            'status' => 'string (optional)',
                            'is_perishable' => 'boolean (optional)',
                            'search' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)',
                            'sort_by' => 'string (default: created_at)',
                            'sort_order' => 'string (default: desc)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}',
                        'description' => 'Get product by ID'
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/{id}',
                        'description' => 'Update product'
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/{id}',
                        'description' => 'Delete product'
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/categories',
                        'description' => 'Get product categories'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/validate',
                        'description' => 'Validate product data'
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/bulk',
                        'description' => 'Bulk update products',
                        'parameters' => [
                            'products' => 'array (required) - Array of product updates'
                        ]
                    ]
                ]
            ],
            'inventory_management' => [
                'base_path' => '/api',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/products/{id}/batches',
                        'description' => 'Add inventory batch',
                        'parameters' => [
                            'batch_number' => 'string (required)',
                            'quantity' => 'int (required)',
                            'manufacturing_date' => 'date (required)',
                            'expiry_date' => 'date (optional)',
                            'supplier_info' => 'string (optional)',
                            'quality_grade' => 'string (optional)',
                            'storage_location' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/products/{id}/batches',
                        'description' => 'Get product batches',
                        'query_parameters' => [
                            'status' => 'string (optional)',
                            'expiry_status' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/batches/{batchId}',
                        'description' => 'Get batch by ID'
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/batches/{batchId}',
                        'description' => 'Update batch'
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/products/{id}/stock',
                        'description' => 'Get stock levels'
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/products/{id}/stock',
                        'description' => 'Update stock levels',
                        'parameters' => [
                            'quantity_change' => 'int (required)',
                            'reason' => 'string (optional)',
                            'batch_id' => 'int (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/inventory/summary',
                        'description' => 'Get inventory summary',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'category' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/inventory/low-stock',
                        'description' => 'Get low stock alerts',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'threshold' => 'int (default: 10)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/inventory/movements',
                        'description' => 'Get inventory movements',
                        'query_parameters' => [
                            'product_id' => 'int (optional)',
                            'vendor_id' => 'int (optional)',
                            'batch_id' => 'int (optional)',
                            'movement_type' => 'string (optional)',
                            'date_from' => 'date (optional)',
                            'date_to' => 'date (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 50)'
                        ]
                    ]
                ]
            ],
            'batch_traceability' => [
                'base_path' => '/api/batches',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/{batchNumber}/track',
                        'description' => 'Track batch journey'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/{batchNumber}/orders',
                        'description' => 'Map batch to order',
                        'parameters' => [
                            'order_id' => 'int (required)',
                            'quantity' => 'int (optional, default: 1)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{batchNumber}/traceability',
                        'description' => 'Get batch traceability chain'
                    ]
                ]
            ],
            'expiry_management' => [
                'base_path' => '/api/inventory',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/expiring',
                        'description' => 'Get expiring products',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'days' => 'int (default: 7)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/expired',
                        'description' => 'Get expired products',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)'
                        ]
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/expired',
                        'description' => 'Remove expired products',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)'
                        ]
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/cleanup',
                        'description' => 'Process expiry cleanup',
                        'parameters' => [
                            'vendor_id' => 'int (optional)'
                        ]
                    ]
                ]
            ],
            'vendor_products' => [
                'base_path' => '/api/vendors',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/{vendorId}/products',
                        'description' => 'Get vendor products',
                        'query_parameters' => [
                            'category' => 'string (optional)',
                            'status' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ]
                ]
            ]
        ];
    }
}