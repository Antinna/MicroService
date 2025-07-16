<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Order consolidation service to merge subscription and on-demand orders
 */
class OrderConsolidator
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Consolidate orders by location, vendor, and delivery slot
     */
    public function consolidateOrders(string $deliveryDate): array
    {
        try {
            // Get all orders for the delivery date
            $orders = $this->getOrdersForDate($deliveryDate);
            
            if (empty($orders)) {
                return [
                    'success' => true,
                    'delivery_date' => $deliveryDate,
                    'total_orders' => 0,
                    'consolidated_groups' => [],
                    'message' => 'No orders found for consolidation'
                ];
            }

            // Group orders by consolidation criteria
            $consolidatedGroups = $this->groupOrdersForConsolidation($orders);
            
            // Create consolidated delivery records
            $consolidationResults = [];
            foreach ($consolidatedGroups as $groupKey => $group) {
                $result = $this->createConsolidatedDelivery($group, $deliveryDate);
                $consolidationResults[$groupKey] = $result;
            }

            return [
                'success' => true,
                'delivery_date' => $deliveryDate,
                'total_orders' => count($orders),
                'consolidated_groups' => count($consolidatedGroups),
                'consolidation_results' => $consolidationResults,
                'message' => 'Orders consolidated successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Order consolidation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get orders for specific date
     */
    private function getOrdersForDate(string $deliveryDate): array
    {
        try {
            // Get subscription orders
            $subscriptionSql = "SELECT 
                                    'subscription' as order_type,
                                    id as order_id,
                                    subscription_id,
                                    customer_id,
                                    vendor_id,
                                    product_id,
                                    quantity,
                                    unit_price,
                                    total_amount,
                                    delivery_time_slot,
                                    delivery_address,
                                    delivery_latitude,
                                    delivery_longitude,
                                    special_instructions,
                                    status,
                                    reserved_batches
                                FROM subscription_orders 
                                WHERE delivery_date = ? 
                                AND status IN ('confirmed', 'preparing')";

            $stmt = $this->db->prepare($subscriptionSql);
            $stmt->execute([$deliveryDate]);
            $subscriptionOrders = $stmt->fetchAll();

            // Get on-demand orders (if table exists)
            $onDemandOrders = [];
            try {
                $onDemandSql = "SELECT 
                                    'on_demand' as order_type,
                                    id as order_id,
                                    NULL as subscription_id,
                                    customer_id,
                                    vendor_id,
                                    product_id,
                                    quantity,
                                    unit_price,
                                    total_amount,
                                    delivery_time_slot,
                                    delivery_address,
                                    delivery_latitude,
                                    delivery_longitude,
                                    special_instructions,
                                    status,
                                    reserved_batches
                                FROM on_demand_orders 
                                WHERE delivery_date = ? 
                                AND status IN ('confirmed', 'preparing')";

                $stmt = $this->db->prepare($onDemandSql);
                $stmt->execute([$deliveryDate]);
                $onDemandOrders = $stmt->fetchAll();
            } catch (Exception $e) {
                // On-demand orders table might not exist yet
            }

            // Merge all orders
            $allOrders = array_merge($subscriptionOrders, $onDemandOrders);

            // Add product and vendor information
            foreach ($allOrders as &$order) {
                $order['product_info'] = $this->getProductInfo($order['product_id']);
                $order['vendor_info'] = $this->getVendorInfo($order['vendor_id']);
            }

            return $allOrders;

        } catch (Exception $e) {
            throw new Exception('Failed to get orders for date: ' . $e->getMessage());
        }
    }

    /**
     * Group orders for consolidation
     */
    private function groupOrdersForConsolidation(array $orders): array
    {
        $groups = [];

        foreach ($orders as $order) {
            // Create consolidation key based on location, vendor, and time slot
            $locationKey = $this->generateLocationKey(
                $order['delivery_latitude'],
                $order['delivery_longitude'],
                $order['delivery_address']
            );
            
            $groupKey = sprintf(
                'vendor_%d_location_%s_slot_%s',
                $order['vendor_id'],
                $locationKey,
                str_replace(':', '', $order['delivery_time_slot'])
            );

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'vendor_id' => $order['vendor_id'],
                    'vendor_info' => $order['vendor_info'],
                    'delivery_time_slot' => $order['delivery_time_slot'],
                    'delivery_location' => [
                        'address' => $order['delivery_address'],
                        'latitude' => $order['delivery_latitude'],
                        'longitude' => $order['delivery_longitude']
                    ],
                    'orders' => [],
                    'total_orders' => 0,
                    'total_quantity' => 0,
                    'total_amount' => 0,
                    'customers' => [],
                    'products' => [],
                    'requires_cold_chain' => false
                ];
            }

            // Add order to group
            $groups[$groupKey]['orders'][] = $order;
            $groups[$groupKey]['total_orders']++;
            $groups[$groupKey]['total_quantity'] += $order['quantity'];
            $groups[$groupKey]['total_amount'] += $order['total_amount'];

            // Track unique customers
            if (!in_array($order['customer_id'], $groups[$groupKey]['customers'])) {
                $groups[$groupKey]['customers'][] = $order['customer_id'];
            }

            // Track unique products
            $productKey = $order['product_id'];
            if (!isset($groups[$groupKey]['products'][$productKey])) {
                $groups[$groupKey]['products'][$productKey] = [
                    'product_id' => $order['product_id'],
                    'product_info' => $order['product_info'],
                    'total_quantity' => 0,
                    'orders' => []
                ];
            }
            $groups[$groupKey]['products'][$productKey]['total_quantity'] += $order['quantity'];
            $groups[$groupKey]['products'][$productKey]['orders'][] = $order['order_id'];

            // Check if any product requires cold chain
            if ($order['product_info']['requires_cold_chain']) {
                $groups[$groupKey]['requires_cold_chain'] = true;
            }
        }

        return $groups;
    }

    /**
     * Create consolidated delivery record
     */
    private function createConsolidatedDelivery(array $group, string $deliveryDate): array
    {
        try {
            // Create consolidated_orders table if it doesn't exist
            $this->createConsolidatedOrdersTable();

            $consolidatedData = [
                'delivery_date' => $deliveryDate,
                'vendor_id' => $group['vendor_id'],
                'delivery_time_slot' => $group['delivery_time_slot'],
                'delivery_address' => $group['delivery_location']['address'],
                'delivery_latitude' => $group['delivery_location']['latitude'],
                'delivery_longitude' => $group['delivery_location']['longitude'],
                'total_orders' => $group['total_orders'],
                'total_quantity' => $group['total_quantity'],
                'total_amount' => $group['total_amount'],
                'customer_count' => count($group['customers']),
                'product_count' => count($group['products']),
                'requires_cold_chain' => $group['requires_cold_chain'],
                'order_ids' => json_encode(array_column($group['orders'], 'order_id')),
                'customer_ids' => json_encode($group['customers']),
                'product_summary' => json_encode(array_values($group['products'])),
                'consolidation_key' => $group['group_key'],
                'status' => 'consolidated',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $sql = "INSERT INTO consolidated_orders (" . implode(', ', array_keys($consolidatedData)) . ") 
                    VALUES (" . str_repeat('?,', count($consolidatedData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($consolidatedData));

            if ($success) {
                $consolidatedId = $this->db->lastInsertId();
                
                // Update individual orders with consolidation reference
                $this->updateOrdersWithConsolidationId($group['orders'], $consolidatedId);

                return [
                    'success' => true,
                    'consolidated_id' => $consolidatedId,
                    'group_key' => $group['group_key'],
                    'total_orders' => $group['total_orders'],
                    'total_amount' => $group['total_amount'],
                    'optimization_score' => $this->calculateOptimizationScore($group)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create consolidated delivery record'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Consolidation creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate location key for grouping
     */
    private function generateLocationKey(?float $latitude, ?float $longitude, string $address): string
    {
        if ($latitude && $longitude) {
            // Round coordinates to create location clusters (approximately 100m radius)
            $roundedLat = round($latitude, 3);
            $roundedLng = round($longitude, 3);
            return "coord_{$roundedLat}_{$roundedLng}";
        } else {
            // Use address hash for grouping
            return 'addr_' . substr(md5($address), 0, 8);
        }
    }

    /**
     * Get product information
     */
    private function getProductInfo(int $productId): array
    {
        try {
            $sql = "SELECT id, name, category, requires_cold_chain, unit_type 
                    FROM products WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            return $product ?: [
                'id' => $productId,
                'name' => 'Unknown Product',
                'category' => 'unknown',
                'requires_cold_chain' => false,
                'unit_type' => 'piece'
            ];

        } catch (Exception $e) {
            return [
                'id' => $productId,
                'name' => 'Unknown Product',
                'category' => 'unknown',
                'requires_cold_chain' => false,
                'unit_type' => 'piece'
            ];
        }
    }

    /**
     * Get vendor information
     */
    private function getVendorInfo(int $vendorId): array
    {
        try {
            $sql = "SELECT id, business_name, cold_chain_capable 
                    FROM vendors WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $vendor = $stmt->fetch();

            return $vendor ?: [
                'id' => $vendorId,
                'business_name' => 'Unknown Vendor',
                'cold_chain_capable' => false
            ];

        } catch (Exception $e) {
            return [
                'id' => $vendorId,
                'business_name' => 'Unknown Vendor',
                'cold_chain_capable' => false
            ];
        }
    }

    /**
     * Update orders with consolidation ID
     */
    private function updateOrdersWithConsolidationId(array $orders, int $consolidatedId): void
    {
        try {
            foreach ($orders as $order) {
                if ($order['order_type'] === 'subscription') {
                    $sql = "UPDATE subscription_orders 
                            SET consolidated_delivery_id = ? 
                            WHERE id = ?";
                } else {
                    $sql = "UPDATE on_demand_orders 
                            SET consolidated_delivery_id = ? 
                            WHERE id = ?";
                }

                $stmt = $this->db->prepare($sql);
                $stmt->execute([$consolidatedId, $order['order_id']]);
            }

        } catch (Exception $e) {
            error_log("Failed to update orders with consolidation ID: " . $e->getMessage());
        }
    }

    /**
     * Calculate optimization score
     */
    private function calculateOptimizationScore(array $group): float
    {
        // Simple optimization score based on:
        // - Number of orders consolidated
        // - Total quantity
        // - Customer density
        
        $orderScore = min($group['total_orders'] * 10, 50); // Max 50 points for orders
        $quantityScore = min($group['total_quantity'] * 2, 30); // Max 30 points for quantity
        $customerScore = count($group['customers']) * 5; // 5 points per unique customer
        
        return round(($orderScore + $quantityScore + $customerScore) / 100 * 100, 2);
    }

    /**
     * Get consolidation statistics
     */
    public function getConsolidationStats(string $deliveryDate): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_consolidated_deliveries,
                        SUM(total_orders) as total_orders_consolidated,
                        SUM(total_amount) as total_value_consolidated,
                        AVG(total_orders) as avg_orders_per_delivery,
                        COUNT(DISTINCT vendor_id) as vendors_involved,
                        SUM(customer_count) as total_customers_served
                    FROM consolidated_orders
                    WHERE delivery_date = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$deliveryDate]);
            $stats = $stmt->fetch();

            return [
                'success' => true,
                'delivery_date' => $deliveryDate,
                'statistics' => $stats ?: [
                    'total_consolidated_deliveries' => 0,
                    'total_orders_consolidated' => 0,
                    'total_value_consolidated' => 0,
                    'avg_orders_per_delivery' => 0,
                    'vendors_involved' => 0,
                    'total_customers_served' => 0
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get consolidation stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get consolidated deliveries for vendor
     */
    public function getVendorConsolidatedDeliveries(int $vendorId, string $deliveryDate): array
    {
        try {
            $sql = "SELECT * FROM consolidated_orders 
                    WHERE vendor_id = ? AND delivery_date = ?
                    ORDER BY delivery_time_slot";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $deliveryDate]);
            $deliveries = $stmt->fetchAll();

            // Decode JSON fields
            foreach ($deliveries as &$delivery) {
                $delivery['order_ids'] = json_decode($delivery['order_ids'], true);
                $delivery['customer_ids'] = json_decode($delivery['customer_ids'], true);
                $delivery['product_summary'] = json_decode($delivery['product_summary'], true);
            }

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'delivery_date' => $deliveryDate,
                'deliveries' => $deliveries
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get vendor consolidated deliveries: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update consolidation status
     */
    public function updateConsolidationStatus(int $consolidatedId, string $status): array
    {
        try {
            $validStatuses = ['consolidated', 'assigned', 'in_transit', 'delivered', 'failed'];
            
            if (!in_array($status, $validStatuses)) {
                return [
                    'success' => false,
                    'error' => 'Invalid status. Must be one of: ' . implode(', ', $validStatuses)
                ];
            }

            $sql = "UPDATE consolidated_orders SET status = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$status, $consolidatedId]);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Consolidation status updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update consolidation status'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Status update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create consolidated orders table
     */
    private function createConsolidatedOrdersTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS consolidated_orders (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            delivery_date DATE NOT NULL,
            vendor_id BIGINT NOT NULL,
            delivery_time_slot VARCHAR(20) NOT NULL,
            delivery_address TEXT NOT NULL,
            delivery_latitude DECIMAL(10, 8),
            delivery_longitude DECIMAL(11, 8),
            total_orders INT NOT NULL,
            total_quantity INT NOT NULL,
            total_amount DECIMAL(12, 2) NOT NULL,
            customer_count INT NOT NULL,
            product_count INT NOT NULL,
            requires_cold_chain BOOLEAN DEFAULT FALSE,
            order_ids JSON NOT NULL,
            customer_ids JSON NOT NULL,
            product_summary JSON NOT NULL,
            consolidation_key VARCHAR(255) NOT NULL,
            status ENUM('consolidated', 'assigned', 'in_transit', 'delivered', 'failed') DEFAULT 'consolidated',
            assigned_driver_id BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_delivery_date (delivery_date),
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_status (status),
            INDEX idx_consolidation_key (consolidation_key)
        )";
        
        $this->db->exec($sql);
    }
}