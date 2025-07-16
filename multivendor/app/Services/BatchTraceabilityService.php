<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Batch traceability service for maintaining batch-to-order mapping
 */
class BatchTraceabilityService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Log traceability event
     */
    public function logEvent(int $batchId, string $eventType, array $eventData = []): array
    {
        try {
            $sql = "INSERT INTO traceability_logs (
                batch_id, order_id, customer_id, event_type, event_description,
                location, temperature, humidity, handled_by, notes, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $batchId,
                $eventData['order_id'] ?? null,
                $eventData['customer_id'] ?? null,
                $eventType,
                $eventData['description'] ?? null,
                $eventData['location'] ?? null,
                $eventData['temperature'] ?? null,
                $eventData['humidity'] ?? null,
                $eventData['handled_by'] ?? null,
                $eventData['notes'] ?? null,
                json_encode($eventData['metadata'] ?? [])
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'log_id' => $this->db->lastInsertId(),
                    'message' => 'Traceability event logged successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to log traceability event'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Traceability logging failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log production event
     */
    public function logProduction(int $batchId, array $productionData): array
    {
        $eventData = [
            'description' => 'Batch produced',
            'location' => $productionData['production_facility'] ?? null,
            'temperature' => $productionData['production_temperature'] ?? null,
            'handled_by' => $productionData['producer'] ?? null,
            'notes' => $productionData['production_notes'] ?? null,
            'metadata' => [
                'production_method' => $productionData['production_method'] ?? null,
                'raw_materials' => $productionData['raw_materials'] ?? [],
                'quality_checks' => $productionData['quality_checks'] ?? []
            ]
        ];

        return $this->logEvent($batchId, 'production', $eventData);
    }

    /**
     * Log quality check event
     */
    public function logQualityCheck(int $batchId, array $qualityData): array
    {
        $eventData = [
            'description' => 'Quality check performed',
            'location' => $qualityData['check_location'] ?? null,
            'temperature' => $qualityData['sample_temperature'] ?? null,
            'handled_by' => $qualityData['inspector'] ?? null,
            'notes' => $qualityData['check_notes'] ?? null,
            'metadata' => [
                'check_type' => $qualityData['check_type'] ?? null,
                'test_results' => $qualityData['test_results'] ?? [],
                'passed' => $qualityData['passed'] ?? true,
                'defects_found' => $qualityData['defects_found'] ?? []
            ]
        ];

        return $this->logEvent($batchId, 'quality_check', $eventData);
    }

    /**
     * Log storage event
     */
    public function logStorage(int $batchId, array $storageData): array
    {
        $eventData = [
            'description' => 'Batch stored',
            'location' => $storageData['storage_location'] ?? null,
            'temperature' => $storageData['storage_temperature'] ?? null,
            'humidity' => $storageData['storage_humidity'] ?? null,
            'handled_by' => $storageData['handler'] ?? null,
            'notes' => $storageData['storage_notes'] ?? null,
            'metadata' => [
                'storage_type' => $storageData['storage_type'] ?? null,
                'storage_conditions' => $storageData['storage_conditions'] ?? [],
                'expected_duration' => $storageData['expected_duration'] ?? null
            ]
        ];

        return $this->logEvent($batchId, 'storage', $eventData);
    }

    /**
     * Log dispatch event
     */
    public function logDispatch(int $batchId, int $orderId, array $dispatchData): array
    {
        $eventData = [
            'order_id' => $orderId,
            'customer_id' => $dispatchData['customer_id'] ?? null,
            'description' => 'Batch dispatched for delivery',
            'location' => $dispatchData['dispatch_location'] ?? null,
            'temperature' => $dispatchData['dispatch_temperature'] ?? null,
            'handled_by' => $dispatchData['dispatcher'] ?? null,
            'notes' => $dispatchData['dispatch_notes'] ?? null,
            'metadata' => [
                'vehicle_id' => $dispatchData['vehicle_id'] ?? null,
                'driver_id' => $dispatchData['driver_id'] ?? null,
                'expected_delivery_time' => $dispatchData['expected_delivery_time'] ?? null,
                'dispatch_quantity' => $dispatchData['quantity'] ?? null
            ]
        ];

        return $this->logEvent($batchId, 'dispatch', $eventData);
    }

    /**
     * Log delivery event
     */
    public function logDelivery(int $batchId, int $orderId, int $customerId, array $deliveryData): array
    {
        $eventData = [
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'description' => 'Batch delivered to customer',
            'location' => $deliveryData['delivery_address'] ?? null,
            'temperature' => $deliveryData['delivery_temperature'] ?? null,
            'handled_by' => $deliveryData['delivery_person'] ?? null,
            'notes' => $deliveryData['delivery_notes'] ?? null,
            'metadata' => [
                'delivery_status' => $deliveryData['status'] ?? 'delivered',
                'delivery_time' => $deliveryData['delivery_time'] ?? date('Y-m-d H:i:s'),
                'customer_signature' => $deliveryData['customer_signature'] ?? null,
                'delivery_photo' => $deliveryData['delivery_photo'] ?? null
            ]
        ];

        return $this->logEvent($batchId, 'delivery', $eventData);
    }

    /**
     * Log return event
     */
    public function logReturn(int $batchId, int $orderId, array $returnData): array
    {
        $eventData = [
            'order_id' => $orderId,
            'customer_id' => $returnData['customer_id'] ?? null,
            'description' => 'Batch returned',
            'location' => $returnData['return_location'] ?? null,
            'handled_by' => $returnData['handler'] ?? null,
            'notes' => $returnData['return_notes'] ?? null,
            'metadata' => [
                'return_reason' => $returnData['return_reason'] ?? null,
                'return_condition' => $returnData['return_condition'] ?? null,
                'refund_amount' => $returnData['refund_amount'] ?? null,
                'return_quantity' => $returnData['quantity'] ?? null
            ]
        ];

        return $this->logEvent($batchId, 'return', $eventData);
    }

    /**
     * Log recall event
     */
    public function logRecall(int $batchId, array $recallData): array
    {
        $eventData = [
            'description' => 'Batch recalled',
            'location' => $recallData['recall_location'] ?? null,
            'handled_by' => $recallData['recall_manager'] ?? null,
            'notes' => $recallData['recall_notes'] ?? null,
            'metadata' => [
                'recall_reason' => $recallData['recall_reason'] ?? null,
                'recall_severity' => $recallData['recall_severity'] ?? null,
                'affected_customers' => $recallData['affected_customers'] ?? [],
                'recall_actions' => $recallData['recall_actions'] ?? []
            ]
        ];

        return $this->logEvent($batchId, 'recall', $eventData);
    }

    /**
     * Get complete traceability chain for batch
     */
    public function getTraceabilityChain(int $batchId): array
    {
        try {
            $sql = "SELECT tl.*, ib.batch_number, ib.production_date, ib.expiry_date,
                           p.name as product_name, v.business_name as vendor_name
                    FROM traceability_logs tl
                    JOIN inventory_batches ib ON tl.batch_id = ib.id
                    JOIN products p ON ib.product_id = p.id
                    JOIN vendors v ON p.vendor_id = v.id
                    WHERE tl.batch_id = ?
                    ORDER BY tl.event_timestamp ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$batchId]);
            
            $events = $stmt->fetchAll();
            
            // Decode metadata for each event
            foreach ($events as &$event) {
                $event['metadata'] = json_decode($event['metadata'], true);
            }

            return [
                'success' => true,
                'batch_id' => $batchId,
                'events' => $events,
                'event_count' => count($events)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving traceability chain: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get traceability for order
     */
    public function getOrderTraceability(int $orderId): array
    {
        try {
            $sql = "SELECT DISTINCT tl.batch_id, ib.batch_number, ib.production_date, ib.expiry_date,
                           p.name as product_name, v.business_name as vendor_name
                    FROM traceability_logs tl
                    JOIN inventory_batches ib ON tl.batch_id = ib.id
                    JOIN products p ON ib.product_id = p.id
                    JOIN vendors v ON p.vendor_id = v.id
                    WHERE tl.order_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderId]);
            
            $batches = $stmt->fetchAll();
            
            $traceability = [];
            foreach ($batches as $batch) {
                $chainResult = $this->getTraceabilityChain($batch['batch_id']);
                if ($chainResult['success']) {
                    $traceability[] = [
                        'batch_info' => $batch,
                        'events' => $chainResult['events']
                    ];
                }
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'batches' => $traceability,
                'batch_count' => count($traceability)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving order traceability: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get traceability for customer
     */
    public function getCustomerTraceability(int $customerId, ?int $limit = 50): array
    {
        try {
            $sql = "SELECT DISTINCT tl.batch_id, tl.order_id, ib.batch_number, ib.production_date, ib.expiry_date,
                           p.name as product_name, v.business_name as vendor_name,
                           MAX(tl.event_timestamp) as last_event_time
                    FROM traceability_logs tl
                    JOIN inventory_batches ib ON tl.batch_id = ib.id
                    JOIN products p ON ib.product_id = p.id
                    JOIN vendors v ON p.vendor_id = v.id
                    WHERE tl.customer_id = ?
                    GROUP BY tl.batch_id, tl.order_id
                    ORDER BY last_event_time DESC
                    LIMIT ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$customerId, $limit]);
            
            $customerBatches = $stmt->fetchAll();

            return [
                'success' => true,
                'customer_id' => $customerId,
                'batches' => $customerBatches,
                'batch_count' => count($customerBatches)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving customer traceability: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Find batches by criteria for recall purposes
     */
    public function findBatchesByCriteria(array $criteria): array
    {
        try {
            $conditions = [];
            $params = [];

            // Build dynamic query based on criteria
            $sql = "SELECT DISTINCT ib.id, ib.batch_number, ib.production_date, ib.expiry_date,
                           p.name as product_name, v.business_name as vendor_name,
                           COUNT(tl.id) as event_count
                    FROM inventory_batches ib
                    JOIN products p ON ib.product_id = p.id
                    JOIN vendors v ON p.vendor_id = v.id
                    LEFT JOIN traceability_logs tl ON ib.id = tl.batch_id";

            if (!empty($criteria['vendor_id'])) {
                $conditions[] = "v.id = ?";
                $params[] = $criteria['vendor_id'];
            }

            if (!empty($criteria['product_id'])) {
                $conditions[] = "p.id = ?";
                $params[] = $criteria['product_id'];
            }

            if (!empty($criteria['production_date_from'])) {
                $conditions[] = "ib.production_date >= ?";
                $params[] = $criteria['production_date_from'];
            }

            if (!empty($criteria['production_date_to'])) {
                $conditions[] = "ib.production_date <= ?";
                $params[] = $criteria['production_date_to'];
            }

            if (!empty($criteria['farm_source'])) {
                $conditions[] = "ib.farm_source LIKE ?";
                $params[] = '%' . $criteria['farm_source'] . '%';
            }

            if (!empty($criteria['quality_grade'])) {
                $conditions[] = "ib.quality_grade = ?";
                $params[] = $criteria['quality_grade'];
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $sql .= " GROUP BY ib.id ORDER BY ib.production_date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $batches = $stmt->fetchAll();

            return [
                'success' => true,
                'criteria' => $criteria,
                'batches' => $batches,
                'batch_count' => count($batches)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error finding batches by criteria: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get traceability statistics
     */
    public function getTraceabilityStats(?int $vendorId = null): array
    {
        try {
            $sql = "SELECT 
                        event_type,
                        COUNT(*) as event_count,
                        COUNT(DISTINCT batch_id) as unique_batches,
                        COUNT(DISTINCT order_id) as unique_orders,
                        COUNT(DISTINCT customer_id) as unique_customers
                    FROM traceability_logs tl";

            $params = [];
            if ($vendorId) {
                $sql .= " JOIN inventory_batches ib ON tl.batch_id = ib.id
                         JOIN products p ON ib.product_id = p.id
                         WHERE p.vendor_id = ?";
                $params[] = $vendorId;
            }

            $sql .= " GROUP BY event_type ORDER BY event_count DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $stats = $stmt->fetchAll();

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'stats' => $stats
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving traceability stats: ' . $e->getMessage()
            ];
        }
    }
}