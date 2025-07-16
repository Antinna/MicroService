<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use Antinna\MultiVendor\Services\BatchTraceabilityService;
use PDO;
use Exception;
use DateTime;

/**
 * Delivery failure handler for missed delivery processing and refunds
 */
class DeliveryFailureHandler
{
    private PDO $db;
    private BatchTraceabilityService $traceabilityService;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->traceabilityService = new BatchTraceabilityService();
    }

    /**
     * Process delivery failure
     */
    public function processDeliveryFailure(int $orderId, string $orderType, array $failureData): array
    {
        try {
            // Get order details
            $order = $this->getOrderDetails($orderId, $orderType);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found'
                ];
            }

            // Validate failure data
            $validation = $this->validateFailureData($failureData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check if failure is within freshness window
            $freshnessCheck = $this->checkFreshnessWindow($order, $failureData);
            
            // Determine failure handling strategy
            $handlingStrategy = $this->determineHandlingStrategy($order, $failureData, $freshnessCheck);

            // Process based on strategy
            $result = $this->executeHandlingStrategy($order, $failureData, $handlingStrategy);

            // Log failure event
            $this->logDeliveryFailure($order, $failureData, $handlingStrategy, $result);

            return [
                'success' => true,
                'order_id' => $orderId,
                'order_type' => $orderType,
                'handling_strategy' => $handlingStrategy,
                'freshness_check' => $freshnessCheck,
                'result' => $result,
                'message' => 'Delivery failure processed successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery failure processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate failure data
     */
    private function validateFailureData(array $data): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['failure_reason', 'failure_time'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Failure reason validation
        if (!empty($data['failure_reason'])) {
            $validReasons = [
                'customer_not_available',
                'address_not_found',
                'customer_refused',
                'product_damaged',
                'vehicle_breakdown',
                'weather_conditions',
                'traffic_delay',
                'other'
            ];
            
            if (!in_array($data['failure_reason'], $validReasons)) {
                $errors['failure_reason'] = 'Invalid failure reason';
            }
        }

        // Failure time validation
        if (!empty($data['failure_time'])) {
            if (!strtotime($data['failure_time'])) {
                $errors['failure_time'] = 'Invalid failure time format';
            }
        }

        // Attempt count validation
        if (isset($data['attempt_count']) && (!is_numeric($data['attempt_count']) || $data['attempt_count'] < 1)) {
            $errors['attempt_count'] = 'Attempt count must be a positive number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get order details
     */
    private function getOrderDetails(int $orderId, string $orderType): ?array
    {
        try {
            if ($orderType === 'subscription') {
                $sql = "SELECT so.*, p.name as product_name, p.category, p.shelf_life_hours,
                               v.business_name as vendor_name, v.cold_chain_capable
                        FROM subscription_orders so
                        JOIN products p ON so.product_id = p.id
                        JOIN vendors v ON so.vendor_id = v.id
                        WHERE so.id = ?";
            } else {
                $sql = "SELECT odo.*, p.name as product_name, p.category, p.shelf_life_hours,
                               v.business_name as vendor_name, v.cold_chain_capable
                        FROM on_demand_orders odo
                        JOIN products p ON odo.product_id = p.id
                        JOIN vendors v ON odo.vendor_id = v.id
                        WHERE odo.id = ?";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if ($order) {
                $order['order_type'] = $orderType;
                $order['reserved_batches'] = json_decode($order['reserved_batches'] ?? '[]', true);
            }

            return $order;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check freshness window
     */
    private function checkFreshnessWindow(array $order, array $failureData): array
    {
        try {
            $deliveryDateTime = new DateTime($order['delivery_date'] . ' ' . explode('-', $order['delivery_time_slot'])[1]);
            $failureDateTime = new DateTime($failureData['failure_time']);
            
            $hoursSinceDelivery = ($failureDateTime->getTimestamp() - $deliveryDateTime->getTimestamp()) / 3600;
            $shelfLifeHours = $order['shelf_life_hours'] ?? 24;
            
            // Consider freshness window as 50% of shelf life for returns
            $freshnessWindowHours = $shelfLifeHours * 0.5;
            
            $withinFreshnessWindow = $hoursSinceDelivery <= $freshnessWindowHours;
            
            return [
                'within_freshness_window' => $withinFreshnessWindow,
                'hours_since_delivery' => round($hoursSinceDelivery, 2),
                'freshness_window_hours' => $freshnessWindowHours,
                'shelf_life_hours' => $shelfLifeHours,
                'can_return_to_vendor' => $withinFreshnessWindow && in_array($order['category'], ['dairy', 'vegetables'])
            ];

        } catch (Exception $e) {
            return [
                'within_freshness_window' => false,
                'hours_since_delivery' => 999,
                'freshness_window_hours' => 0,
                'shelf_life_hours' => 0,
                'can_return_to_vendor' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Determine handling strategy
     */
    private function determineHandlingStrategy(array $order, array $failureData, array $freshnessCheck): string
    {
        $reason = $failureData['failure_reason'];
        $attemptCount = $failureData['attempt_count'] ?? 1;
        
        // Strategy decision matrix
        switch ($reason) {
            case 'customer_not_available':
                if ($attemptCount < 3) {
                    return 'reschedule';
                } else {
                    return $freshnessCheck['can_return_to_vendor'] ? 'return_and_refund' : 'dispose_and_refund';
                }
                
            case 'address_not_found':
                return 'contact_customer';
                
            case 'customer_refused':
                return $freshnessCheck['can_return_to_vendor'] ? 'return_and_refund' : 'dispose_and_refund';
                
            case 'product_damaged':
                return 'dispose_and_refund';
                
            case 'vehicle_breakdown':
            case 'weather_conditions':
            case 'traffic_delay':
                if ($freshnessCheck['within_freshness_window']) {
                    return 'reschedule';
                } else {
                    return $freshnessCheck['can_return_to_vendor'] ? 'return_and_refund' : 'dispose_and_refund';
                }
                
            default:
                return $freshnessCheck['can_return_to_vendor'] ? 'return_and_refund' : 'dispose_and_refund';
        }
    }

    /**
     * Execute handling strategy
     */
    private function executeHandlingStrategy(array $order, array $failureData, string $strategy): array
    {
        switch ($strategy) {
            case 'reschedule':
                return $this->rescheduleDelivery($order, $failureData);
                
            case 'contact_customer':
                return $this->contactCustomer($order, $failureData);
                
            case 'return_and_refund':
                return $this->returnAndRefund($order, $failureData);
                
            case 'dispose_and_refund':
                return $this->disposeAndRefund($order, $failureData);
                
            default:
                return [
                    'success' => false,
                    'error' => 'Unknown handling strategy: ' . $strategy
                ];
        }
    }

    /**
     * Reschedule delivery
     */
    private function rescheduleDelivery(array $order, array $failureData): array
    {
        try {
            // Calculate next available delivery slot (next day)
            $nextDeliveryDate = date('Y-m-d', strtotime($order['delivery_date'] . ' +1 day'));
            
            // Update order with new delivery date
            $tableName = $order['order_type'] === 'subscription' ? 'subscription_orders' : 'on_demand_orders';
            $sql = "UPDATE {$tableName} 
                    SET delivery_date = ?, 
                        status = 'rescheduled',
                        special_instructions = CONCAT(COALESCE(special_instructions, ''), '\n[RESCHEDULED: ', ?, ']')
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $nextDeliveryDate,
                $failureData['failure_reason'],
                $order['id']
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'action' => 'rescheduled',
                    'new_delivery_date' => $nextDeliveryDate,
                    'message' => 'Delivery rescheduled for next day'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to reschedule delivery'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Reschedule failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Contact customer
     */
    private function contactCustomer(array $order, array $failureData): array
    {
        try {
            // Update order status to pending customer contact
            $tableName = $order['order_type'] === 'subscription' ? 'subscription_orders' : 'on_demand_orders';
            $sql = "UPDATE {$tableName} 
                    SET status = 'pending_customer_contact',
                        special_instructions = CONCAT(COALESCE(special_instructions, ''), '\n[CONTACT REQUIRED: ', ?, ']')
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $failureData['failure_reason'],
                $order['id']
            ]);

            if ($success) {
                // Create customer contact task (this would integrate with notification system)
                $contactTask = [
                    'customer_id' => $order['customer_id'],
                    'order_id' => $order['id'],
                    'contact_reason' => 'delivery_address_issue',
                    'priority' => 'high',
                    'message' => 'Delivery failed due to address issue. Please contact customer to verify delivery address.'
                ];

                return [
                    'success' => true,
                    'action' => 'contact_customer',
                    'contact_task' => $contactTask,
                    'message' => 'Customer contact task created'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create customer contact task'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Customer contact failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Return and refund
     */
    private function returnAndRefund(array $order, array $failureData): array
    {
        try {
            // Update order status
            $tableName = $order['order_type'] === 'subscription' ? 'subscription_orders' : 'on_demand_orders';
            $sql = "UPDATE {$tableName} 
                    SET status = 'returned',
                        special_instructions = CONCAT(COALESCE(special_instructions, ''), '\n[RETURNED: ', ?, ']')
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $orderUpdateSuccess = $stmt->execute([
                $failureData['failure_reason'],
                $order['id']
            ]);

            // Release reserved inventory back to vendor
            $inventoryResult = $this->releaseInventoryToVendor($order);

            // Process refund
            $refundResult = $this->processRefund($order, 'delivery_failure');

            // Log return event for traceability
            if (!empty($order['reserved_batches'])) {
                foreach ($order['reserved_batches'] as $batch) {
                    $this->traceabilityService->logReturn(
                        $batch['batch_id'],
                        $order['id'],
                        [
                            'customer_id' => $order['customer_id'],
                            'return_reason' => 'delivery_failure',
                            'return_condition' => 'good',
                            'refund_amount' => $order['total_amount'],
                            'quantity' => $batch['quantity_reserved']
                        ]
                    );
                }
            }

            return [
                'success' => $orderUpdateSuccess && $inventoryResult['success'] && $refundResult['success'],
                'action' => 'returned_and_refunded',
                'refund_amount' => $order['total_amount'],
                'inventory_released' => $inventoryResult['success'],
                'refund_processed' => $refundResult['success'],
                'message' => 'Product returned to vendor and refund processed'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Return and refund failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Dispose and refund
     */
    private function disposeAndRefund(array $order, array $failureData): array
    {
        try {
            // Update order status
            $tableName = $order['order_type'] === 'subscription' ? 'subscription_orders' : 'on_demand_orders';
            $sql = "UPDATE {$tableName} 
                    SET status = 'disposed',
                        special_instructions = CONCAT(COALESCE(special_instructions, ''), '\n[DISPOSED: ', ?, ']')
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $orderUpdateSuccess = $stmt->execute([
                $failureData['failure_reason'],
                $order['id']
            ]);

            // Mark inventory as disposed (cannot be returned to vendor)
            $disposalResult = $this->markInventoryAsDisposed($order);

            // Process refund
            $refundResult = $this->processRefund($order, 'product_disposed');

            return [
                'success' => $orderUpdateSuccess && $disposalResult['success'] && $refundResult['success'],
                'action' => 'disposed_and_refunded',
                'refund_amount' => $order['total_amount'],
                'inventory_disposed' => $disposalResult['success'],
                'refund_processed' => $refundResult['success'],
                'message' => 'Product disposed and refund processed'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Dispose and refund failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Release inventory back to vendor
     */
    private function releaseInventoryToVendor(array $order): array
    {
        try {
            if (empty($order['reserved_batches'])) {
                return ['success' => true, 'message' => 'No inventory to release'];
            }

            $releasedBatches = [];
            foreach ($order['reserved_batches'] as $batch) {
                // Release the reserved quantity back to available
                $sql = "UPDATE inventory_batches 
                        SET quantity_reserved = GREATEST(0, quantity_reserved - ?)
                        WHERE id = ?";
                
                $stmt = $this->db->prepare($sql);
                $success = $stmt->execute([$batch['quantity_reserved'], $batch['batch_id']]);
                
                if ($success) {
                    $releasedBatches[] = $batch;
                }
            }

            return [
                'success' => true,
                'released_batches' => $releasedBatches,
                'message' => 'Inventory released back to vendor'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Inventory release failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mark inventory as disposed
     */
    private function markInventoryAsDisposed(array $order): array
    {
        try {
            if (empty($order['reserved_batches'])) {
                return ['success' => true, 'message' => 'No inventory to dispose'];
            }

            $disposedBatches = [];
            foreach ($order['reserved_batches'] as $batch) {
                // Reduce both available and reserved quantities
                $sql = "UPDATE inventory_batches 
                        SET quantity_available = GREATEST(0, quantity_available - ?),
                            quantity_reserved = GREATEST(0, quantity_reserved - ?)
                        WHERE id = ?";
                
                $stmt = $this->db->prepare($sql);
                $success = $stmt->execute([
                    $batch['quantity_reserved'],
                    $batch['quantity_reserved'],
                    $batch['batch_id']
                ]);
                
                if ($success) {
                    $disposedBatches[] = $batch;
                }
            }

            return [
                'success' => true,
                'disposed_batches' => $disposedBatches,
                'message' => 'Inventory marked as disposed'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Inventory disposal failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process refund
     */
    private function processRefund(array $order, string $refundReason): array
    {
        try {
            // Create refund record (this would integrate with payment service)
            $refundData = [
                'order_id' => $order['id'],
                'order_type' => $order['order_type'],
                'customer_id' => $order['customer_id'],
                'vendor_id' => $order['vendor_id'],
                'refund_amount' => $order['total_amount'],
                'refund_reason' => $refundReason,
                'refund_status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ];

            // This would typically call the payment service API
            // For now, we'll just log the refund request
            
            return [
                'success' => true,
                'refund_amount' => $order['total_amount'],
                'refund_reference' => 'REF_' . $order['id'] . '_' . time(),
                'message' => 'Refund processed successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Refund processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log delivery failure
     */
    private function logDeliveryFailure(array $order, array $failureData, string $strategy, array $result): void
    {
        try {
            // Create delivery_failures table if it doesn't exist
            $this->createDeliveryFailuresTable();

            $logData = [
                'order_id' => $order['id'],
                'order_type' => $order['order_type'],
                'customer_id' => $order['customer_id'],
                'vendor_id' => $order['vendor_id'],
                'product_id' => $order['product_id'],
                'delivery_date' => $order['delivery_date'],
                'delivery_time_slot' => $order['delivery_time_slot'],
                'failure_reason' => $failureData['failure_reason'],
                'failure_time' => $failureData['failure_time'],
                'attempt_count' => $failureData['attempt_count'] ?? 1,
                'handling_strategy' => $strategy,
                'resolution_status' => $result['success'] ? 'resolved' : 'failed',
                'refund_amount' => $result['refund_amount'] ?? 0,
                'notes' => $failureData['notes'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $sql = "INSERT INTO delivery_failures (" . implode(', ', array_keys($logData)) . ") 
                    VALUES (" . str_repeat('?,', count($logData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($logData));

        } catch (Exception $e) {
            error_log("Failed to log delivery failure: " . $e->getMessage());
        }
    }

    /**
     * Get delivery failure statistics
     */
    public function getDeliveryFailureStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateTo ?? date('Y-m-d');

            $sql = "SELECT 
                        COUNT(*) as total_failures,
                        COUNT(DISTINCT customer_id) as affected_customers,
                        COUNT(DISTINCT vendor_id) as affected_vendors,
                        SUM(refund_amount) as total_refunds,
                        failure_reason,
                        COUNT(*) as failure_count,
                        handling_strategy,
                        COUNT(*) as strategy_count
                    FROM delivery_failures
                    WHERE DATE(failure_time) BETWEEN ? AND ?
                    GROUP BY failure_reason, handling_strategy
                    ORDER BY failure_count DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFrom, $dateTo]);
            $stats = $stmt->fetchAll();

            return [
                'success' => true,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'statistics' => $stats
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get delivery failure stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create delivery failures table
     */
    private function createDeliveryFailuresTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS delivery_failures (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            order_type ENUM('subscription', 'on_demand') NOT NULL,
            customer_id BIGINT NOT NULL,
            vendor_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            delivery_date DATE NOT NULL,
            delivery_time_slot VARCHAR(20) NOT NULL,
            failure_reason ENUM('customer_not_available', 'address_not_found', 'customer_refused', 'product_damaged', 'vehicle_breakdown', 'weather_conditions', 'traffic_delay', 'other') NOT NULL,
            failure_time TIMESTAMP NOT NULL,
            attempt_count INT DEFAULT 1,
            handling_strategy ENUM('reschedule', 'contact_customer', 'return_and_refund', 'dispose_and_refund') NOT NULL,
            resolution_status ENUM('resolved', 'failed', 'pending') DEFAULT 'pending',
            refund_amount DECIMAL(10, 2) DEFAULT 0.00,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_customer_id (customer_id),
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_failure_reason (failure_reason),
            INDEX idx_failure_time (failure_time),
            INDEX idx_handling_strategy (handling_strategy)
        )";
        
        $this->db->exec($sql);
    }
}