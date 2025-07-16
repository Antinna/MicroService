<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\OrderConsolidator;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class OrderConsolidatorTest extends TestCase
{
    private OrderConsolidator $orderConsolidator;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->orderConsolidator = new OrderConsolidator();
    }

    public function testConsolidateOrdersForSlot()
    {
        $deliverySlotId = 1;
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        $options = [
            'strategy' => OrderConsolidator::STRATEGY_BY_CUSTOMER,
            'delivery_fee_discount' => 25
        ];

        $result = $this->orderConsolidator->consolidateOrdersForSlot($deliverySlotId, $deliveryDate, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('delivery_slot_id', $result);
            $this->assertArrayHasKey('delivery_date', $result);
            $this->assertArrayHasKey('strategy', $result);
            $this->assertArrayHasKey('original_orders_count', $result);
            $this->assertArrayHasKey('consolidated_orders_count', $result);
            $this->assertArrayHasKey('consolidation_ratio', $result);
            $this->assertArrayHasKey('consolidated_orders', $result);
            
            $this->assertEquals($deliverySlotId, $result['delivery_slot_id']);
            $this->assertEquals($deliveryDate, $result['delivery_date']);
            $this->assertEquals(OrderConsolidator::STRATEGY_BY_CUSTOMER, $result['strategy']);
            $this->assertIsArray($result['consolidated_orders']);
        }
    }

    public function testConsolidateByCustomerLocation()
    {
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        $options = [
            'delivery_fee_discount' => 20
        ];

        $result = $this->orderConsolidator->consolidateByCustomerLocation($deliveryDate, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('delivery_date', $result);
            $this->assertArrayHasKey('consolidated_orders', $result);
            
            $this->assertEquals($deliveryDate, $result['delivery_date']);
            $this->assertIsArray($result['consolidated_orders']);
            
            if (isset($result['original_orders_count'])) {
                $this->assertArrayHasKey('consolidated_orders_count', $result);
                $this->assertIsInt($result['original_orders_count']);
                $this->assertIsInt($result['consolidated_orders_count']);
            }
        }
    }

    public function testConsolidateByVendorRoute()
    {
        $vendorId = 1;
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        $options = [
            'max_distance_km' => 10
        ];

        $result = $this->orderConsolidator->consolidateByVendorRoute($vendorId, $deliveryDate, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('vendor_id', $result);
            $this->assertArrayHasKey('delivery_date', $result);
            $this->assertArrayHasKey('optimized_routes', $result);
            
            $this->assertEquals($vendorId, $result['vendor_id']);
            $this->assertEquals($deliveryDate, $result['delivery_date']);
            $this->assertIsArray($result['optimized_routes']);
            
            if (isset($result['total_orders'])) {
                $this->assertArrayHasKey('route_groups', $result);
                $this->assertIsInt($result['total_orders']);
                $this->assertIsInt($result['route_groups']);
            }
        }
    }

    public function testGetConsolidationRecommendations()
    {
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        $filters = [
            'min_orders' => 2,
            'min_savings' => 10
        ];

        $result = $this->orderConsolidator->getConsolidationRecommendations($deliveryDate, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('delivery_date', $result);
            $this->assertArrayHasKey('recommendations_count', $result);
            $this->assertArrayHasKey('recommendations', $result);
            
            $this->assertEquals($deliveryDate, $result['delivery_date']);
            $this->assertIsInt($result['recommendations_count']);
            $this->assertIsArray($result['recommendations']);
            
            // Check recommendation structure
            foreach ($result['recommendations'] as $recommendation) {
                $this->assertArrayHasKey('type', $recommendation);
                $this->assertArrayHasKey('description', $recommendation);
                $this->assertArrayHasKey('orders_count', $recommendation);
                $this->assertArrayHasKey('potential_savings', $recommendation);
                $this->assertArrayHasKey('priority', $recommendation);
                $this->assertArrayHasKey('consolidation_data', $recommendation);
                
                $this->assertIsString($recommendation['type']);
                $this->assertIsString($recommendation['description']);
                $this->assertIsInt($recommendation['orders_count']);
                $this->assertIsArray($recommendation['potential_savings']);
                $this->assertIsInt($recommendation['priority']);
            }
        }
    }

    public function testExecuteAutoConsolidation()
    {
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        $rules = [
            'min_orders_for_consolidation' => 2,
            'max_distance_km' => 5,
            'min_savings_percentage' => 15,
            'consolidate_same_customer' => true,
            'consolidate_same_vendor' => true,
            'consolidate_same_area' => true
        ];

        $result = $this->orderConsolidator->executeAutoConsolidation($deliveryDate, $rules);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('delivery_date', $result);
            $this->assertArrayHasKey('rules_applied', $result);
            $this->assertArrayHasKey('recommendations_evaluated', $result);
            $this->assertArrayHasKey('consolidations_executed', $result);
            $this->assertArrayHasKey('successful_consolidations', $result);
            $this->assertArrayHasKey('consolidation_results', $result);
            
            $this->assertEquals($deliveryDate, $result['delivery_date']);
            $this->assertEquals($rules, $result['rules_applied']);
            $this->assertIsInt($result['recommendations_evaluated']);
            $this->assertIsInt($result['consolidations_executed']);
            $this->assertIsInt($result['successful_consolidations']);
            $this->assertIsArray($result['consolidation_results']);
        }
    }

    public function testGetConsolidationHistory()
    {
        $days = 30;
        $filters = [
            'vendor_id' => 1
        ];

        $result = $this->orderConsolidator->getConsolidationHistory($days, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('period_days', $result);
            $this->assertArrayHasKey('total_activities', $result);
            $this->assertArrayHasKey('activities', $result);
            $this->assertArrayHasKey('statistics', $result);
            
            $this->assertEquals($days, $result['period_days']);
            $this->assertIsInt($result['total_activities']);
            $this->assertIsArray($result['activities']);
            $this->assertIsArray($result['statistics']);
            
            // Check statistics structure
            $stats = $result['statistics'];
            $this->assertArrayHasKey('total_consolidation_activities', $stats);
            $this->assertArrayHasKey('total_orders_consolidated', $stats);
            $this->assertArrayHasKey('total_savings_amount', $stats);
            $this->assertArrayHasKey('average_orders_per_consolidation', $stats);
            $this->assertArrayHasKey('average_savings_per_consolidation', $stats);
        }

        // Test without filters
        $result = $this->orderConsolidator->getConsolidationHistory($days);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testConsolidationStrategies()
    {
        // Test all consolidation strategy constants
        $this->assertEquals('location', OrderConsolidator::STRATEGY_BY_LOCATION);
        $this->assertEquals('vendor', OrderConsolidator::STRATEGY_BY_VENDOR);
        $this->assertEquals('delivery_slot', OrderConsolidator::STRATEGY_BY_DELIVERY_SLOT);
        $this->assertEquals('customer', OrderConsolidator::STRATEGY_BY_CUSTOMER);
        $this->assertEquals('mixed', OrderConsolidator::STRATEGY_MIXED);
    }

    public function testOrderTypes()
    {
        // Test order type constants
        $this->assertEquals('subscription', OrderConsolidator::ORDER_TYPE_SUBSCRIPTION);
        $this->assertEquals('on_demand', OrderConsolidator::ORDER_TYPE_ON_DEMAND);
    }

    public function testConsolidateOrdersForSlotWithDifferentStrategies()
    {
        $deliverySlotId = 1;
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        
        $strategies = [
            OrderConsolidator::STRATEGY_BY_LOCATION,
            OrderConsolidator::STRATEGY_BY_VENDOR,
            OrderConsolidator::STRATEGY_BY_DELIVERY_SLOT,
            OrderConsolidator::STRATEGY_BY_CUSTOMER,
            OrderConsolidator::STRATEGY_MIXED
        ];

        foreach ($strategies as $strategy) {
            $options = ['strategy' => $strategy];
            $result = $this->orderConsolidator->consolidateOrdersForSlot($deliverySlotId, $deliveryDate, $options);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertEquals($strategy, $result['strategy']);
            }
        }
    }

    public function testConsolidationWithEmptyOrders()
    {
        $deliverySlotId = 999; // Non-existent slot
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));

        $result = $this->orderConsolidator->consolidateOrdersForSlot($deliverySlotId, $deliveryDate);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertStringContains('No pending orders found', $result['message']);
            $this->assertEquals(0, $result['total_orders']);
        }
    }

    public function testConsolidationWithInvalidDate()
    {
        $deliverySlotId = 1;
        $invalidDate = 'invalid-date';

        $result = $this->orderConsolidator->consolidateOrdersForSlot($deliverySlotId, $invalidDate);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should handle invalid date gracefully
        if (!$result['success']) {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testConsolidationRulesValidation()
    {
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));
        
        // Test with strict rules (should result in fewer consolidations)
        $strictRules = [
            'min_orders_for_consolidation' => 5,
            'min_savings_percentage' => 50
        ];

        $result = $this->orderConsolidator->executeAutoConsolidation($deliveryDate, $strictRules);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertEquals($strictRules['min_orders_for_consolidation'], 
                              $result['rules_applied']['min_orders_for_consolidation']);
            $this->assertEquals($strictRules['min_savings_percentage'], 
                              $result['rules_applied']['min_savings_percentage']);
        }

        // Test with lenient rules (should result in more consolidations)
        $lenientRules = [
            'min_orders_for_consolidation' => 2,
            'min_savings_percentage' => 5
        ];

        $result = $this->orderConsolidator->executeAutoConsolidation($deliveryDate, $lenientRules);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testConsolidationHistoryWithDifferentTimeRanges()
    {
        $timeRanges = [7, 14, 30, 90];

        foreach ($timeRanges as $days) {
            $result = $this->orderConsolidator->getConsolidationHistory($days);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertEquals($days, $result['period_days']);
            }
        }
    }

    public function testVendorRouteConsolidationWithInvalidVendor()
    {
        $invalidVendorId = 999999;
        $deliveryDate = date('Y-m-d', strtotime('+1 day'));

        $result = $this->orderConsolidator->consolidateByVendorRoute($invalidVendorId, $deliveryDate);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertStringContains('No vendor orders found', $result['message']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}