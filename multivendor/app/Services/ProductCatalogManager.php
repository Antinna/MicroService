<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Interfaces\ServiceInterface;
use Antinna\MultiVendor\Repositories\ProductRepository;
use Antinna\MultiVendor\Repositories\VendorRepository;
use Exception;

/**
 * Product catalog management service with perishable-specific attributes
 */
class ProductCatalogManager implements ServiceInterface
{
    private ProductRepository $productRepository;
    private VendorRepository $vendorRepository;

    public function __construct()
    {
        $this->productRepository = new ProductRepository();
        $this->vendorRepository = new VendorRepository();
    }

    /**
     * Validate product data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = [
            'vendor_id', 'name', 'category', 'packaging_type', 
            'shelf_life_hours', 'price_per_unit', 'unit_type'
        ];

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Vendor ID validation
        if (!empty($data['vendor_id'])) {
            if (!is_numeric($data['vendor_id'])) {
                $errors['vendor_id'] = 'Vendor ID must be a valid number';
            } else {
                // Check if vendor exists and is active
                $vendor = $this->vendorRepository->find($data['vendor_id']);
                if (!$vendor) {
                    $errors['vendor_id'] = 'Vendor not found';
                } elseif ($vendor['status'] !== 'active') {
                    $errors['vendor_id'] = 'Vendor must be active to add products';
                }
            }
        }

        // Product name validation
        if (!empty($data['name'])) {
            if (strlen($data['name']) < 2) {
                $errors['name'] = 'Product name must be at least 2 characters long';
            }
            if (strlen($data['name']) > 255) {
                $errors['name'] = 'Product name must not exceed 255 characters';
            }
        }

        // Category validation
        if (!empty($data['category'])) {
            $validCategories = ['dairy', 'vegetables', 'fruits'];
            if (!in_array($data['category'], $validCategories)) {
                $errors['category'] = 'Category must be one of: ' . implode(', ', $validCategories);
            }
        }

        // Packaging type validation
        if (!empty($data['packaging_type'])) {
            $validPackaging = ['loose', 'bottle', 'sealed', 'bag'];
            if (!in_array($data['packaging_type'], $validPackaging)) {
                $errors['packaging_type'] = 'Packaging type must be one of: ' . implode(', ', $validPackaging);
            }
        }

        // Shelf life validation
        if (!empty($data['shelf_life_hours'])) {
            if (!is_numeric($data['shelf_life_hours']) || $data['shelf_life_hours'] <= 0) {
                $errors['shelf_life_hours'] = 'Shelf life must be a positive number';
            }
            if ($data['shelf_life_hours'] > 8760) { // 1 year in hours
                $errors['shelf_life_hours'] = 'Shelf life cannot exceed 1 year (8760 hours)';
            }
        }

        // Price validation
        if (!empty($data['price_per_unit'])) {
            if (!is_numeric($data['price_per_unit']) || $data['price_per_unit'] <= 0) {
                $errors['price_per_unit'] = 'Price must be a positive number';
            }
            if ($data['price_per_unit'] > 99999.99) {
                $errors['price_per_unit'] = 'Price cannot exceed 99,999.99';
            }
        }

        // Unit type validation
        if (!empty($data['unit_type'])) {
            $validUnits = ['kg', 'liter', 'piece', 'gram'];
            if (!in_array($data['unit_type'], $validUnits)) {
                $errors['unit_type'] = 'Unit type must be one of: ' . implode(', ', $validUnits);
            }
        }

        // Description validation
        if (!empty($data['description']) && strlen($data['description']) > 1000) {
            $errors['description'] = 'Description must not exceed 1000 characters';
        }

        // Farm origin validation
        if (!empty($data['farm_origin']) && strlen($data['farm_origin']) > 255) {
            $errors['farm_origin'] = 'Farm origin must not exceed 255 characters';
        }

        // Order quantity validations
        if (!empty($data['minimum_order_quantity'])) {
            if (!is_numeric($data['minimum_order_quantity']) || $data['minimum_order_quantity'] < 1) {
                $errors['minimum_order_quantity'] = 'Minimum order quantity must be at least 1';
            }
        }

        if (!empty($data['maximum_order_quantity'])) {
            if (!is_numeric($data['maximum_order_quantity']) || $data['maximum_order_quantity'] < 1) {
                $errors['maximum_order_quantity'] = 'Maximum order quantity must be at least 1';
            }
            
            $minQty = $data['minimum_order_quantity'] ?? 1;
            if ($data['maximum_order_quantity'] < $minQty) {
                $errors['maximum_order_quantity'] = 'Maximum order quantity must be greater than or equal to minimum order quantity';
            }
        }

        // Boolean field validations
        if (isset($data['is_organic'])) {
            $data['is_organic'] = filter_var($data['is_organic'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['requires_cold_chain'])) {
            $data['requires_cold_chain'] = filter_var($data['requires_cold_chain'], FILTER_VALIDATE_BOOLEAN);
        }

        // Status validation
        if (!empty($data['status'])) {
            $validStatuses = ['active', 'inactive', 'out_of_stock'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors['status'] = 'Status must be one of: ' . implode(', ', $validStatuses);
            }
        }

        // Business logic validations
        if (!empty($data['category']) && !empty($data['vendor_id'])) {
            $vendor = $this->vendorRepository->find($data['vendor_id']);
            if ($vendor && !$this->isValidCategoryForVendor($data['category'], $vendor['business_type'])) {
                $errors['category'] = 'Product category does not match vendor business type';
            }
        }

        // Cold chain validation for dairy products
        if (!empty($data['category']) && $data['category'] === 'dairy') {
            if (!isset($data['requires_cold_chain']) || !$data['requires_cold_chain']) {
                $errors['requires_cold_chain'] = 'Dairy products must require cold chain';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Process product creation/update
     */
    public function process(array $data): array
    {
        if (isset($data['product_id'])) {
            return $this->updateProduct($data['product_id'], $data);
        } else {
            return $this->createProduct($data);
        }
    }

    /**
     * Create new product
     */
    public function createProduct(array $data): array
    {
        try {
            // Validate product data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for duplicate product name for vendor
            if ($this->isDuplicateProductName($data['vendor_id'], $data['name'])) {
                return [
                    'success' => false,
                    'errors' => ['name' => 'Product name already exists for this vendor']
                ];
            }

            // Prepare product data
            $productData = [
                'vendor_id' => $validation['data']['vendor_id'],
                'name' => $validation['data']['name'],
                'description' => $validation['data']['description'] ?? null,
                'category' => $validation['data']['category'],
                'packaging_type' => $validation['data']['packaging_type'],
                'is_organic' => $validation['data']['is_organic'] ?? false,
                'farm_origin' => $validation['data']['farm_origin'] ?? null,
                'shelf_life_hours' => $validation['data']['shelf_life_hours'],
                'price_per_unit' => $validation['data']['price_per_unit'],
                'unit_type' => $validation['data']['unit_type'],
                'minimum_order_quantity' => $validation['data']['minimum_order_quantity'] ?? 1,
                'maximum_order_quantity' => $validation['data']['maximum_order_quantity'] ?? null,
                'requires_cold_chain' => $validation['data']['requires_cold_chain'] ?? false,
                'status' => $validation['data']['status'] ?? 'active'
            ];

            // Create product
            $productId = $this->productRepository->create($productData);

            return [
                'success' => true,
                'product_id' => $productId,
                'message' => 'Product created successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update existing product
     */
    public function updateProduct(int $productId, array $data): array
    {
        try {
            // Check if product exists
            $existingProduct = $this->productRepository->find($productId);
            if (!$existingProduct) {
                return [
                    'success' => false,
                    'error' => 'Product not found'
                ];
            }

            // Add product ID to data for validation context
            $data['vendor_id'] = $existingProduct['vendor_id']; // Ensure vendor_id is set

            // Validate update data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for duplicate product name (excluding current product)
            if (isset($data['name']) && $data['name'] !== $existingProduct['name']) {
                if ($this->isDuplicateProductName($existingProduct['vendor_id'], $data['name'], $productId)) {
                    return [
                        'success' => false,
                        'errors' => ['name' => 'Product name already exists for this vendor']
                    ];
                }
            }

            // Prepare update data (only include fields that are being updated)
            $updateData = [];
            $allowedFields = [
                'name', 'description', 'category', 'packaging_type', 'is_organic',
                'farm_origin', 'shelf_life_hours', 'price_per_unit', 'unit_type',
                'minimum_order_quantity', 'maximum_order_quantity', 'requires_cold_chain', 'status'
            ];

            foreach ($allowedFields as $field) {
                if (isset($validation['data'][$field])) {
                    $updateData[$field] = $validation['data'][$field];
                }
            }

            // Update product
            $success = $this->productRepository->update($productId, $updateData);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Product updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update product'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get product details
     */
    public function getProduct(int $productId): array
    {
        try {
            $product = $this->productRepository->find($productId);
            
            if (!$product) {
                return [
                    'found' => false,
                    'error' => 'Product not found'
                ];
            }

            // Get vendor information
            $vendor = $this->vendorRepository->find($product['vendor_id']);
            $product['vendor_name'] = $vendor['business_name'] ?? 'Unknown Vendor';

            return [
                'found' => true,
                'product' => $product
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => 'Error retrieving product: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get products by vendor
     */
    public function getVendorProducts(int $vendorId, ?string $status = null): array
    {
        try {
            if ($status) {
                $products = $this->productRepository->findAll(['vendor_id' => $vendorId, 'status' => $status]);
            } else {
                $products = $this->productRepository->findByVendor($vendorId);
            }

            return [
                'success' => true,
                'products' => $products
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving vendor products: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Search products
     */
    public function searchProducts(string $query, ?string $category = null, ?bool $organicOnly = null): array
    {
        try {
            $products = $this->productRepository->search($query);

            // Apply additional filters
            if ($category) {
                $products = array_filter($products, function($product) use ($category) {
                    return $product['category'] === $category;
                });
            }

            if ($organicOnly === true) {
                $products = array_filter($products, function($product) {
                    return $product['is_organic'];
                });
            }

            return [
                'success' => true,
                'products' => array_values($products) // Re-index array
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product search failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete product
     */
    public function deleteProduct(int $productId): array
    {
        try {
            $product = $this->productRepository->find($productId);
            
            if (!$product) {
                return [
                    'success' => false,
                    'error' => 'Product not found'
                ];
            }

            // Soft delete by setting status to inactive
            $success = $this->productRepository->update($productId, ['status' => 'inactive']);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Product deleted successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to delete product'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product deletion failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if product name is duplicate for vendor
     */
    private function isDuplicateProductName(int $vendorId, string $name, ?int $excludeProductId = null): bool
    {
        $existing = $this->productRepository->findAll(['vendor_id' => $vendorId, 'name' => $name]);
        
        foreach ($existing as $product) {
            if ($excludeProductId && $product['id'] == $excludeProductId) {
                continue;
            }
            return true;
        }
        
        return false;
    }

    /**
     * Check if category is valid for vendor business type
     */
    private function isValidCategoryForVendor(string $category, string $businessType): bool
    {
        $validCombinations = [
            'dairy' => ['dairy'],
            'vegetables' => ['vegetables'],
            'mixed' => ['dairy', 'vegetables', 'fruits']
        ];

        return in_array($category, $validCombinations[$businessType] ?? []);
    }

    /**
     * Get product categories for vendor
     */
    public function getValidCategoriesForVendor(int $vendorId): array
    {
        try {
            $vendor = $this->vendorRepository->find($vendorId);
            
            if (!$vendor) {
                return [
                    'success' => false,
                    'error' => 'Vendor not found'
                ];
            }

            $validCombinations = [
                'dairy' => ['dairy'],
                'vegetables' => ['vegetables'],
                'mixed' => ['dairy', 'vegetables', 'fruits']
            ];

            return [
                'success' => true,
                'categories' => $validCombinations[$vendor['business_type']] ?? []
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving categories: ' . $e->getMessage()
            ];
        }
    }
}