<?php

namespace Antinna\MultiVendor\Controllers;

use Antinna\MultiVendor\Services\ProductCatalogManager;
use Antinna\MultiVendor\Services\InventoryTracker;
use Antinna\MultiVendor\Services\BatchTraceabilityService;
use Antinna\MultiVendor\Services\ExpiryManager;
use Exception;

/**
 * Product and inventory management API controller
 */
class ProductController
{
    private ProductCatalogManager $catalogManager;
    private InventoryTracker $inventoryTracker;
    private BatchTraceabilityService $batchService;
    private ExpiryManager $expiryManager;

    public function __construct()
    {
        $this->catalogManager = new ProductCatalogManager();
        $this->inventoryTracker = new InventoryTracker();
        $this->batchService = new BatchTraceabilityService();
        $this->expiryManager = new ExpiryManager();
    }

    /**
     * Create new product
     * POST /api/products
     */
    public function createProduct(): void
    {
        try {
            $input = $this->getJsonInput();
            
            // Validate required fields
            $requiredFields = ['vendor_id', 'name', 'category', 'unit', 'price'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            // Create product
            $result = $this->catalogManager->createProduct($input);
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Product creation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get product by ID
     * GET /api/products/{id}
     */
    public function getProduct(int $productId): void
    {
        try {
            $result = $this->catalogManager->getProduct($productId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get product: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update product
     * PUT /api/products/{id}
     */
    public function updateProduct(int $productId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->catalogManager->updateProduct($productId, $input);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Product update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Delete product
     * DELETE /api/products/{id}
     */
    public function deleteProduct(int $productId): void
    {
        try {
            $result = $this->catalogManager->deleteProduct($productId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Product deletion failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get products with filtering and pagination
     * GET /api/products
     */
    public function getProducts(): void
    {
        try {
            $filters = [
                'vendor_id' => $_GET['vendor_id'] ?? null,
                'category' => $_GET['category'] ?? null,
                'status' => $_GET['status'] ?? null,
                'is_perishable' => isset($_GET['is_perishable']) ? (bool)$_GET['is_perishable'] : null,
                'search' => $_GET['search'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 20),
                'sort_by' => $_GET['sort_by'] ?? 'created_at',
                'sort_order' => $_GET['sort_order'] ?? 'desc'
            ];

            $result = $this->catalogManager->getProducts($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get products by vendor
     * GET /api/vendors/{vendorId}/products
     */
    public function getVendorProducts(int $vendorId): void
    {
        try {
            $filters = [
                'vendor_id' => $vendorId,
                'category' => $_GET['category'] ?? null,
                'status' => $_GET['status'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 20)
            ];

            $result = $this->catalogManager->getProducts($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendor products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Add inventory batch
     * POST /api/products/{id}/batches
     */
    public function addBatch(int $productId): void
    {
        try {
            $input = $this->getJsonInput();
            
            // Validate required fields
            $requiredFields = ['batch_number', 'quantity', 'manufacturing_date'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            // Add product ID to input
            $input['product_id'] = $productId;

            $result = $this->inventoryTracker->addBatch($input);
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Batch creation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get product batches
     * GET /api/products/{id}/batches
     */
    public function getProductBatches(int $productId): void
    {
        try {
            $filters = [
                'product_id' => $productId,
                'status' => $_GET['status'] ?? null,
                'expiry_status' => $_GET['expiry_status'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 20)
            ];

            $result = $this->inventoryTracker->getBatches($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get product batches: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update batch
     * PUT /api/batches/{batchId}
     */
    public function updateBatch(int $batchId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->inventoryTracker->updateBatch($batchId, $input);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Batch update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get batch by ID
     * GET /api/batches/{batchId}
     */
    public function getBatch(int $batchId): void
    {
        try {
            $result = $this->inventoryTracker->getBatch($batchId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get batch: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get inventory summary
     * GET /api/inventory/summary
     */
    public function getInventorySummary(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            $category = $_GET['category'] ?? null;
            
            $result = $this->inventoryTracker->getInventorySummary($vendorId, $category);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get inventory summary: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update stock levels
     * PATCH /api/products/{id}/stock
     */
    public function updateStock(int $productId): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (!isset($input['quantity_change'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'quantity_change is required'
                ]);
                return;
            }

            $result = $this->inventoryTracker->updateStock(
                $productId,
                $input['quantity_change'],
                $input['reason'] ?? 'Manual adjustment',
                $input['batch_id'] ?? null
            );
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Stock update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get stock levels
     * GET /api/products/{id}/stock
     */
    public function getStock(int $productId): void
    {
        try {
            $result = $this->inventoryTracker->getStockLevel($productId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get stock levels: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get low stock alerts
     * GET /api/inventory/low-stock
     */
    public function getLowStockAlerts(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            $threshold = (int)($_GET['threshold'] ?? 10);
            
            $result = $this->inventoryTracker->getLowStockAlerts($vendorId, $threshold);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get low stock alerts: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Track batch journey
     * GET /api/batches/{batchNumber}/track
     */
    public function trackBatch(string $batchNumber): void
    {
        try {
            $result = $this->batchService->trackBatch($batchNumber);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Batch tracking failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Map batch to order
     * POST /api/batches/{batchNumber}/orders
     */
    public function mapBatchToOrder(string $batchNumber): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['order_id'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'order_id is required'
                ]);
                return;
            }

            $result = $this->batchService->mapBatchToOrder(
                $batchNumber,
                $input['order_id'],
                $input['quantity'] ?? 1
            );
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Batch mapping failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get batch traceability
     * GET /api/batches/{batchNumber}/traceability
     */
    public function getBatchTraceability(string $batchNumber): void
    {
        try {
            $result = $this->batchService->getTraceabilityChain($batchNumber);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get batch traceability: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get expiring products
     * GET /api/inventory/expiring
     */
    public function getExpiringProducts(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            $days = (int)($_GET['days'] ?? 7);
            
            $result = $this->expiryManager->getExpiringProducts($vendorId, $days);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get expiring products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get expired products
     * GET /api/inventory/expired
     */
    public function getExpiredProducts(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            
            $result = $this->expiryManager->getExpiredProducts($vendorId);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get expired products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Remove expired products
     * DELETE /api/inventory/expired
     */
    public function removeExpiredProducts(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            
            $result = $this->expiryManager->removeExpiredProducts($vendorId);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to remove expired products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Process expiry cleanup
     * POST /api/inventory/cleanup
     */
    public function processExpiryCleanup(): void
    {
        try {
            $input = $this->getJsonInput();
            $vendorId = $input['vendor_id'] ?? null;
            
            $result = $this->expiryManager->processExpiryCleanup($vendorId);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Expiry cleanup failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get product categories
     * GET /api/products/categories
     */
    public function getCategories(): void
    {
        try {
            $result = $this->catalogManager->getCategories();
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get categories: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Validate product data
     * POST /api/products/validate
     */
    public function validateProduct(): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->catalogManager->validateProductData($input);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Product validation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Bulk update products
     * PATCH /api/products/bulk
     */
    public function bulkUpdateProducts(): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['products']) || !is_array($input['products'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'products array is required'
                ]);
                return;
            }

            $result = $this->catalogManager->bulkUpdateProducts($input['products']);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Bulk update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get inventory movements
     * GET /api/inventory/movements
     */
    public function getInventoryMovements(): void
    {
        try {
            $filters = [
                'product_id' => $_GET['product_id'] ?? null,
                'vendor_id' => $_GET['vendor_id'] ?? null,
                'batch_id' => $_GET['batch_id'] ?? null,
                'movement_type' => $_GET['movement_type'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 50)
            ];

            $result = $this->inventoryTracker->getInventoryMovements($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get inventory movements: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input');
        }
        
        return $data ?? [];
    }

    /**
     * Validate required fields
     */
    private function validateRequiredFields(array $data, array $requiredFields): array
    {
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Send JSON response
     */
    private function sendResponse(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}