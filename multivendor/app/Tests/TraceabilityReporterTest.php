<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\TraceabilityReporter;
use PHPUnit\Framework\TestCase;

class TraceabilityReporterTest extends TestCase
{
    private TraceabilityReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new TraceabilityReporter();
    }

    public function testLogTraceabilityEventSuccess()
    {
        $eventData = [
            'batch_number' => 'BATCH001',
            'event_type' => 'batch_created',
            'event_description' => 'Batch created with 100 units',
            'location' => 'Warehouse A',
            'temperature' => 4.5,
            'humidity' => 65.0,
            'handler_name' => 'John Doe'
        ];

        $result = $this->reporter->logTraceabilityEvent($eventData);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('log_id', $result);
        $this->assertEquals('batch_created', $result['event_type']);
        $this->assertEquals('BATCH001', $result['batch_number']);
    }

    public function testLogTraceabilityEventWithMissingData()
    {
        $eventData = [
            'batch_number' => 'BATCH001',
            // Missing required fields
        ];

        $result = $this->reporter->logTraceabilityEvent($eventData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testLogTraceabilityEventWithInvalidEventType()
    {
        $eventData = [
            'batch_number' => 'BATCH001',
            'event_type' => 'invalid_event',
            'event_description' => 'Test event'
        ];

        $result = $this->reporter->logTraceabilityEvent($eventData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('event_type', $result['errors']);
    }

    public function testTrackProductJourneyWithValidBatch()
    {
        // First create a traceability event
        $eventData = [
            'batch_number' => 'BATCH002',
            'event_type' => 'batch_created',
            'event_description' => 'Batch created for tracking test'
        ];
        $this->reporter->logTraceabilityEvent($eventData);

        $result = $this->reporter->trackProductJourney('BATCH002');
        $this->assertTrue($result['success']);
        $this->assertEquals('BATCH002', $result['batch_number']);
        $this->assertArrayHasKey('journey_timeline', $result);
        $this->assertArrayHasKey('traceability_logs', $result);
    }

    public function testTrackProductJourneyWithInvalidBatch()
    {
        $result = $this->reporter->trackProductJourney('NONEXISTENT_BATCH');
        $this->assertFalse($result['success']);
        $this->assertEquals('Batch not found', $result['error']);
    }

    public function testInitiateBatchRecallSuccess()
    {
        // First create a batch and some traceability events
        $eventData = [
            'batch_number' => 'BATCH003',
            'event_type' => 'batch_created',
            'event_description' => 'Batch created for recall test'
        ];
        $this->reporter->logTraceabilityEvent($eventData);

        $recallData = [
            'reason' => 'Quality issue detected',
            'severity' => 'high',
            'initiated_by' => 'Quality Manager',
            'description' => 'Contamination detected in batch'
        ];

        $result = $this->reporter->initiateBatchRecall('BATCH003', $recallData);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('recall_id', $result);
        $this->assertEquals('BATCH003', $result['batch_number']);
        $this->assertEquals('high', $result['recall_severity']);
        $this->assertEquals('Quality issue detected', $result['recall_reason']);
    }

    public function testInitiateBatchRecallWithMissingData()
    {
        $recallData = [
            'reason' => 'Quality issue',
            // Missing severity
        ];

        $result = $this->reporter->initiateBatchRecall('BATCH003', $recallData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testInitiateBatchRecallWithInvalidSeverity()
    {
        $recallData = [
            'reason' => 'Quality issue',
            'severity' => 'invalid_severity'
        ];

        $result = $this->reporter->initiateBatchRecall('BATCH003', $recallData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('severity', $result['errors']);
    }

    public function testRecordCustomerRecallResponseSuccess()
    {
        // First initiate a recall
        $eventData = [
            'batch_number' => 'BATCH004',
            'event_type' => 'batch_created',
            'event_description' => 'Batch created for customer response test'
        ];
        $this->reporter->logTraceabilityEvent($eventData);

        $recallData = [
            'reason' => 'Quality issue',
            'severity' => 'medium',
            'initiated_by' => 'Test Manager'
        ];

        $recallResult = $this->reporter->initiateBatchRecall('BATCH004', $recallData);
        $this->assertTrue($recallResult['success']);

        $responseData = [
            'response_status' => 'acknowledged',
            'product_returned' => 1,
            'return_quantity' => 2,
            'customer_notes' => 'Product returned as requested'
        ];

        $result = $this->reporter->recordCustomerRecallResponse(
            $recallResult['recall_id'], 
            1, 
            $responseData
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($recallResult['recall_id'], $result['recall_id']);
        $this->assertEquals(1, $result['customer_id']);
        $this->assertEquals('acknowledged', $result['response_status']);
    }

    public function testRecordCustomerRecallResponseWithInvalidStatus()
    {
        $responseData = [
            'response_status' => 'invalid_status'
        ];

        $result = $this->reporter->recordCustomerRecallResponse(1, 1, $responseData);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid response status', $result['error']);
    }

    public function testRecordCustomerRecallResponseWithMissingStatus()
    {
        $responseData = [
            'product_returned' => 1
        ];

        $result = $this->reporter->recordCustomerRecallResponse(1, 1, $responseData);
        $this->assertFalse($result['success']);
        $this->assertEquals('Response status is required', $result['error']);
    }

    public function testGetRecallStatusWithValidRecall()
    {
        // First initiate a recall
        $eventData = [
            'batch_number' => 'BATCH005',
            'event_type' => 'batch_created',
            'event_description' => 'Batch created for status test'
        ];
        $this->reporter->logTraceabilityEvent($eventData);

        $recallData = [
            'reason' => 'Quality issue',
            'severity' => 'low',
            'initiated_by' => 'Test Manager'
        ];

        $recallResult = $this->reporter->initiateBatchRecall('BATCH005', $recallData);
        $this->assertTrue($recallResult['success']);

        $result = $this->reporter->getRecallStatus($recallResult['recall_id']);
        $this->assertTrue($result['success']);
        $this->assertEquals($recallResult['recall_id'], $result['recall_id']);
        $this->assertEquals('BATCH005', $result['batch_number']);
        $this->assertArrayHasKey('progress', $result);
        $this->assertArrayHasKey('customer_responses', $result);
    }

    public function testGetRecallStatusWithInvalidRecall()
    {
        $result = $this->reporter->getRecallStatus(99999);
        $this->assertFalse($result['success']);
        $this->assertEquals('Recall record not found', $result['error']);
    }

    public function testGenerateTraceabilityReportWithoutFilters()
    {
        $result = $this->reporter->generateTraceabilityReport();
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('batch_groups', $result);
        $this->assertArrayHasKey('raw_logs', $result);
        $this->assertArrayHasKey('total_batches', $result['statistics']);
        $this->assertArrayHasKey('total_events', $result['statistics']);
    }

    public function testGenerateTraceabilityReportWithBatchFilter()
    {
        // Create some events first
        $eventData = [
            'batch_number' => 'BATCH006',
            'event_type' => 'batch_created',
            'event_description' => 'Batch created for report test'
        ];
        $this->reporter->logTraceabilityEvent($eventData);

        $result = $this->reporter->generateTraceabilityReport('BATCH006');
        $this->assertTrue($result['success']);
        $this->assertEquals('BATCH006', $result['filters']['batch_number']);
        $this->assertArrayHasKey('batch_groups', $result);
    }

    public function testGenerateTraceabilityReportWithDateFilters()
    {
        $dateFrom = '2024-01-01';
        $dateTo = '2024-12-31';

        $result = $this->reporter->generateTraceabilityReport(null, null, $dateFrom, $dateTo);
        $this->assertTrue($result['success']);
        $this->assertEquals($dateFrom, $result['filters']['date_from']);
        $this->assertEquals($dateTo, $result['filters']['date_to']);
    }

    public function testGetActiveRecalls()
    {
        $result = $this->reporter->getActiveRecalls();
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('active_recalls_count', $result);
        $this->assertArrayHasKey('recalls', $result);
        $this->assertIsInt($result['active_recalls_count']);
        $this->assertIsArray($result['recalls']);
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
                'batch_number' => 'TEST_BATCH',
                'event_type' => $eventType,
                'event_description' => "Test event for {$eventType}"
            ];

            $result = $this->reporter->logTraceabilityEvent($eventData);
            $this->assertTrue($result['success'], "Event type {$eventType} should be valid");
        }
    }

    public function testValidSeverityLevels()
    {
        $validSeverities = ['low', 'medium', 'high', 'critical'];

        foreach ($validSeverities as $severity) {
            $eventData = [
                'batch_number' => 'TEST_BATCH_' . strtoupper($severity),
                'event_type' => 'batch_created',
                'event_description' => "Test batch for {$severity} severity"
            ];
            $this->reporter->logTraceabilityEvent($eventData);

            $recallData = [
                'reason' => "Test recall with {$severity} severity",
                'severity' => $severity,
                'initiated_by' => 'Test Manager'
            ];

            $result = $this->reporter->initiateBatchRecall('TEST_BATCH_' . strtoupper($severity), $recallData);
            $this->assertTrue($result['success'], "Severity {$severity} should be valid");
            $this->assertEquals($severity, $result['recall_severity']);
        }
    }

    public function testValidCustomerResponseStatuses()
    {
        $validStatuses = ['acknowledged', 'product_returned', 'no_product', 'refused'];

        // First create a recall
        $eventData = [
            'batch_number' => 'BATCH_RESPONSE_TEST',
            'event_type' => 'batch_created',
            'event_description' => 'Batch for response status test'
        ];
        $this->reporter->logTraceabilityEvent($eventData);

        $recallData = [
            'reason' => 'Test recall for response statuses',
            'severity' => 'medium',
            'initiated_by' => 'Test Manager'
        ];

        $recallResult = $this->reporter->initiateBatchRecall('BATCH_RESPONSE_TEST', $recallData);
        $this->assertTrue($recallResult['success']);

        foreach ($validStatuses as $status) {
            $responseData = [
                'response_status' => $status,
                'customer_notes' => "Test response with {$status} status"
            ];

            $result = $this->reporter->recordCustomerRecallResponse(
                $recallResult['recall_id'], 
                rand(1, 1000), // Different customer ID for each test
                $responseData
            );

            $this->assertTrue($result['success'], "Response status {$status} should be valid");
            $this->assertEquals($status, $result['response_status']);
        }
    }

    public function testEventDataWithAdditionalFields()
    {
        $eventData = [
            'batch_number' => 'BATCH_ADDITIONAL',
            'event_type' => 'temperature_alert',
            'event_description' => 'Temperature exceeded threshold',
            'location' => 'Cold Storage Unit 3',
            'temperature' => 8.5,
            'humidity' => 75.0,
            'handler_id' => 123,
            'handler_name' => 'Storage Manager',
            'event_timestamp' => '2024-01-15 14:30:00',
            'additional_data' => [
                'alert_level' => 'warning',
                'threshold_exceeded' => 2.5,
                'duration_minutes' => 15
            ]
        ];

        $result = $this->reporter->logTraceabilityEvent($eventData);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('log_id', $result);
    }

    public function testInvalidTimestampFormat()
    {
        $eventData = [
            'batch_number' => 'BATCH_TIMESTAMP',
            'event_type' => 'batch_created',
            'event_description' => 'Test with invalid timestamp',
            'event_timestamp' => 'invalid-timestamp'
        ];

        $result = $this->reporter->logTraceabilityEvent($eventData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('event_timestamp', $result['errors']);
    }
}