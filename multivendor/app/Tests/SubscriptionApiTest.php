<?php

namespace Antinna\MultiVendor\Tests;

use PHPUnit\Framework\TestCase;

class SubscriptionApiTest extends TestCase
{
    private string $baseUrl = 'http://localhost/api';
    private array $testSubscriptionData;
    private ?int $testSubscriptionId = null;

    protected function setUp(): void
    {
        $this->testSubscriptionData = [
            'customer_id' => 1,
            'vendor_id' => 1,
            'products' => [
                [
                    'product_id' => 1,
                    'quantity' => 2
                ],
                [
                    'product_id' => 2,
                    'quantity' => 1
                ]
            ],
            'delivery_frequency' => 'weekly',
            'delivery_preferences' => [
                'preferred_time' => '08:00-10:00',
                'delivery_address' => '123 Main St, Mumbai',
                'special_instructions' => 'Ring doorbell twice'
            ],
            'start_date' => date('Y-m-d', strtotime('+1 day')),
            'end_date' => date('Y-m-d', strtotime('+3 months'))
        ];
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->testSubscriptionId) {
            $this->deleteTestSubscription($this->testSubscriptionId);
        }
    }

    public function testCreateSubscriptionSuccess()
    {
        $response = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('subscription_id', $response['data']);
        $this->assertArrayHasKey('subscription', $response['data']);
        
        // Store for cleanup
        $this->testSubscriptionId = $response['data']['subscription_id'];
    }

    public function testCreateSubscriptionWithMissingFields()
    {
        $incompleteData = [
            'customer_id' => 1,
            'vendor_id' => 1,
            // Missing required fields
        ];

        $response = $this->makeRequest('POST', '/subscriptions', $incompleteData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('details', $response['data']);
    }

    public function testCreateSubscriptionWithInvalidFrequency()
    {
        $invalidData = $this->testSubscriptionData;
        $invalidData['delivery_frequency'] = 'invalid_frequency';

        $response = $this->makeRequest('POST', '/subscriptions', $invalidData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
    }

    public function testGetSubscriptionSuccess()
    {
        // First create a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->testSubscriptionId = $createResponse['data']['subscription_id'];

        // Get the subscription
        $response = $this->makeRequest('GET', "/subscriptions/{$this->testSubscriptionId}");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('subscription', $response['data']);
        $this->assertEquals($this->testSubscriptionData['customer_id'], $response['data']['subscription']['customer_id']);
    }

    public function testGetSubscriptionNotFound()
    {
        $response = $this->makeRequest('GET', '/subscriptions/99999');
        
        $this->assertEquals(404, $response['status_code']);
        $this->assertFalse($response['data']['success']);
    }

    public function testUpdateSubscriptionSuccess()
    {
        // First create a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->testSubscriptionId = $createResponse['data']['subscription_id'];

        // Update the subscription
        $updateData = [
            'delivery_frequency' => 'daily',
            'delivery_preferences' => [
                'preferred_time' => '10:00-12:00',
                'delivery_address' => '456 Oak St, Mumbai'
            ]
        ];

        $response = $this->makeRequest('PUT', "/subscriptions/{$this->testSubscriptionId}", $updateData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('updated_fields', $response['data']);
    }

    public function testGetSubscriptionsWithFilters()
    {
        $response = $this->makeRequest('GET', '/subscriptions?customer_id=1&status=active&page=1&limit=10');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('subscriptions', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('filters_applied', $response['data']);
    }

    public function testPauseSubscriptionSuccess()
    {
        // First create a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->testSubscriptionId = $createResponse['data']['subscription_id'];

        // Pause the subscription
        $pauseData = [
            'pause_until' => date('Y-m-d', strtotime('+1 week')),
            'reason' => 'Going on vacation'
        ];

        $response = $this->makeRequest('PATCH', "/subscriptions/{$this->testSubscriptionId}/pause", $pauseData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('pause_until', $response['data']);
    }

    public function testResumeSubscriptionSuccess()
    {
        // First create and pause a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->testSubscriptionId = $createResponse['data']['subscription_id'];

        $pauseData = ['reason' => 'Test pause'];
        $this->makeRequest('PATCH', "/subscriptions/{$this->testSubscriptionId}/pause", $pauseData);

        // Resume the subscription
        $response = $this->makeRequest('PATCH', "/subscriptions/{$this->testSubscriptionId}/resume");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('resumed_at', $response['data']);
    }

    public function testCancelSubscriptionSuccess()
    {
        // First create a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $subscriptionId = $createResponse['data']['subscription_id'];

        // Cancel the subscription
        $cancelData = [
            'reason' => 'No longer needed'
        ];

        $response = $this->makeRequest('DELETE', "/subscriptions/{$subscriptionId}", $cancelData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('cancelled_at', $response['data']);

        // Verify subscription is cancelled
        $getResponse = $this->makeRequest('GET', "/subscriptions/{$subscriptionId}");
        $this->assertEquals('cancelled', $getResponse['data']['subscription']['status']);
    }

    public function testGetCustomerSubscriptions()
    {
        $response = $this->makeRequest('GET', '/customers/1/subscriptions?status=active');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('subscriptions', $response['data']);
        $this->assertArrayHasKey('customer_id', $response['data']);
        $this->assertEquals(1, $response['data']['customer_id']);
    }

    public function testGetVendorSubscriptions()
    {
        $response = $this->makeRequest('GET', '/vendors/1/subscriptions?status=active');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('subscriptions', $response['data']);
        $this->assertArrayHasKey('vendor_id', $response['data']);
        $this->assertEquals(1, $response['data']['vendor_id']);
    }

    public function testGenerateSubscriptionOrders()
    {
        $orderData = [
            'date' => date('Y-m-d'),
            'vendor_id' => 1
        ];

        $response = $this->makeRequest('POST', '/subscriptions/generate-orders', $orderData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('orders_generated', $response['data']);
        $this->assertArrayHasKey('total_subscriptions_processed', $response['data']);
    }

    public function testGetSubscriptionOrders()
    {
        // First create a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->testSubscriptionId = $createResponse['data']['subscription_id'];

        $response = $this->makeRequest('GET', "/subscriptions/{$this->testSubscriptionId}/orders");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('orders', $response['data']);
        $this->assertArrayHasKey('subscription_id', $response['data']);
    }

    public function testGetAvailableDeliverySlots()
    {
        $response = $this->makeRequest('GET', '/delivery/slots?vendor_id=1&date=' . date('Y-m-d', strtotime('+1 day')));
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('available_slots', $response['data']);
        $this->assertArrayHasKey('date', $response['data']);
    }

    public function testBookDeliverySlotSuccess()
    {
        $bookingData = [
            'slot_id' => 1,
            'order_id' => 1,
            'customer_id' => 1,
            'special_instructions' => 'Call before delivery'
        ];

        $response = $this->makeRequest('POST', '/delivery/slots/book', $bookingData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('booking_id', $response['data']);
        $this->assertArrayHasKey('slot_details', $response['data']);
    }

    public function testBookDeliverySlotWithMissingData()
    {
        $incompleteData = [
            'slot_id' => 1,
            // Missing required fields
        ];

        $response = $this->makeRequest('POST', '/delivery/slots/book', $incompleteData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('details', $response['data']);
    }

    public function testGetSlotCapacity()
    {
        $response = $this->makeRequest('GET', '/delivery/slots/1/capacity');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('slot_id', $response['data']);
        $this->assertArrayHasKey('total_capacity', $response['data']);
        $this->assertArrayHasKey('available_capacity', $response['data']);
        $this->assertArrayHasKey('booked_capacity', $response['data']);
    }

    public function testCancelSlotBooking()
    {
        $cancelData = [
            'reason' => 'Customer request'
        ];

        $response = $this->makeRequest('DELETE', '/delivery/slots/1/bookings/1', $cancelData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('cancelled_at', $response['data']);
    }

    public function testConsolidateOrders()
    {
        $consolidationData = [
            'vendor_id' => 1,
            'delivery_date' => date('Y-m-d', strtotime('+1 day')),
            'location' => 'Mumbai'
        ];

        $response = $this->makeRequest('POST', '/orders/consolidate', $consolidationData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('consolidated_orders', $response['data']);
        $this->assertArrayHasKey('total_orders_processed', $response['data']);
    }

    public function testGetConsolidatedOrders()
    {
        $response = $this->makeRequest('GET', '/orders/consolidated?vendor_id=1&delivery_date=' . date('Y-m-d'));
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('consolidated_orders', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
    }

    public function testReportDeliveryFailure()
    {
        $failureData = [
            'order_id' => 1,
            'failure_reason' => 'customer_not_available',
            'failure_details' => 'Customer did not answer door or phone',
            'delivery_attempt_time' => date('Y-m-d H:i:s')
        ];

        $response = $this->makeRequest('POST', '/delivery/failures', $failureData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('failure_id', $response['data']);
        $this->assertArrayHasKey('next_action', $response['data']);
    }

    public function testReportDeliveryFailureWithMissingData()
    {
        $incompleteData = [
            'order_id' => 1,
            // Missing failure_reason
        ];

        $response = $this->makeRequest('POST', '/delivery/failures', $incompleteData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('details', $response['data']);
    }

    public function testProcessDeliveryFailure()
    {
        $processData = [
            'action' => 'reschedule',
            'notes' => 'Rescheduled for next available slot'
        ];

        $response = $this->makeRequest('POST', '/delivery/failures/1/process', $processData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('action_taken', $response['data']);
        $this->assertArrayHasKey('processed_at', $response['data']);
    }

    public function testGetDeliveryFailures()
    {
        $response = $this->makeRequest('GET', '/delivery/failures?vendor_id=1&status=pending');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('failures', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('filters_applied', $response['data']);
    }

    public function testGetSubscriptionAnalytics()
    {
        $response = $this->makeRequest('GET', '/subscriptions/analytics?vendor_id=1&date_from=' . date('Y-m-d', strtotime('-30 days')));
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('analytics', $response['data']);
        $this->assertArrayHasKey('total_subscriptions', $response['data']['analytics']);
        $this->assertArrayHasKey('active_subscriptions', $response['data']['analytics']);
        $this->assertArrayHasKey('revenue_metrics', $response['data']['analytics']);
    }

    public function testGetDeliveryPerformance()
    {
        $response = $this->makeRequest('GET', '/delivery/performance?vendor_id=1&date_from=' . date('Y-m-d', strtotime('-30 days')));
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('performance_metrics', $response['data']);
        $this->assertArrayHasKey('on_time_delivery_rate', $response['data']['performance_metrics']);
        $this->assertArrayHasKey('failure_rate', $response['data']['performance_metrics']);
    }

    public function testValidateSubscriptionData()
    {
        $response = $this->makeRequest('POST', '/subscriptions/validate', $this->testSubscriptionData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertTrue($response['data']['valid']);
        $this->assertEmpty($response['data']['errors']);
    }

    public function testValidateSubscriptionDataWithInvalidData()
    {
        $invalidData = [
            'customer_id' => 'invalid',
            'delivery_frequency' => 'invalid_frequency',
            'products' => []
        ];

        $response = $this->makeRequest('POST', '/subscriptions/validate', $invalidData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertFalse($response['data']['valid']);
        $this->assertNotEmpty($response['data']['errors']);
    }

    public function testGetSubscriptionHistory()
    {
        // First create a subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->testSubscriptionId = $createResponse['data']['subscription_id'];

        $response = $this->makeRequest('GET', "/subscriptions/{$this->testSubscriptionId}/history");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('history', $response['data']);
        $this->assertArrayHasKey('subscription_id', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
    }

    public function testSubscriptionWorkflow()
    {
        // Create subscription
        $createResponse = $this->makeRequest('POST', '/subscriptions', $this->testSubscriptionData);
        $this->assertEquals(201, $createResponse['status_code']);
        $subscriptionId = $createResponse['data']['subscription_id'];

        // Update subscription
        $updateData = ['delivery_frequency' => 'daily'];
        $updateResponse = $this->makeRequest('PUT', "/subscriptions/{$subscriptionId}", $updateData);
        $this->assertEquals(200, $updateResponse['status_code']);

        // Pause subscription
        $pauseData = ['reason' => 'Test workflow'];
        $pauseResponse = $this->makeRequest('PATCH', "/subscriptions/{$subscriptionId}/pause", $pauseData);
        $this->assertEquals(200, $pauseResponse['status_code']);

        // Resume subscription
        $resumeResponse = $this->makeRequest('PATCH', "/subscriptions/{$subscriptionId}/resume");
        $this->assertEquals(200, $resumeResponse['status_code']);

        // Cancel subscription
        $cancelData = ['reason' => 'End of test'];
        $cancelResponse = $this->makeRequest('DELETE', "/subscriptions/{$subscriptionId}", $cancelData);
        $this->assertEquals(200, $cancelResponse['status_code']);

        // Verify final state
        $getResponse = $this->makeRequest('GET', "/subscriptions/{$subscriptionId}");
        $this->assertEquals('cancelled', $getResponse['data']['subscription']['status']);
    }

    /**
     * Make HTTP request to API endpoint
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status_code' => $statusCode,
            'data' => json_decode($response, true) ?? []
        ];
    }

    /**
     * Delete test subscription for cleanup
     */
    private function deleteTestSubscription(int $subscriptionId): void
    {
        $this->makeRequest('DELETE', "/subscriptions/{$subscriptionId}", ['reason' => 'Test cleanup']);
    }
}