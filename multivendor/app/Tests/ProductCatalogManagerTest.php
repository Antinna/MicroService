<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\ProductCatalogManager;
use PHPUnit\Framework\TestCase;

class ProductCatalogManagerTest extends TestCase
{
    private ProductCatalogManager $catalogManager;
    private array $testProductData;
    private ?int $testProductId = null;

    protected function setUp(): void
    {
        $this->catalogManager = new ProductCatalogManager();
        $this->testProductData = [
            'vendor_id' => 1,
            'name' => 'Fresh Milk',
            'category' => 'dairy',
            'unit' => 'liter',
            'price' => 45.00,
            'description' => 'Fresh cow milk from local farm',
            'is_perishable' => true,
            'shelf_life_days' => 3,
            'storage_temperature' => 4.0,
            'organic_certified' => false,
            'packaging_type' => 'bottle'
        ];
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->testProductId) {
            $this->catalogManager->deleteProduct($this->testProductId);
        }
    }

    public function testCreateProductSuccess()
    {
        $result = $this->catalogManager->createProduct($this->testProductData);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('product_id', $result);
        $this->assertArrayHasKey('product', $result);
        $this->assertEquals($this->testProductData['name'], $result['product']['name']);
        $this->assertEquals($this->testProductData['category'], $result['product']['category']);
        
        // Store for cleanup
        $this->testProductId = $result['product_id'];
    }

    public function testCreateProductWithMissingRequiredFields()
    {
        $incompleteData = [
            'vendor_id' => 1,
            'name' => 'Test Product',
            // Missing required fields
        ];

        $result = $this->catalogManager->createProduct($incompleteData);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testCreateProductWithInvalidPrice()
    {
        $invalidData = $this->testProductData;
        $invalidData['price'] = -10.00;

        $result = $this->catalogManager->createProduct($invalidData);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('price', $result['errors']);
    }

    public function testCreateProductWithInvalidShelfLife()
    {
        $invalidData = $this->testProductData;
        $invalidData['shelf_life_days'] = -5;

        $result = $this->catalogManager->createProduct($invalidData);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('shelf_life_days', $result['errors']);
    }

    public function testGetProductSuccess()
    {
        // First create a product
        $createResult = $this->catalogManager->createProduct($this->testProductData);
        $this->testProductId = $createResult['product_id'];

        // Get the product
        $result = $this->catalogManager->getProduct($this->testProductId);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('product', $result);
        $this->assertEquals($this->testProductData['name'], $result['product']['name']);
        $this->assertEquals($this->testProductData['vendor_id'], $result['product']['vendor_id']);
    }

    public function testGetProductNotFound()
    {
        $result = $this->catalogManager->getProduct(99999);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Product not found', $result['error']);
    }

    public function testUpdateProductSuccess()
    {
        // First create a product
        $createResult = $this->catalogManager->createProduct($this->testProductData);
        $this->testProductId = $createResult['product_id'];

        // Update the product
        $updateData = [
            'name' => 'Premium Fresh Milk',
            'price' => 55.00,
            'description' => 'Premium quality fresh milk'
        ];

        $result = $this->catalogManager->updateProduct($this->testProductId, $updateData);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('updated_fields', $result);
        $this->assertContains('name', $result['updated_fields']);
        $this->assertContains('price', $result['updated_fields']);
    }

    public function testUpdateProductNotFound()
    {
        $updateData = [
            'name' => 'Updated Product'
        ];

        $result = $this->catalogManager->updateProduct(99999, $updateData);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Product not found', $result['error']);
    }

    public function testUpdateProductWithInvalidData()
    {
        // First create a product
        $createResult = $this->catalogManager->createProduct($this->testProductData);
        $this->testProductId = $createResult['product_id'];

        // Try to update with invalid data
        $updateData = [
            'price' => -20.00
        ];

        $result = $this->catalogManager->updateProduct($this->testProductId, $updateData);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testDeleteProductSuccess()
    {
        // First create a product
        $createResult = $this->catalogManager->createProduct($this->testProductData);
        $productId = $createResult['product_id'];

        // Delete the product
        $result = $this->catalogManager->deleteProduct($productId);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('Product deleted successfully', $result['message']);

        // Verify product is deleted
        $getResult = $this->catalogManager->getProduct($productId);
        $this->assertFalse($getResult['success']);
    }

    public function testDeleteProductNotFound()
    {
        $result = $this->catalogManager->deleteProduct(99999);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Product not found', $result['error']);
    }

    public function testGetProductsWithoutFilters()
    {
        $result = $this->catalogManager->getProducts([]);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('total', $result['pagination']);
        $this->assertArrayHasKey('page', $result['pagination']);
        $this->assertArrayHasKey('limit', $result['pagination']);
    }

    public function testGetProductsWithVendorFilter()
    {
        $filters = [
            'vendor_id' => 1,
            'page' => 1,
            'limit' => 10
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('filters_applied', $result);
        $this->assertEquals(1, $result['filters_applied']['vendor_id']);
    }

    public function testGetProductsWithCategoryFilter()
    {
        $filters = [
            'category' => 'dairy',
            'page' => 1,
            'limit' => 10
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertEquals('dairy', $result['filters_applied']['category']);
    }

    public function testGetProductsWithSearchFilter()
    {
        $filters = [
            'search' => 'milk',
            'page' => 1,
            'limit' => 10
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertEquals('milk', $result['filters_applied']['search']);
    }

    public function testGetProductsWithPerishableFilter()
    {
        $filters = [
            'is_perishable' => true,
            'page' => 1,
            'limit' => 10
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertTrue($result['filters_applied']['is_perishable']);
    }

    public function testGetProductsWithSorting()
    {
        $filters = [
            'sort_by' => 'name',
            'sort_order' => 'asc',
            'page' => 1,
            'limit' => 10
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertEquals('name', $result['filters_applied']['sort_by']);
        $this->assertEquals('asc', $result['filters_applied']['sort_order']);
    }

    public function testGetProductsWithPagination()
    {
        $filters = [
            'page' => 2,
            'limit' => 5
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertEquals(2, $result['pagination']['page']);
        $this->assertEquals(5, $result['pagination']['limit']);
    }

    public function testGetCategories()
    {
        $result = $this->catalogManager->getCategories();
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('categories', $result);
        $this->assertIsArray($result['categories']);
        $this->assertArrayHasKey('total_categories', $result);
    }

    public function testValidateProductDataWithValidData()
    {
        $result = $this->catalogManager->validateProductData($this->testProductData);
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateProductDataWithInvalidData()
    {
        $invalidData = [
            'vendor_id' => 'invalid',
            'name' => '',
            'price' => -10,
            'shelf_life_days' => -5
        ];

        $result = $this->catalogManager->validateProductData($invalidData);
        
        $this->assertTrue($result['success']);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertArrayHasKey('vendor_id', $result['errors']);
        $this->assertArrayHasKey('name', $result['errors']);
        $this->assertArrayHasKey('price', $result['errors']);
    }

    public function testBulkUpdateProductsSuccess()
    {
        // First create some products
        $product1 = $this->catalogManager->createProduct($this->testProductData);
        $this->testProductId = $product1['product_id'];

        $product2Data = $this->testProductData;
        $product2Data['name'] = 'Fresh Yogurt';
        $product2 = $this->catalogManager->createProduct($product2Data);

        // Bulk update
        $updates = [
            [
                'id' => $product1['product_id'],
                'price' => 50.00,
                'description' => 'Updated milk description'
            ],
            [
                'id' => $product2['product_id'],
                'price' => 35.00,
                'description' => 'Updated yogurt description'
            ]
        ];

        $result = $this->catalogManager->bulkUpdateProducts($updates);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('updated_count', $result);
        $this->assertArrayHasKey('failed_count', $result);
        $this->assertEquals(2, $result['updated_count']);
        $this->assertEquals(0, $result['failed_count']);

        // Clean up second product
        $this->catalogManager->deleteProduct($product2['product_id']);
    }

    public function testBulkUpdateProductsWithSomeFailures()
    {
        // First create a product
        $product1 = $this->catalogManager->createProduct($this->testProductData);
        $this->testProductId = $product1['product_id'];

        // Bulk update with one valid and one invalid
        $updates = [
            [
                'id' => $product1['product_id'],
                'price' => 50.00
            ],
            [
                'id' => 99999, // Non-existent product
                'price' => 35.00
            ]
        ];

        $result = $this->catalogManager->bulkUpdateProducts($updates);
        
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['updated_count']);
        $this->assertEquals(1, $result['failed_count']);
        $this->assertArrayHasKey('failures', $result);
    }

    public function testCreateProductWithAllOptionalFields()
    {
        $completeData = $this->testProductData;
        $completeData['tags'] = ['fresh', 'local', 'premium'];
        $completeData['nutritional_info'] = [
            'calories' => 60,
            'protein' => 3.2,
            'fat' => 3.5,
            'carbs' => 4.8
        ];
        $completeData['allergen_info'] = ['milk'];
        $completeData['minimum_order_quantity'] = 1;
        $completeData['maximum_order_quantity'] = 10;

        $result = $this->catalogManager->createProduct($completeData);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('product_id', $result);
        
        // Store for cleanup
        $this->testProductId = $result['product_id'];

        // Verify all fields are saved
        $getResult = $this->catalogManager->getProduct($this->testProductId);
        $this->assertTrue($getResult['success']);
        $this->assertEquals($completeData['minimum_order_quantity'], $getResult['product']['minimum_order_quantity']);
    }

    public function testGetProductsWithMultipleFilters()
    {
        $filters = [
            'vendor_id' => 1,
            'category' => 'dairy',
            'is_perishable' => true,
            'status' => 'active',
            'search' => 'milk',
            'page' => 1,
            'limit' => 10
        ];

        $result = $this->catalogManager->getProducts($filters);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('filters_applied', $result);
        
        // Verify all filters are applied
        $appliedFilters = $result['filters_applied'];
        $this->assertEquals(1, $appliedFilters['vendor_id']);
        $this->assertEquals('dairy', $appliedFilters['category']);
        $this->assertTrue($appliedFilters['is_perishable']);
        $this->assertEquals('active', $appliedFilters['status']);
        $this->assertEquals('milk', $appliedFilters['search']);
    }

    public function testProductStatusTransitions()
    {
        // Create product
        $createResult = $this->catalogManager->createProduct($this->testProductData);
        $this->testProductId = $createResult['product_id'];

        // Test status transitions
        $statuses = ['active', 'inactive', 'discontinued'];
        
        foreach ($statuses as $status) {
            $updateResult = $this->catalogManager->updateProduct($this->testProductId, ['status' => $status]);
            $this->assertTrue($updateResult['success']);
            
            $getResult = $this->catalogManager->getProduct($this->testProductId);
            $this->assertEquals($status, $getResult['product']['status']);
        }
    }
}