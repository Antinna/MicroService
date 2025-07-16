<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Traceability reporter for complete product journey tracking and incident management
 */
class TraceabilityReporter
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->createTraceabilityTables();
    }

    /**
     * Track product journey from batch to customer
     */
    public function trackProductJourney(string $batchNumber): array
    {
        try {
            // Get batch information
            $batch = $this->getBatchInfo($batchNumber);
            if (!$batch) {
                return [
                    'success' => false,
                    'error' => 'Batch not found'
                ];
            }

            // Get product information
            $product = $this->getProductInfo($batch['product_id']);
            
            // Get vendor information
            $vendor = $this->getVendorInfo($product['vendor_id']);
            
            // Get all traceability logs for this batch
            $traceabilityLogs = $this->getTraceabilityLogs($batchNumber);
            
            // Get orders containing this batch
            $orders = $this->getOrdersForBatch($batchNumber);
            
            // Get delivery information
            $deliveries = $this->getDeliveriesForBatch($batchNumber);
            
            // Calculate journey timeline
            $timeline = $this->buildJourneyTimeline($batch, $traceabilityLogs, $orders, $deliveries);

            return [
                'success' => true,
                'batch_number' => $batchNumber,
                'batch_info' => $batch,
                'product_info' => $product,
                'vendor_info' => $vendor,
                'journey_timeline' => $timeline,
                'traceability_logs' => $traceabilityLogs,
                'orders' => $orders,
                'deliveries' => $deliveries,
                'total_quantity_distributed' => $this->calculateDistributedQuantity($orders),
                'customers_affected' => $this->getAffectedCustomers($orders)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to track product journey: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log traceability event
     */
    public function logTraceabilityEvent(array $eventData): array
    {
        try {
            // Validate event data
            $validation = $this->validateEventData($eventData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Create traceability log entry
            $logData = [
                'batch_number' => $eventData['batch_number'],
                'event_type' => $eventData['event_type'],
                'event_description' => $eventData['event_description'],
                'location' => $eventData['location'] ?? null,
                'temperature' => $eventData['temperature'] ?? null,
                'humidity' => $eventData['humidity'] ?? null,
                'handler_id' => $eventData['handler_id'] ?? null,
                'handler_name' => $eventData['handler_name'] ?? null,
                'additional_data' => isset($eventData['additional_data']) ? json_encode($eventData['additional_data']) : null,
                'event_timestamp' => $eventData['event_timestamp'] ?? date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ];

            $logId = $this->createTraceabilityLog($logData);

            if ($logId) {
                return [
                    'success' => true,
                    'message' => 'Traceability event logged successfully',
                    'log_id' => $logId,
                    'event_type' => $eventData['event_type'],
                    'batch_number' => $eventData['batch_number']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create traceability log'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to log traceability event: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Initiate batch recall
     */
    public function initiateBatchRecall(string $batchNumber, array $recallData): array
    {
        try {
            // Validate recall data
            $validation = $this->validateRecallData($recallData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Get complete product journey
            $journey = $this->trackProductJourney($batchNumber);
            if (!$journey['success']) {
                return [
                    'success' => false,
                    'error' => 'Cannot initiate recall: ' . $journey['error']
                ];
            }

            // Create recall record
            $recallId = $this->createRecallRecord($batchNumber, $recallData, $journey);
            
            if (!$recallId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create recall record'
                ];
            }

            // Log recall initiation
            $this->logTraceabilityEvent([
                'batch_number' => $batchNumber,
                'event_type' => 'recall_initiated',
                'event_description' => 'Batch recall initiated: ' . $recallData['reason'],
                'handler_name' => $recallData['initiated_by'] ?? 'System',
                'additional_data' => [
                    'recall_id' => $recallId,
                    'severity' => $recallData['severity'],
                    'reason' => $recallData['reason']
                ]
            ]);

            // Get affected customers
            $affectedCustomers = $journey['customers_affected'];
            
            // Prepare customer notifications
            $notifications = $this->prepareRecallNotifications($batchNumber, $recallData, $affectedCustomers, $journey);

            // Update batch status
            $this->updateBatchStatus($batchNumber, 'recalled');

            // Remove from active inventory
            $this->removeFromActiveInventory($batchNumber);

            return [
                'success' => true,
                'recall_id' => $recallId,
                'batch_number' => $batchNumber,
                'affected_customers' => count($affectedCustomers),
                'total_quantity_recalled' => $journey['total_quantity_distributed'],
                'notifications_prepared' => count($notifications),
                'recall_severity' => $recallData['severity'],
                'recall_reason' => $recallData['reason'],
                'customer_notifications' => $notifications,
                'journey_data' => $journey
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to initiate batch recall: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get recall status and progress
     */
    public function getRecallStatus(int $recallId): array
    {
        try {
            // Get recall record
            $recall = $this->getRecallRecord($recallId);
            if (!$recall) {
                return [
                    'success' => false,
                    'error' => 'Recall record not found'
                ];
            }

            // Get customer response status
            $customerResponses = $this->getCustomerRecallResponses($recallId);
            
            // Calculate progress metrics
            $totalCustomers = count($customerResponses);
            $respondedCustomers = array_filter($customerResponses, fn($r) => $r['response_status'] !== 'pending');
            $acknowledgedCustomers = array_filter($customerResponses, fn($r) => $r['response_status'] === 'acknowledged');
            $returnedProducts = array_filter($customerResponses, fn($r) => $r['product_returned'] === 1);

            $progressPercentage = $totalCustomers > 0 ? (count($respondedCustomers) / $totalCustomers) * 100 : 100;

            return [
                'success' => true,
                'recall_id' => $recallId,
                'batch_number' => $recall['batch_number'],
                'recall_status' => $recall['status'],
                'initiated_date' => $recall['initiated_date'],
                'severity' => $recall['severity'],
                'reason' => $recall['reason'],
                'progress' => [
                    'total_customers' => $totalCustomers,
                    'responded_customers' => count($respondedCustomers),
                    'acknowledged_customers' => count($acknowledgedCustomers),
                    'returned_products' => count($returnedProducts),
                    'progress_percentage' => round($progressPercentage, 2)
                ],
                'customer_responses' => $customerResponses,
                'timeline' => $this->getRecallTimeline($recallId)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get recall status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Record customer response to recall
     */
    public function recordCustomerRecallResponse(int $recallId, int $customerId, array $responseData): array
    {
        try {
            // Validate response data
            if (empty($responseData['response_status'])) {
                return [
                    'success' => false,
                    'error' => 'Response status is required'
                ];
            }

            $validStatuses = ['acknowledged', 'product_returned', 'no_product', 'refused'];
            if (!in_array($responseData['response_status'], $validStatuses)) {
                return [
                    'success' => false,
                    'error' => 'Invalid response status'
                ];
            }

            // Update customer recall response
            $updateData = [
                'response_status' => $responseData['response_status'],
                'response_date' => date('Y-m-d H:i:s'),
                'product_returned' => $responseData['product_returned'] ?? 0,
                'return_quantity' => $responseData['return_quantity'] ?? 0,
                'customer_notes' => $responseData['customer_notes'] ?? null,
                'refund_amount' => $responseData['refund_amount'] ?? 0,
                'refund_processed' => $responseData['refund_processed'] ?? 0
            ];

            $success = $this->updateCustomerRecallResponse($recallId, $customerId, $updateData);

            if ($success) {
                // Log the response
                $recall = $this->getRecallRecord($recallId);
                $this->logTraceabilityEvent([
                    'batch_number' => $recall['batch_number'],
                    'event_type' => 'customer_recall_response',
                    'event_description' => "Customer responded to recall: {$responseData['response_status']}",
                    'additional_data' => [
                        'recall_id' => $recallId,
                        'customer_id' => $customerId,
                        'response_status' => $responseData['response_status'],
                        'product_returned' => $responseData['product_returned'] ?? 0,
                        'return_quantity' => $responseData['return_quantity'] ?? 0
                    ]
                ]);

                return [
                    'success' => true,
                    'message' => 'Customer recall response recorded successfully',
                    'recall_id' => $recallId,
                    'customer_id' => $customerId,
                    'response_status' => $responseData['response_status']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to record customer response'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to record customer recall response: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate traceability report
     */
    public function generateTraceabilityReport(?string $batchNumber = null, ?int $vendorId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        try {
            $conditions = [];
            $params = [];

            // Build query conditions
            if ($batchNumber) {
                $conditions[] = "tl.batch_number = ?";
                $params[] = $batchNumber;
            }

            if ($vendorId) {
                $conditions[] = "p.vendor_id = ?";
                $params[] = $vendorId;
            }

            if ($dateFrom) {
                $conditions[] = "tl.event_timestamp >= ?";
                $params[] = $dateFrom;
            }

            if ($dateTo) {
                $conditions[] = "tl.event_timestamp <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }

            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $sql = "SELECT 
                        tl.*,
                        ib.product_id,
                        p.name as product_name,
                        p.vendor_id,
                        v.business_name as vendor_name
                    FROM traceability_logs tl
                    LEFT JOIN inventory_batches ib ON tl.batch_number = ib.batch_number
                    LEFT JOIN products p ON ib.product_id = p.id
                    LEFT JOIN vendors v ON p.vendor_id = v.id
                    {$whereClause}
                    ORDER BY tl.event_timestamp DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

            // Group by batch number
            $batchGroups = [];
            foreach ($logs as $log) {
                $batchNum = $log['batch_number'];
                if (!isset($batchGroups[$batchNum])) {
                    $batchGroups[$batchNum] = [
                        'batch_number' => $batchNum,
                        'product_name' => $log['product_name'],
                        'vendor_name' => $log['vendor_name'],
                        'events' => []
                    ];
                }
                $batchGroups[$batchNum]['events'][] = $log;
            }

            // Calculate statistics
            $totalBatches = count($batchGroups);
            $totalEvents = count($logs);
            $eventTypes = array_count_values(array_column($logs, 'event_type'));

            return [
                'success' => true,
                'generated_at' => date('Y-m-d H:i:s'),
                'filters' => [
                    'batch_number' => $batchNumber,
                    'vendor_id' => $vendorId,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'statistics' => [
                    'total_batches' => $totalBatches,
                    'total_events' => $totalEvents,
                    'event_types' => $eventTypes
                ],
                'batch_groups' => array_values($batchGroups),
                'raw_logs' => $logs
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate traceability report: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get active recalls
     */
    public function getActiveRecalls(): array
    {
        try {
            $sql = "SELECT 
                        br.*,
                        ib.product_id,
                        p.name as product_name,
                        v.business_name as vendor_name,
                        COUNT(crs.customer_id) as total_customers,
                        SUM(CASE WHEN crs.response_status != 'pending' THEN 1 ELSE 0 END) as responded_customers
                    FROM batch_recalls br
                    LEFT JOIN inventory_batches ib ON br.batch_number = ib.batch_number
                    LEFT JOIN products p ON ib.product_id = p.id
                    LEFT JOIN vendors v ON p.vendor_id = v.id
                    LEFT JOIN customer_recall_responses crs ON br.id = crs.recall_id
                    WHERE br.status IN ('active', 'in_progress')
                    GROUP BY br.id
                    ORDER BY br.initiated_date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $recalls = $stmt->fetchAll();

            return [
                'success' => true,
                'active_recalls_count' => count($recalls),
                'recalls' => $recalls
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get active recalls: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate event data
     */
    private function validateEventData(array $data): array
    {
        $errors = [];

        // Required fields
        if (empty($data['batch_number'])) {
            $errors['batch_number'] = 'Batch number is required';
        }

        if (empty($data['event_type'])) {
            $errors['event_type'] = 'Event type is required';
        }

        if (empty($data['event_description'])) {
            $errors['event_description'] = 'Event description is required';
        }

        // Valid event types
        if (!empty($data['event_type'])) {
            $validTypes = [
                'batch_created', 'batch_received', 'quality_check', 'storage',
                'order_assigned', 'picked', 'packed', 'shipped', 'delivered',
                'returned', 'expired', 'recalled', 'disposed', 'customer_recall_response',
                'recall_initiated', 'temperature_alert', 'quality_issue'
            ];

            if (!in_array($data['event_type'], $validTypes)) {
                $errors['event_type'] = 'Invalid event type';
            }
        }

        // Validate timestamp if provided
        if (!empty($data['event_timestamp'])) {
            if (!$this->isValidDateTime($data['event_timestamp'])) {
                $errors['event_timestamp'] = 'Invalid timestamp format (YYYY-MM-DD HH:MM:SS)';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate recall data
     */
    private function validateRecallData(array $data): array
    {
        $errors = [];

        // Required fields
        if (empty($data['reason'])) {
            $errors['reason'] = 'Recall reason is required';
        }

        if (empty($data['severity'])) {
            $errors['severity'] = 'Recall severity is required';
        }

        // Valid severity levels
        if (!empty($data['severity'])) {
            $validSeverities = ['low', 'medium', 'high', 'critical'];
            if (!in_array($data['severity'], $validSeverities)) {
                $errors['severity'] = 'Invalid severity level';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get batch information
     */
    private function getBatchInfo(string $batchNumber): ?array
    {
        try {
            $sql = "SELECT * FROM inventory_batches WHERE batch_number = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$batchNumber]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get product information
     */
    private function getProductInfo(int $productId): ?array
    {
        try {
            $sql = "SELECT * FROM products WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get vendor information
     */
    private function getVendorInfo(int $vendorId): ?array
    {
        try {
            $sql = "SELECT * FROM vendors WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get traceability logs for batch
     */
    private function getTraceabilityLogs(string $batchNumber): array
    {
        try {
            $sql = "SELECT * FROM traceability_logs 
                    WHERE batch_number = ? 
                    ORDER BY event_timestamp ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$batchNumber]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get orders for batch
     */
    private function getOrdersForBatch(string $batchNumber): array
    {
        try {
            // This would integrate with order management system
            // For now, return mock data
            return [
                [
                    'order_id' => 1,
                    'customer_id' => 1,
                    'customer_name' => 'John Doe',
                    'quantity' => 2,
                    'order_date' => '2024-01-15',
                    'delivery_date' => '2024-01-16'
                ]
            ];

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get deliveries for batch
     */
    private function getDeliveriesForBatch(string $batchNumber): array
    {
        try {
            // This would integrate with delivery system
            // For now, return mock data
            return [
                [
                    'delivery_id' => 1,
                    'order_id' => 1,
                    'delivery_status' => 'delivered',
                    'delivered_at' => '2024-01-16 10:30:00',
                    'delivery_person' => 'Delivery Staff'
                ]
            ];

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Build journey timeline
     */
    private function buildJourneyTimeline(array $batch, array $logs, array $orders, array $deliveries): array
    {
        $timeline = [];

        // Add batch creation
        $timeline[] = [
            'timestamp' => $batch['created_at'],
            'event' => 'Batch Created',
            'description' => "Batch {$batch['batch_number']} created with {$batch['quantity']} units",
            'type' => 'batch_event'
        ];

        // Add traceability logs
        foreach ($logs as $log) {
            $timeline[] = [
                'timestamp' => $log['event_timestamp'],
                'event' => ucwords(str_replace('_', ' ', $log['event_type'])),
                'description' => $log['event_description'],
                'type' => 'traceability_event',
                'location' => $log['location'],
                'handler' => $log['handler_name']
            ];
        }

        // Add order events
        foreach ($orders as $order) {
            $timeline[] = [
                'timestamp' => $order['order_date'] . ' 00:00:00',
                'event' => 'Order Placed',
                'description' => "Order #{$order['order_id']} placed by {$order['customer_name']}",
                'type' => 'order_event'
            ];
        }

        // Add delivery events
        foreach ($deliveries as $delivery) {
            $timeline[] = [
                'timestamp' => $delivery['delivered_at'],
                'event' => 'Product Delivered',
                'description' => "Delivered to customer for order #{$delivery['order_id']}",
                'type' => 'delivery_event'
            ];
        }

        // Sort by timestamp
        usort($timeline, fn($a, $b) => strtotime($a['timestamp']) - strtotime($b['timestamp']));

        return $timeline;
    }

    /**
     * Calculate distributed quantity
     */
    private function calculateDistributedQuantity(array $orders): int
    {
        return array_sum(array_column($orders, 'quantity'));
    }

    /**
     * Get affected customers
     */
    private function getAffectedCustomers(array $orders): array
    {
        $customers = [];
        foreach ($orders as $order) {
            $customers[$order['customer_id']] = [
                'customer_id' => $order['customer_id'],
                'customer_name' => $order['customer_name'],
                'quantity_purchased' => $order['quantity'],
                'order_date' => $order['order_date'],
                'delivery_date' => $order['delivery_date'] ?? null
            ];
        }
        return array_values($customers);
    }

    /**
     * Create traceability log
     */
    private function createTraceabilityLog(array $logData): ?int
    {
        try {
            $sql = "INSERT INTO traceability_logs (" . implode(', ', array_keys($logData)) . ") 
                    VALUES (" . str_repeat('?,', count($logData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($logData));

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create recall record
     */
    private function createRecallRecord(string $batchNumber, array $recallData, array $journey): ?int
    {
        try {
            $recordData = [
                'batch_number' => $batchNumber,
                'reason' => $recallData['reason'],
                'severity' => $recallData['severity'],
                'initiated_by' => $recallData['initiated_by'] ?? 'System',
                'initiated_date' => date('Y-m-d H:i:s'),
                'status' => 'active',
                'affected_customers' => count($journey['customers_affected']),
                'total_quantity' => $journey['total_quantity_distributed'],
                'description' => $recallData['description'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $sql = "INSERT INTO batch_recalls (" . implode(', ', array_keys($recordData)) . ") 
                    VALUES (" . str_repeat('?,', count($recordData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($recordData));

            if ($success) {
                $recallId = $this->db->lastInsertId();
                
                // Create customer recall response records
                foreach ($journey['customers_affected'] as $customer) {
                    $this->createCustomerRecallResponse($recallId, $customer);
                }

                return $recallId;
            }

            return null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create customer recall response record
     */
    private function createCustomerRecallResponse(int $recallId, array $customer): void
    {
        try {
            $sql = "INSERT INTO customer_recall_responses 
                    (recall_id, customer_id, customer_name, quantity_purchased, response_status, created_at) 
                    VALUES (?, ?, ?, ?, 'pending', NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $recallId,
                $customer['customer_id'],
                $customer['customer_name'],
                $customer['quantity_purchased']
            ]);

        } catch (Exception $e) {
            // Log error but don't fail the recall process
        }
    }

    /**
     * Prepare recall notifications
     */
    private function prepareRecallNotifications(string $batchNumber, array $recallData, array $customers, array $journey): array
    {
        $notifications = [];

        foreach ($customers as $customer) {
            $notifications[] = [
                'customer_id' => $customer['customer_id'],
                'customer_name' => $customer['customer_name'],
                'notification_type' => 'recall_alert',
                'subject' => 'URGENT: Product Recall Notice',
                'message' => $this->generateRecallMessage($batchNumber, $recallData, $customer, $journey),
                'priority' => $this->getNotificationPriority($recallData['severity']),
                'channels' => $this->getNotificationChannels($recallData['severity'])
            ];
        }

        return $notifications;
    }

    /**
     * Generate recall message
     */
    private function generateRecallMessage(string $batchNumber, array $recallData, array $customer, array $journey): string
    {
        $productName = $journey['product_info']['name'] ?? 'Product';
        $vendorName = $journey['vendor_info']['business_name'] ?? 'Vendor';
        
        return "URGENT RECALL NOTICE\n\n" .
               "Dear {$customer['customer_name']},\n\n" .
               "We are issuing an urgent recall for {$productName} (Batch: {$batchNumber}) " .
               "that you purchased from {$vendorName}.\n\n" .
               "Reason: {$recallData['reason']}\n" .
               "Severity: " . strtoupper($recallData['severity']) . "\n\n" .
               "Quantity purchased: {$customer['quantity_purchased']} units\n" .
               "Purchase date: {$customer['order_date']}\n\n" .
               "IMMEDIATE ACTION REQUIRED:\n" .
               "1. Stop using this product immediately\n" .
               "2. Do not consume or distribute\n" .
               "3. Contact us for return and refund\n\n" .
               "For your safety, please respond to this notice immediately.\n\n" .
               "Contact: [Support Contact Information]";
    }

    /**
     * Get notification priority based on severity
     */
    private function getNotificationPriority(string $severity): string
    {
        return match($severity) {
            'critical' => 'urgent',
            'high' => 'high',
            'medium' => 'normal',
            'low' => 'low',
            default => 'normal'
        };
    }

    /**
     * Get notification channels based on severity
     */
    private function getNotificationChannels(string $severity): array
    {
        return match($severity) {
            'critical' => ['sms', 'email', 'push', 'call'],
            'high' => ['sms', 'email', 'push'],
            'medium' => ['email', 'push'],
            'low' => ['email'],
            default => ['email']
        };
    }

    /**
     * Update batch status
     */
    private function updateBatchStatus(string $batchNumber, string $status): void
    {
        try {
            $sql = "UPDATE inventory_batches SET status = ? WHERE batch_number = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$status, $batchNumber]);

        } catch (Exception $e) {
            // Log error but don't fail the process
        }
    }

    /**
     * Remove from active inventory
     */
    private function removeFromActiveInventory(string $batchNumber): void
    {
        try {
            $sql = "UPDATE inventory_batches SET quantity = 0, status = 'recalled' WHERE batch_number = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$batchNumber]);

        } catch (Exception $e) {
            // Log error but don't fail the process
        }
    }

    /**
     * Get recall record
     */
    private function getRecallRecord(int $recallId): ?array
    {
        try {
            $sql = "SELECT * FROM batch_recalls WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$recallId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get customer recall responses
     */
    private function getCustomerRecallResponses(int $recallId): array
    {
        try {
            $sql = "SELECT * FROM customer_recall_responses WHERE recall_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$recallId]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get recall timeline
     */
    private function getRecallTimeline(int $recallId): array
    {
        try {
            $recall = $this->getRecallRecord($recallId);
            if (!$recall) {
                return [];
            }

            // Get traceability logs related to this recall
            $sql = "SELECT * FROM traceability_logs 
                    WHERE batch_number = ? AND event_type LIKE '%recall%'
                    ORDER BY event_timestamp ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$recall['batch_number']]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Update customer recall response
     */
    private function updateCustomerRecallResponse(int $recallId, int $customerId, array $updateData): bool
    {
        try {
            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE customer_recall_responses SET " . implode(', ', $setParts) . 
                   " WHERE recall_id = ? AND customer_id = ?";
            
            $params = array_values($updateData);
            $params[] = $recallId;
            $params[] = $customerId;
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate datetime format
     */
    private function isValidDateTime(string $datetime): bool
    {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d && $d->format('Y-m-d H:i:s') === $datetime;
    }

    /**
     * Create traceability tables
     */
    private function createTraceabilityTables(): void
    {
        // Traceability logs table
        $sql1 = "CREATE TABLE IF NOT EXISTS traceability_logs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            batch_number VARCHAR(100) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_description TEXT NOT NULL,
            location VARCHAR(255),
            temperature DECIMAL(5,2),
            humidity DECIMAL(5,2),
            handler_id BIGINT,
            handler_name VARCHAR(255),
            additional_data JSON,
            event_timestamp TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_batch_number (batch_number),
            INDEX idx_event_type (event_type),
            INDEX idx_event_timestamp (event_timestamp)
        )";

        // Batch recalls table
        $sql2 = "CREATE TABLE IF NOT EXISTS batch_recalls (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            batch_number VARCHAR(100) NOT NULL,
            reason TEXT NOT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
            initiated_by VARCHAR(255),
            initiated_date TIMESTAMP NOT NULL,
            status ENUM('active', 'in_progress', 'completed', 'cancelled') DEFAULT 'active',
            affected_customers INT DEFAULT 0,
            total_quantity INT DEFAULT 0,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_batch_number (batch_number),
            INDEX idx_status (status),
            INDEX idx_severity (severity),
            INDEX idx_initiated_date (initiated_date)
        )";

        // Customer recall responses table
        $sql3 = "CREATE TABLE IF NOT EXISTS customer_recall_responses (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            recall_id BIGINT NOT NULL,
            customer_id BIGINT NOT NULL,
            customer_name VARCHAR(255),
            quantity_purchased INT DEFAULT 0,
            response_status ENUM('pending', 'acknowledged', 'product_returned', 'no_product', 'refused') DEFAULT 'pending',
            response_date TIMESTAMP NULL,
            product_returned BOOLEAN DEFAULT FALSE,
            return_quantity INT DEFAULT 0,
            customer_notes TEXT,
            refund_amount DECIMAL(10,2) DEFAULT 0,
            refund_processed BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_recall_id (recall_id),
            INDEX idx_customer_id (customer_id),
            INDEX idx_response_status (response_status)
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
        $this->db->exec($sql3);
    }
}