<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\VendorPromotionService;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class VendorPromotionServiceTest extends TestCase
{
    private VendorPromotionService $vendorPromotionService;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->vendorPromotionService = new VendorPromotionService();
    }

    public function testCreatePromotion()
    {
        $vendorId = 999; // Non-existent vendor for testing
        $promotionData = [
            'name' => 'Summer Sale',
            'description' => '20% off on all products',
            'promotion_type' => VendorPromotionService::TYPE_PERCENTAGE_DISCOUNT,
            'discount_value' => 20,
            'start_date' => '2024-06-01 00:00:00',
            'end_date' => '2024-06-30 23:59:59',
            'application_scope' => VendorPromotionService::SCOPE_ALL_PRODUCTS,
            'usage_limit_per_customer' => 1,
            'generate_coupons' => true,
            'coupon_count' => 50
        ];

        $result = $this->vendorPromotionService->createPromotion($vendorId, $promotionData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because vendor doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Vendor not found', $result['error']);
    }

    public function testCreatePromotionWithInvalidData()
    {
        $vendorId = 1;
        $invalidPromotionData = [
            // Missing required fields
            'description' => 'Invalid promotion',
            'discount_value' => 150, // Invalid: > 100% for percentage discount
            'start_date' => 'invalid-date',
            'end_date' => '2024-01-01', // Before start date
            'promotion_type' => 'invalid_type'
        ];

        $result = $this->vendorPromotionService->createPromotion($vendorId, $invalidPromotionData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        
        $errors = $result['errors'];
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('promotion_type', $errors);
        $this->assertArrayHasKey('start_date', $errors);
    }

    public function testValidateAndCalculateDiscount()
    {
        $couponCode = 'INVALID_COUPON';
        $vendorId = 1;
        $orderData = [
            'customer_id' => 1,
            'subtotal' => 100.00,
            'delivery_fee' => 5.00,
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => 50.00]
            ]
        ];

        $result = $this->vendorPromotionService->validateAndCalculateDiscount($couponCode, $vendorId, $orderData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        
        // Should fail because coupon doesn't exist
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Invalid coupon code', $result['error']);
    }

    public function testApplyPromotionToOrder()
    {
        $orderId = 999; // Non-existent order for testing
        $couponCode = 'TEST_COUPON';
        $discountData = [
            'coupon' => ['coupon_code' => $couponCode],
            'promotion' => ['id' => 1],
            'discount_calculation' => ['discount_amount' => 20.00],
            'payout_adjustment' => ['vendor_adjustment' => -20.00]
        ];

        $result = $this->vendorPromotionService->applyPromotionToOrder($orderId, $couponCode, $discountData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // May succeed or fail depending on database state
        if ($result['success']) {
            $this->assertArrayHasKey('usage_id', $result);
            $this->assertArrayHasKey('discount_applied', $result);
            $this->assertArrayHasKey('message', $result);
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testGetVendorPromotions()
    {
        $vendorId = 1;
        $filters = [
            'status' => VendorPromotionService::STATUS_ACTIVE,
            'type' => VendorPromotionService::TYPE_PERCENTAGE_DISCOUNT,
            'active_only' => true
        ];

        $result = $this->vendorPromotionService->getVendorPromotions($vendorId, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('vendor_id', $result);
            $this->assertArrayHasKey('promotions', $result);
            $this->assertArrayHasKey('total_promotions', $result);
            
            $this->assertEquals($vendorId, $result['vendor_id']);
            $this->assertIsArray($result['promotions']);
            $this->assertIsInt($result['total_promotions']);
            
            // Check promotion structure
            foreach ($result['promotions'] as $promotion) {
                $this->assertArrayHasKey('coupon_stats', $promotion);
                $this->assertArrayHasKey('usage_stats', $promotion);
            }
        }

        // Test without filters
        $result = $this->vendorPromotionService->getVendorPromotions($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testGetPromotionAnalytics()
    {
        $promotionId = 999; // Non-existent promotion for testing

        $result = $this->vendorPromotionService->getPromotionAnalytics($promotionId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because promotion doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Promotion not found', $result['error']);
    }

    public function testUpdatePromotionStatus()
    {
        $promotionId = 999; // Non-existent promotion for testing
        $status = VendorPromotionService::STATUS_PAUSED;

        $result = $this->vendorPromotionService->updatePromotionStatus($promotionId, $status);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because promotion doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Promotion not found or status unchanged', $result['error']);
    }

    public function testUpdatePromotionStatusWithInvalidStatus()
    {
        $promotionId = 1;
        $invalidStatus = 'invalid_status';

        $result = $this->vendorPromotionService->updatePromotionStatus($promotionId, $invalidStatus);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Invalid promotion status', $result['error']);
    }

    public function testGenerateBulkCoupons()
    {
        $promotionId = 999; // Non-existent promotion for testing
        $quantity = 10;
        $options = [
            'prefix' => 'BULK',
            'length' => 6,
            'usage_limit' => 1
        ];

        $result = $this->vendorPromotionService->generateBulkCoupons($promotionId, $quantity, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because promotion doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Promotion not found', $result['error']);
    }

    public function testPromotionTypeConstants()
    {
        // Test that all promotion type constants are defined
        $this->assertEquals('percentage_discount', VendorPromotionService::TYPE_PERCENTAGE_DISCOUNT);
        $this->assertEquals('fixed_amount_discount', VendorPromotionService::TYPE_FIXED_AMOUNT_DISCOUNT);
        $this->assertEquals('buy_x_get_y', VendorPromotionService::TYPE_BUY_X_GET_Y);
        $this->assertEquals('free_delivery', VendorPromotionService::TYPE_FREE_DELIVERY);
        $this->assertEquals('minimum_order_discount', VendorPromotionService::TYPE_MINIMUM_ORDER_DISCOUNT);
        $this->assertEquals('first_order_discount', VendorPromotionService::TYPE_FIRST_ORDER_DISCOUNT);
        $this->assertEquals('bulk_discount', VendorPromotionService::TYPE_BULK_DISCOUNT);
    }

    public function testPromotionStatusConstants()
    {
        // Test that all promotion status constants are defined
        $this->assertEquals('draft', VendorPromotionService::STATUS_DRAFT);
        $this->assertEquals('active', VendorPromotionService::STATUS_ACTIVE);
        $this->assertEquals('paused', VendorPromotionService::STATUS_PAUSED);
        $this->assertEquals('expired', VendorPromotionService::STATUS_EXPIRED);
        $this->assertEquals('cancelled', VendorPromotionService::STATUS_CANCELLED);
    }

    public function testCouponStatusConstants()
    {
        // Test that all coupon status constants are defined
        $this->assertEquals('active', VendorPromotionService::COUPON_STATUS_ACTIVE);
        $this->assertEquals('used', VendorPromotionService::COUPON_STATUS_USED);
        $this->assertEquals('expired', VendorPromotionService::COUPON_STATUS_EXPIRED);
        $this->assertEquals('cancelled', VendorPromotionService::COUPON_STATUS_CANCELLED);
    }

    public function testApplicationScopeConstants()
    {
        // Test that all application scope constants are defined
        $this->assertEquals('all_products', VendorPromotionService::SCOPE_ALL_PRODUCTS);
        $this->assertEquals('specific_products', VendorPromotionService::SCOPE_SPECIFIC_PRODUCTS);
        $this->assertEquals('category', VendorPromotionService::SCOPE_CATEGORY);
        $this->assertEquals('minimum_order', VendorPromotionService::SCOPE_MINIMUM_ORDER);
    }

    public function testCreatePromotionWithDifferentTypes()
    {
        $vendorId = 999;
        $promotionTypes = [
            VendorPromotionService::TYPE_PERCENTAGE_DISCOUNT,
            VendorPromotionService::TYPE_FIXED_AMOUNT_DISCOUNT,
            VendorPromotionService::TYPE_BUY_X_GET_Y,
            VendorPromotionService::TYPE_FREE_DELIVERY,
            VendorPromotionService::TYPE_MINIMUM_ORDER_DISCOUNT,
            VendorPromotionService::TYPE_FIRST_ORDER_DISCOUNT,
            VendorPromotionService::TYPE_BULK_DISCOUNT
        ];

        foreach ($promotionTypes as $type) {
            $promotionData = [
                'name' => 'Test Promotion - ' . $type,
                'promotion_type' => $type,
                'discount_value' => 10,
                'start_date' => '2024-01-01 00:00:00',
                'end_date' => '2024-12-31 23:59:59'
            ];

            $result = $this->vendorPromotionService->createPromotion($vendorId, $promotionData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because vendor doesn't exist, but test that all types are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testUpdatePromotionStatusWithAllValidStatuses()
    {
        $promotionId = 999;
        $validStatuses = [
            VendorPromotionService::STATUS_DRAFT,
            VendorPromotionService::STATUS_ACTIVE,
            VendorPromotionService::STATUS_PAUSED,
            VendorPromotionService::STATUS_EXPIRED,
            VendorPromotionService::STATUS_CANCELLED
        ];

        foreach ($validStatuses as $status) {
            $result = $this->vendorPromotionService->updatePromotionStatus($promotionId, $status);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because promotion doesn't exist, but test that all statuses are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testGetVendorPromotionsWithDifferentFilters()
    {
        $vendorId = 1;
        $filterVariations = [
            [], // No filters
            ['status' => VendorPromotionService::STATUS_ACTIVE],
            ['type' => VendorPromotionService::TYPE_PERCENTAGE_DISCOUNT],
            ['active_only' => true],
            [
                'status' => VendorPromotionService::STATUS_ACTIVE,
                'type' => VendorPromotionService::TYPE_FIXED_AMOUNT_DISCOUNT,
                'active_only' => true
            ]
        ];

        foreach ($filterVariations as $filters) {
            $result = $this->vendorPromotionService->getVendorPromotions($vendorId, $filters);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertEquals($vendorId, $result['vendor_id']);
                $this->assertIsArray($result['promotions']);
            }
        }
    }

    public function testValidateAndCalculateDiscountWithDifferentOrderAmounts()
    {
        $couponCode = 'TEST_COUPON';
        $vendorId = 1;
        $orderAmounts = [0, 10, 50, 100, 500, 1000];

        foreach ($orderAmounts as $amount) {
            $orderData = [
                'customer_id' => 1,
                'subtotal' => $amount,
                'delivery_fee' => 5.00,
                'items' => [
                    ['product_id' => 1, 'quantity' => 1, 'price' => $amount]
                ]
            ];

            $result = $this->vendorPromotionService->validateAndCalculateDiscount($couponCode, $vendorId, $orderData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('valid', $result);
            
            // Should fail because coupon doesn't exist, but test that all amounts are handled
            $this->assertFalse($result['valid']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testGenerateBulkCouponsWithDifferentQuantities()
    {
        $promotionId = 999;
        $quantities = [1, 5, 10, 50, 100];

        foreach ($quantities as $quantity) {
            $result = $this->vendorPromotionService->generateBulkCoupons($promotionId, $quantity);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because promotion doesn't exist, but test that all quantities are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testPromotionDataValidationEdgeCases()
    {
        $vendorId = 1;
        
        // Test with boundary values
        $boundaryPromotionData = [
            'name' => 'Boundary Test',
            'promotion_type' => VendorPromotionService::TYPE_PERCENTAGE_DISCOUNT,
            'discount_value' => 100, // Maximum percentage
            'start_date' => '2024-01-01 00:00:00',
            'end_date' => '2024-01-01 00:00:01', // Minimum valid duration
            'usage_limit_per_customer' => 0, // Minimum usage limit
            'total_usage_limit' => 0 // Minimum total limit
        ];

        $result = $this->vendorPromotionService->createPromotion($vendorId, $boundaryPromotionData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Test with negative values
        $negativePromotionData = [
            'name' => 'Negative Test',
            'promotion_type' => VendorPromotionService::TYPE_FIXED_AMOUNT_DISCOUNT,
            'discount_value' => -10, // Negative discount
            'start_date' => '2024-01-01 00:00:00',
            'end_date' => '2024-12-31 23:59:59',
            'usage_limit_per_customer' => -1, // Negative usage limit
            'total_usage_limit' => -1 // Negative total limit
        ];

        $result = $this->vendorPromotionService->createPromotion($vendorId, $negativePromotionData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}