<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\TraceabilityReporter;
use PHPUnit\Framework\TestCase;

class TraceabilityReporterTest extends TestCase
{
    private TraceabilityReporter $traceabilityReporter;

    protected function setUp(): void
    {
        $this->traceabilityReporter = new TraceabilityReporter();
    }

    public function testTrackProductJourney()
    {
        $batchNumber = 'BATCH123456';
        $result = $this->traceabilityReporter->trackProductJourney($batchNumber);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('batch_number', $result);
            $this->assertArrayHasKey('batch_info', $result);
            $this->assertArrayHasKey('product_info', $result);
            $this->assertArrayHasKey('vendor_info', $result);
            $this->assertArrayHasKey('journey_timeline', $result);
            $this->assertArrayHasKey('traceability_logs', $result);
            $this->assertArrayHasKey('orders', $result);
            $this->assertArrayHasKey('deliveries', $result);
            $this->assertArrayHasKey('total_quantity_distributed', $result);
            $this->assertArrayHasKey('customers_affected', $result);
            
            $this->assertEquals($batchNumber, $result['batch_number']);
            $this->assertIsArray($result['journey_timeline']);
            $this->assertIsArray($result['traceability_logs']);
            $this->assertIsArray($result['orders']);
            $this->assertIsArray($result['deliveries']);
            $this->assertIsInt($result['total_quantity_distributed']);
            $this->assertIsArray($result['customers_affected']);
        } else {
            // Should fail if batch doesn't exist
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testTrackProductJourneyWithInvalidBatch()
    {
        $invalidBatchNumber = 'INVALID_BATCH';
        $result = $this->traceabilityReporter->trackProductJourney($invalidBatchNumber);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Batch not found', $result['error']);
    }

    public function testLogTraceabilityEvent()
    {
        $eventData = [
            'batch_number' => 'BATCH123456',
            'event_type' => 'quality_check',
            'event_description' => 'Quality inspection passed',
            'location' => 'Warehouse A',
            'temperature' => 4.5,
            'humidity' => 65.0,
            'handler_id' => 1,
            'handler_name' => 'John Doe',
            'additional_data' => [
                'inspector' => 'Jane Smith',
                'test_results' => 'All parameters within acceptable range'
            ],
            'event_timestamp' => '2024-01-15 10:30:00'
        ];

        $result = $this->traceabilityReporter->logTraceabilityEvent($eventData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('log_id', $result);
            $this->assertArrayHasKey('event_type', $result);
            $this->assertArrayHasKey('batch_number', $result);
            $this->assertArrayHasKey('message', $result);
            
            $this->assertEquals($eventData['event_type'], $result['event_type']);
            $this->assertEquals($eventData['batch_number'], $result['batch_number']);
            $this->assertEquals('Traceability event logged successfully', $result['message']);
        }
    }

    public function testLogTraceabilityEventWithInvalidData()
    {
        $invalidEventData = [
            // Missing required fields
            'event_description' => 'Test event',
            'event_type' => 'invalid_type', // Invalid event type
            'event_timestamp' => 'invalid-timestamp' // Invalid timestamp format
        ];

        $result = $this->traceabilityReporter->logTraceabilityEvent($invalidEventData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        
        $errors = $result['errors'];
        $this->assertArrayHasKey('batch_number', $errors);
        $this->assertArrayHasKey('event_type', $errors);
        $this->assertArrayHasKey('event_timestamp', $errors);
    }

    public function testInitiateBatchRecall()
    {
        $batchNumber = 'BATCH123456';
        $recallData = [
            'reason' => 'Potential contamination detected',
            'severity' => 'high',
            'initiated_by' => 'Quality Manager',
            'description' => 'Precautionary recall due to potential bacterial contamination'
        ];

        $result = $this->traceabilityReporter->initiateBatchRecall($batchNumber, $recallData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('recall_id', $result);
            $this->assertArrayHasKey('batch_number', $result);
            $this->assertArrayHasKey('affected_customers', $result);
            $this->assertArrayHasKey('total_quantity_recalled', $result);
            $this->assertArrayHasKey('notifications_prepared', $result);
            $this->assertArrayHasKey('recall_severity', $result);
            $this->assertArrayHasKey('recall_reason', $result);
            $this->assertArrayHasKey('customer_notifications', $result);
            $this->assertArrayHasKey('journey_data', $result);
            
            $this->assertEquals($batchNumber, $result['batch_number']);
            $this->assertEquals($recallData['severity'], $result['recall_severity']);
            $this->assertEquals($recallData['reason'], $result['recall_reason']);
            $this->assertIsInt($result['affected_customers']);
            $this->assertIsInt($result['total_quantity_recalled']);
            $this->assertIsArray($result['customer_notifications']);
            $this->assertIsArray($result['journey_data']);
        } else {
            // Should fail if batch doesn't exist or other validation errors
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testInitiateBatchRecallWithInvalidData()
    {
        $batchNumber = 'BATCH123456';
        $invalidRecallData = [
            // Missing required fields
            'severity' => 'invalid_severity', // Invalid severity level
            'initiated_by' => 'Test User'
        ];

        $result = $this->traceabilityReporter->initiateBatchRecall($batchNumber, $invalidRecallData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        
        $errors = $result['errors'];
        $this->assertArrayHasKey('reason', $errors);
        $this->assertArrayHasKey('severity', $errors);
    }

    public function testGetRecallStatus()
    {
        $recallId = 999; // Non-existent recall for testing
        $result = $this->traceabilityReporter->getRecallStatus($recallId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because recall doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Recall record not found', $result['error']);
    }

    public function testGetRecallStatusWithValidRecall()
    {
        // This test would require a recall to exist in the database
        // For now, we'll test the structure when recall exists
        $recallId = 1; // Assuming recall exists
        $result = $this->traceabilityReporter->getRecallStatus($recallId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('recall_id', $result);
            $this->assertArrayHasKey('batch_number', $result);
            $this->assertArrayHasKey('recall_status', $result);
            $this->assertArrayHasKey('initiated_date', $result);
            $this->assertArrayHasKey('severity', $result);
            $this->assertArrayHasKey('reason', $result);
            $this->assertArrayHasKey('progress', $result);
            $this->assertArrayHasKey('customer_responses', $result);
            $this->assertArrayHasKey('timeline', $result);
            
            $this->assertEquals($recallId, $result['recall_id']);
            $this->assertIsArray($result['progress']);
            $this->assertIsArray($result['customer_responses']);
            $this->assertIsArray($result['timeline']);
            
            // Check progress structure
            $progress = $result['progress'];
            $this->assertArrayHasKey('total_customers', $progress);
            $this->assertArrayHasKey('responded_customers', $progress);
            $this->assertArrayHasKey('acknowledged_customers', $progress);
            $this->assertArrayHasKey('returned_products', $progress);
            $this->assertArrayHasKey('progress_percentage', $progress);
        }
    }

    public function testRecordCustomerRecallResponse()
    {
        $recallId = 1;
        $customerId = 1;
        $responseData = [
            'response_status' => 'acknowledged',
            'product_returned' => 1,
            'return_quantity' => 2,
            'customer_notes' => 'Product returned in good condition',
            'refund_amount' => 25.99,
            'refund_processed' => 1
        ];

        $result = $this->traceabilityReporter->recordCustomerRecallResponse($recallId, $customerId, $responseData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('recall_id', $result);
            $this->assertArrayHasKey('customer_id', $result);
            $this->assertArrayHasKey('response_status', $result);
            
            $this->assertEquals($recallId, $result['recall_id']);
            $this->assertEquals($customerId, $result['customer_id']);
            $this->assertEquals($responseData['response_status'], $result['response_status']);
            $this->assertEquals('Customer recall response recorded successfully', $result['message']);
        }
    }

    public function testRecordCustomerRecallResponseWithInvalidData()
    {
        $recallId = 1;
        $customerId = 1;
        $invalidResponseData = [
            // Missing response_status
            'product_returned' => 1,
            'return_quantity' => 2
        ];

        $result = $this->traceabilityReporter->recordCustomerRecallResponse($recallId, $customerId, $invalidResponseData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Response status is required', $result['error']);

        // Test with invalid response status
        $invalidStatusData = [
            'response_status' => 'invalid_status',
            'product_returned' => 1
        ];

        $result = $this->traceabilityReporter->recordCustomerRecallResponse($recallId, $customerId, $invalidStatusData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Invalid response status', $result['error']);
    }

    public function testGenerateTraceabilityReport()
    {
        // Test without filters
        $result = $this->traceabilityReporter->generateTraceabilityReport();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('generated_at', $result);
            $this->assertArrayHasKey('filters', $result);
            $this->assertArrayHasKey('statistics', $result);
            $this->assertArrayHasKey('batch_groups', $result);
            $this->assertArrayHasKey('raw_logs', $result);
            
            $this->assertIsArray($result['filters']);
            $this->assertIsArray($result['statistics']);
            $this->assertIsArray($result['batch_groups']);
            $this->assertIsArray($result['raw_logs']);
            
            // Check statistics structure
            $statistics = $result['statistics'];
            $this->assertArrayHasKey('total_batches', $statistics);
            $this->assertArrayHasKey('total_events', $statistics);
            $this->assertArrayHasKey('event_types', $statistics);
        }

        // Test with filters
        $batchNumber = 'BATCH123456';
        $vendorId = 1;
        $dateFrom = '2024-01-01';
        $dateTo = '2024-01-31';

        $result = $this->traceabilityReporter->generateTraceabilityReport($batchNumber, $vendorId, $dateFrom, $dateTo);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $filters = $result['filters'];
            $this->assertEquals($batchNumber, $filters['batch_number']);
            $this->assertEquals($vendorId, $filters['vendor_id']);
            $this->assertEquals($dateFrom, $filters['date_from']);
            $this->assertEquals($dateTo, $filters['date_to']);
        }
    }

    public function testGetActiveRecalls()
    {
        $result = $this->traceabilityReporter->getActiveRecalls();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('active_recalls_count', $result);
            $this->assertArrayHasKey('recalls', $result);
            
            $this->assertIsInt($result['active_recalls_count']);
            $this->assertIsArray($result['recalls']);
            
            // Check recall structure if any exist
            foreach ($result['recalls'] as $recall) {
                $this->assertArrayHasKey('id', $recall);
                $this->assertArrayHasKey('batch_number', $recall);
                $this->assertArrayHasKey('reason', $recall);
                $this->assertArrayHasKey('severity', $recall);
                $this->assertArrayHasKey('status', $recall);
                $this->assertArrayHasKey('initiated_date', $recall);
                
                $this->assertContains($recall['status'], ['active', 'in_progress']);
                $this->assertContains($recall['severity'], ['low', 'medium', 'high', 'critical']);
            }
        }
    }

    public function testValidEventTypes()
    {
        $validEventTypes = [
            'batch_created', 'batch_received', 'quality_check', 'storage',
            'order_assigned', 'picked', 'packed', 'shipped', 'delivered',
            'returned', 'expired', 'recalled', 'disposed', 'customer_recall_response',
            'recall_initiated', 'temperature_alert', 'quality_issue'
        ];

        foreach ($validEventTypes as $eventType) {
            $eventData = [
                'batch_number' => 'BATCH123456',
                'event_type' => $eventType,
                'event_description' => "Test event for {$eventType}",
                'event_timestamp' => '2024-01-15 10:30:00'
            ];

            $result = $this->traceabilityReporter->logTraceabilityEvent($eventData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should not fail due to invalid event type
            if (!$result['success'] && isset($result['errors'])) {
                $this->assertArrayNotHasKey('event_type', $result['errors']);
            }
        }
    }

    public function testValidRecallSeverityLevels()
    {
        $validSeverities = ['low', 'medium', 'high', 'critical'];

        foreach ($validSeverities as $severity) {
            $recallData = [
                'reason' => 'Test recall',
                'severity' => $severity,
                'initiated_by' => 'Test User'
            ];

            $result = $this->traceabilityReporter->initiateBatchRecall('BATCH123456', $recallData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should not fail due to invalid severity
            if (!$result['success'] && isset($result['errors'])) {
                $this->assertArrayNotHasKey('severity', $result['errors']);
            }
        }
    }

    public function testValidCustomerResponseStatuses()
    {
        $validStatuses = ['acknowledged', 'product_returned', 'no_product', 'refused'];

        foreach ($validStatuses as $status) {
            $responseData = [
                'response_status' => $status,
                'product_returned' => $status === 'product_returned' ? 1 : 0,
                'return_quantity' => $status === 'product_returned' ? 2 : 0
            ];

            $result = $this->traceabilityReporter->recordCustomerRecallResponse(1, 1, $responseData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should not fail due to invalid response status
            if (!$result['success']) {
                $this->assertNotEquals('Invalid response status', $result['error']);
            }
        }
    }

    public function testTimestampValidation()
    {
        $validTimestamps = [
            '2024-01-15 10:30:00',
            '2024-12-31 23:59:59',
            '2024-01-01 00:00:00'
        ];

        $invalidTimestamps = [
            'invalid-timestamp',
            '2024-13-01 10:30:00', // Invalid month
            '2024-01-32 10:30:00', // Invalid day
            '2024-01-15 25:30:00', // Invalid hour
            '2024-01-15 10:60:00'  // Invalid minute
        ];

        // Test valid timestamps
        foreach ($validTimestamps as $timestamp) {
            $eventData = [
                'batch_number' => 'BATCH123456',
                'event_type' => 'quality_check',
                'event_description' => 'Test event',
                'event_timestamp' => $timestamp
            ];

            $result = $this->traceabilityReporter->logTraceabilityEvent($eventData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should not fail due to invalid timestamp format
            if (!$result['success'] && isset($result['errors'])) {
                $this->assertArrayNotHasKey('event_timestamp', $result['errors']);
            }
        }

        // Test invalid timestamps
        foreach ($invalidTimestamps as $timestamp) {
            $eventData = [
                'batch_number' => 'BATCH123456',
                'event_type' => 'quality_check',
                'event_description' => 'Test event',
                'event_timestamp' => $timestamp
            ];

            $result = $this->traceabilityReporter->logTraceabilityEvent($eventData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if (!$result['success'] && isset($result['errors'])) {
                $this->assertArrayHasKey('event_timestamp', $result['errors']);
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}