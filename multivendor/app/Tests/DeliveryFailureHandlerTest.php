<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\DeliveryFailureHandler;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class DeliveryFailureHandlerTest extends TestCase
{
    private DeliveryFailureHandler $deliveryFailureHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->deliveryFailureHandler = new DeliveryFailureHandler();
    }

    public function testHandleDeliveryFailure()
    {
        $orderId = 999; // Non-existent order for testing
        $failureReason = DeliveryFailureHandler::REASON_CUSTOMER_UNAVAILABLE;
        $failureDetails = [
            'delivery_agent' => 'John Doe',
            'attempted_at' => '2024-01-15 14:30:00',
            'notes' => 'Customer not at home, no response to calls'
        ];

        $result = $this->deliveryFailureHandler->handleDeliveryFailure($orderId, $failureReason, $failureDetails);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testProcessAutomaticRefund()
    {
        $orderId = 999; // Non-existent order for testing
        $refundType = DeliveryFailureHandler::REFUND_FULL;
        $refundDetails = [
            'reason' => 'Product damaged during delivery'
        ];

        $result = $this->deliveryFailureHandler->processAutomaticRefund($orderId, $refundType, $refundDetails);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testScheduleDeliveryRetry()
    {
        $orderId = 999; // Non-existent order for testing
        $retryOptions = [
            'delay_days' => 2,
            'time_slot' => '10:00-12:00',
            'reason' => 'Customer requested reschedule'
        ];

        $result = $this->deliveryFailureHandler->scheduleDeliveryRetry($orderId, $retryOptions);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testGetDeliveryFailureStatistics()
    {
        $days = 30;
        $filters = [
            'vendor_id' => 1,
            'failure_reason' => DeliveryFailureHandler::REASON_CUSTOMER_UNAVAILABLE
        ];

        $result = $this->deliveryFailureHandler->getDeliveryFailureStatistics($days, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('period_days', $result);
            $this->assertArrayHasKey('failure_statistics', $result);
            $this->assertArrayHasKey('daily_trends', $result);
            $this->assertArrayHasKey('summary', $result);
            
            $this->assertEquals($days, $result['period_days']);
            $this->assertIsArray($result['failure_statistics']);
            $this->assertIsArray($result['daily_trends']);
            $this->assertIsArray($result['summary']);
            
            // Check summary structure
            $summary = $result['summary'];
            $this->assertArrayHasKey('total_failures', $summary);
            $this->assertArrayHasKey('total_refunds', $summary);
            $this->assertArrayHasKey('total_retries', $summary);
            $this->assertArrayHasKey('refund_rate_percentage', $summary);
            $this->assertArrayHasKey('retry_rate_percentage', $summary);
        }

        // Test without filters
        $result = $this->deliveryFailureHandler->getDeliveryFailureStatistics($days);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testGetRefundHistory()
    {
        $days = 30;
        $filters = [
            'vendor_id' => 1,
            'refund_status' => 'completed'
        ];

        $result = $this->deliveryFailureHandler->getRefundHistory($days, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('period_days', $result);
            $this->assertArrayHasKey('refunds', $result);
            $this->assertArrayHasKey('summary', $result);
            
            $this->assertEquals($days, $result['period_days']);
            $this->assertIsArray($result['refunds']);
            $this->assertIsArray($result['summary']);
            
            // Check summary structure
            $summary = $result['summary'];
            $this->assertArrayHasKey('total_refunds', $summary);
            $this->assertArrayHasKey('completed_refunds', $summary);
            $this->assertArrayHasKey('total_refund_amount', $summary);
            $this->assertArrayHasKey('completed_refund_amount', $summary);
            $this->assertArrayHasKey('average_refund_amount', $summary);
        }

        // Test without filters
        $result = $this->deliveryFailureHandler->getRefundHistory($days);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testFailureReasonConstants()
    {
        // Test that all failure reason constants are defined
        $this->assertEquals('customer_unavailable', DeliveryFailureHandler::REASON_CUSTOMER_UNAVAILABLE);
        $this->assertEquals('address_not_found', DeliveryFailureHandler::REASON_ADDRESS_NOT_FOUND);
        $this->assertEquals('customer_refused', DeliveryFailureHandler::REASON_CUSTOMER_REFUSED);
        $this->assertEquals('product_damaged', DeliveryFailureHandler::REASON_PRODUCT_DAMAGED);
        $this->assertEquals('vehicle_breakdown', DeliveryFailureHandler::REASON_VEHICLE_BREAKDOWN);
        $this->assertEquals('weather_conditions', DeliveryFailureHandler::REASON_WEATHER_CONDITIONS);
        $this->assertEquals('security_issues', DeliveryFailureHandler::REASON_SECURITY_ISSUES);
        $this->assertEquals('other', DeliveryFailureHandler::REASON_OTHER);
    }

    public function testFailureActionConstants()
    {
        // Test that all failure action constants are defined
        $this->assertEquals('retry', DeliveryFailureHandler::ACTION_RETRY);
        $this->assertEquals('refund', DeliveryFailureHandler::ACTION_REFUND);
        $this->assertEquals('reschedule', DeliveryFailureHandler::ACTION_RESCHEDULE);
        $this->assertEquals('return_to_vendor', DeliveryFailureHandler::ACTION_RETURN_TO_VENDOR);
        $this->assertEquals('manual_review', DeliveryFailureHandler::ACTION_MANUAL_REVIEW);
    }

    public function testRefundTypeConstants()
    {
        // Test that all refund type constants are defined
        $this->assertEquals('full', DeliveryFailureHandler::REFUND_FULL);
        $this->assertEquals('partial', DeliveryFailureHandler::REFUND_PARTIAL);
        $this->assertEquals('delivery_fee_only', DeliveryFailureHandler::REFUND_DELIVERY_FEE_ONLY);
    }

    public function testHandleDeliveryFailureWithDifferentReasons()
    {
        $orderId = 999;
        $failureReasons = [
            DeliveryFailureHandler::REASON_CUSTOMER_UNAVAILABLE,
            DeliveryFailureHandler::REASON_ADDRESS_NOT_FOUND,
            DeliveryFailureHandler::REASON_CUSTOMER_REFUSED,
            DeliveryFailureHandler::REASON_PRODUCT_DAMAGED,
            DeliveryFailureHandler::REASON_VEHICLE_BREAKDOWN,
            DeliveryFailureHandler::REASON_WEATHER_CONDITIONS,
            DeliveryFailureHandler::REASON_SECURITY_ISSUES,
            DeliveryFailureHandler::REASON_OTHER
        ];

        foreach ($failureReasons as $reason) {
            $result = $this->deliveryFailureHandler->handleDeliveryFailure($orderId, $reason);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because order doesn't exist, but test that all reasons are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testProcessAutomaticRefundWithDifferentTypes()
    {
        $orderId = 999;
        $refundTypes = [
            DeliveryFailureHandler::REFUND_FULL,
            DeliveryFailureHandler::REFUND_PARTIAL,
            DeliveryFailureHandler::REFUND_DELIVERY_FEE_ONLY
        ];

        foreach ($refundTypes as $refundType) {
            $result = $this->deliveryFailureHandler->processAutomaticRefund($orderId, $refundType);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because order doesn't exist, but test that all types are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testScheduleDeliveryRetryWithDifferentOptions()
    {
        $orderId = 999;
        $retryOptionsVariations = [
            [], // Default options
            ['delay_days' => 1],
            ['delay_days' => 3, 'time_slot' => '14:00-16:00'],
            ['delay_days' => 2, 'reason' => 'Customer requested specific time'],
            ['delivery_slot_id' => 5, 'delay_days' => 1]
        ];

        foreach ($retryOptionsVariations as $options) {
            $result = $this->deliveryFailureHandler->scheduleDeliveryRetry($orderId, $options);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because order doesn't exist, but test that all options are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testStatisticsWithDifferentTimeRanges()
    {
        $timeRanges = [7, 14, 30, 60, 90];

        foreach ($timeRanges as $days) {
            $result = $this->deliveryFailureHandler->getDeliveryFailureStatistics($days);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertEquals($days, $result['period_days']);
            }
        }
    }

    public function testRefundHistoryWithDifferentFilters()
    {
        $filterVariations = [
            [], // No filters
            ['vendor_id' => 1],
            ['refund_status' => 'completed'],
            ['vendor_id' => 1, 'refund_status' => 'pending'],
            ['refund_status' => 'failed']
        ];

        foreach ($filterVariations as $filters) {
            $result = $this->deliveryFailureHandler->getRefundHistory(30, $filters);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertArrayHasKey('refunds', $result);
                $this->assertArrayHasKey('summary', $result);
            }
        }
    }

    public function testFailureStatisticsWithDifferentFilters()
    {
        $filterVariations = [
            [], // No filters
            ['vendor_id' => 1],
            ['failure_reason' => DeliveryFailureHandler::REASON_CUSTOMER_UNAVAILABLE],
            ['vendor_id' => 1, 'failure_reason' => DeliveryFailureHandler::REASON_PRODUCT_DAMAGED]
        ];

        foreach ($filterVariations as $filters) {
            $result = $this->deliveryFailureHandler->getDeliveryFailureStatistics(30, $filters);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertArrayHasKey('failure_statistics', $result);
                $this->assertArrayHasKey('summary', $result);
            }
        }
    }

    public function testHandleDeliveryFailureWithEmptyDetails()
    {
        $orderId = 999;
        $failureReason = DeliveryFailureHandler::REASON_CUSTOMER_UNAVAILABLE;
        $emptyDetails = [];

        $result = $this->deliveryFailureHandler->handleDeliveryFailure($orderId, $failureReason, $emptyDetails);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should handle empty details gracefully
        $this->assertFalse($result['success']); // Fails due to non-existent order
        $this->assertArrayHasKey('error', $result);
    }

    public function testProcessAutomaticRefundWithEmptyDetails()
    {
        $orderId = 999;
        $refundType = DeliveryFailureHandler::REFUND_PARTIAL;
        $emptyDetails = [];

        $result = $this->deliveryFailureHandler->processAutomaticRefund($orderId, $refundType, $emptyDetails);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should handle empty details gracefully
        $this->assertFalse($result['success']); // Fails due to non-existent order
        $this->assertArrayHasKey('error', $result);
    }

    public function testInvalidFailureReason()
    {
        $orderId = 999;
        $invalidReason = 'invalid_reason';

        $result = $this->deliveryFailureHandler->handleDeliveryFailure($orderId, $invalidReason);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should handle invalid reason gracefully
        $this->assertFalse($result['success']); // Fails due to non-existent order
        $this->assertArrayHasKey('error', $result);
    }

    public function testInvalidRefundType()
    {
        $orderId = 999;
        $invalidRefundType = 'invalid_refund_type';

        $result = $this->deliveryFailureHandler->processAutomaticRefund($orderId, $invalidRefundType);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should handle invalid refund type gracefully
        $this->assertFalse($result['success']); // Fails due to non-existent order
        $this->assertArrayHasKey('error', $result);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}