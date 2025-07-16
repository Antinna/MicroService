<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\DeliveryNotificationService;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class DeliveryNotificationServiceTest extends TestCase
{
    private DeliveryNotificationService $deliveryNotificationService;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->deliveryNotificationService = new DeliveryNotificationService();
    }

    public function testSendDeliveryStatusNotification()
    {
        // Test with non-existent order (should fail gracefully)
        $result = $this->deliveryNotificationService->sendDeliveryStatusNotification(
            999, 
            DeliveryNotificationService::STATUS_SCHEDULED
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testSendBulkDeliveryNotifications()
    {
        $orderUpdates = [
            [
                'order_id' => 1,
                'status' => DeliveryNotificationService::STATUS_SCHEDULED,
                'additional_data' => ['delivery_date' => '2024-01-15']
            ],
            [
                'order_id' => 2,
                'status' => DeliveryNotificationService::STATUS_OUT_FOR_DELIVERY,
                'additional_data' => ['estimated_arrival' => '14:30']
            ]
        ];

        $result = $this->deliveryNotificationService->sendBulkDeliveryNotifications($orderUpdates);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('total_orders', $result);
        $this->assertArrayHasKey('success_count', $result);
        $this->assertArrayHasKey('failure_count', $result);
        $this->assertArrayHasKey('results', $result);

        $this->assertEquals(2, $result['total_orders']);
        $this->assertCount(2, $result['results']);
    }

    public function testSendDeliveryFailureNotification()
    {
        $retryOptions = [
            'retry_date' => '2024-01-16',
            'retry_time_slot' => '10:00-12:00',
            'max_attempts' => 3,
            'current_attempt' => 1,
            'retry_reason' => 'Customer not available'
        ];

        $result = $this->deliveryNotificationService->sendDeliveryFailureNotification(
            999, 
            'Customer not available',
            $retryOptions
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testSendDeliveryCompletionNotification()
    {
        $deliveryDetails = [
            'delivery_person' => 'John Doe',
            'delivery_time' => '14:30',
            'delivery_photo' => 'photo_url.jpg',
            'customer_rating_requested' => true
        ];

        $result = $this->deliveryNotificationService->sendDeliveryCompletionNotification(
            999, 
            $deliveryDetails
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetDeliveryNotificationHistory()
    {
        // Test without order filter
        $result = $this->deliveryNotificationService->getDeliveryNotificationHistory();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('notifications', $result);
            $this->assertArrayHasKey('summary', $result);
            $this->assertArrayHasKey('total_notifications', $result);
            $this->assertArrayHasKey('period_days', $result);
            
            $this->assertIsArray($result['notifications']);
            $this->assertIsArray($result['summary']);
            $this->assertEquals(7, $result['period_days']);
        }

        // Test with specific order
        $result = $this->deliveryNotificationService->getDeliveryNotificationHistory(1, 30);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertEquals(30, $result['period_days']);
        }
    }

    public function testGetDeliveryStatistics()
    {
        // Test without vendor filter
        $result = $this->deliveryNotificationService->getDeliveryStatistics();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('status_statistics', $result);
            $this->assertArrayHasKey('daily_trends', $result);
            $this->assertArrayHasKey('summary', $result);
            $this->assertArrayHasKey('period_days', $result);
            
            $this->assertIsArray($result['status_statistics']);
            $this->assertIsArray($result['daily_trends']);
            $this->assertIsArray($result['summary']);
            $this->assertEquals(30, $result['period_days']);
            
            // Check summary structure
            $summary = $result['summary'];
            $this->assertArrayHasKey('total_deliveries', $summary);
            $this->assertArrayHasKey('successful_deliveries', $summary);
            $this->assertArrayHasKey('failed_deliveries', $summary);
            $this->assertArrayHasKey('success_rate_percentage', $summary);
        }

        // Test with vendor filter
        $result = $this->deliveryNotificationService->getDeliveryStatistics(1, 7);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertEquals(1, $result['vendor_id']);
            $this->assertEquals(7, $result['period_days']);
        }
    }

    public function testDeliveryStatusConstants()
    {
        // Test that all status constants are defined
        $this->assertEquals('scheduled', DeliveryNotificationService::STATUS_SCHEDULED);
        $this->assertEquals('picked_up', DeliveryNotificationService::STATUS_PICKED_UP);
        $this->assertEquals('in_transit', DeliveryNotificationService::STATUS_IN_TRANSIT);
        $this->assertEquals('out_for_delivery', DeliveryNotificationService::STATUS_OUT_FOR_DELIVERY);
        $this->assertEquals('delivered', DeliveryNotificationService::STATUS_DELIVERED);
        $this->assertEquals('failed', DeliveryNotificationService::STATUS_FAILED);
        $this->assertEquals('cancelled', DeliveryNotificationService::STATUS_CANCELLED);
        $this->assertEquals('returned', DeliveryNotificationService::STATUS_RETURNED);
    }

    public function testNotificationTypeConstants()
    {
        // Test that all notification type constants are defined
        $this->assertEquals('customer', DeliveryNotificationService::NOTIFICATION_CUSTOMER);
        $this->assertEquals('vendor', DeliveryNotificationService::NOTIFICATION_VENDOR);
        $this->assertEquals('delivery_agent', DeliveryNotificationService::NOTIFICATION_DELIVERY_AGENT);
        $this->assertEquals('admin', DeliveryNotificationService::NOTIFICATION_ADMIN);
    }

    public function testInvalidDeliveryStatus()
    {
        // Test with invalid status
        $result = $this->deliveryNotificationService->sendDeliveryStatusNotification(
            999, 
            'invalid_status'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist, not because of invalid status
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testEmptyBulkNotifications()
    {
        $result = $this->deliveryNotificationService->sendBulkDeliveryNotifications([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('total_orders', $result);
        $this->assertArrayHasKey('success_count', $result);
        $this->assertArrayHasKey('failure_count', $result);
        $this->assertArrayHasKey('results', $result);

        $this->assertEquals(0, $result['total_orders']);
        $this->assertEquals(0, $result['success_count']);
        $this->assertEquals(0, $result['failure_count']);
        $this->assertEmpty($result['results']);
    }

    public function testMalformedBulkNotificationData()
    {
        $malformedUpdates = [
            [
                // Missing order_id
                'status' => DeliveryNotificationService::STATUS_SCHEDULED
            ],
            [
                'order_id' => 'invalid_id', // Invalid order ID
                'status' => DeliveryNotificationService::STATUS_DELIVERED
            ]
        ];

        $result = $this->deliveryNotificationService->sendBulkDeliveryNotifications($malformedUpdates);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('total_orders', $result);
        $this->assertArrayHasKey('failure_count', $result);

        $this->assertEquals(2, $result['total_orders']);
        // Should have failures due to malformed data
        $this->assertGreaterThan(0, $result['failure_count']);
    }

    public function testDeliveryFailureWithoutRetryOptions()
    {
        $result = $this->deliveryNotificationService->sendDeliveryFailureNotification(
            999, 
            'Address not found'
            // No retry options provided
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testDeliveryCompletionWithMinimalDetails()
    {
        $result = $this->deliveryNotificationService->sendDeliveryCompletionNotification(999);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because order doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testStatisticsWithInvalidParameters()
    {
        // Test with negative days
        $result = $this->deliveryNotificationService->getDeliveryStatistics(null, -5);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Service should handle invalid parameters gracefully
        if ($result['success']) {
            $this->assertArrayHasKey('period_days', $result);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}