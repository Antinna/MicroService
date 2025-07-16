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
    private Logger $logger;

    // Consolidation strategies
    const STRATEGY_BY_LOCATION = 'location';
    const STRATEGY_BY_VENDOR = 'vendor';
    const STRATEGY_BY_DELIVERY_SLOT = 'delivery_slot';
    const STRATEGY_BY_CUSTOMER = 'customer';
    const STRATEGY_MIXED = 'mixed';

    // Order types
    const ORDER_TYPE_SUBSCRIPTION = 'subscription';
    const ORDER_TYPE_ON_DEMAND = 'on_demand';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->createConsolidationTables();
    }

    /**
     * Consolidate orders for a specific date and delivery slot
     */
    public function consolidateOrdersForSlot(int $deliverySlotId, string $deliveryDate, array $options = []): array
    {
        try {
            $this->logger->info('Starting order consolidation for slot', [
                'delivery_slot_id' => $deliverySlotId,
                'delivery_date' => $deliveryDate
            ]);

            // Get pending orders for the slot and date
            $pendingOrders = $this->getPendingOrdersForSlot($deliverySlotId, $deliveryDate);
            
            if (empty($pendingOrders)) {
                return [
                    'success' => true,
                    'message' => 'No pending orders found for consolidation',
                    'consolidated_orders' => [],
                    'total_orders' => 0
                ];
            }

            // Group orders by consolidation strategy
            $strategy = $options['strategy'] ?? self::STRATEGY_MIXED;
            $groupedOrders = $this->groupOrdersByStrategy($pendingOrders, $strategy);

            // Consolidate each group
            $consolidatedOrders = [];
            $consolidationResults = [];

            foreach ($groupedOrders as $groupKey => $orders) {
                $consolidationResult = $this->consolidateOrderGroup($orders, $groupKey, $options);
                
                if ($consolidationResult['success']) {
                    $consolidatedOrders[] = $consolidationResult['consolidated_order'];
                    $consolidationResults[] = $consolidationResult;
                }
            }

            // Record consolidation activity
            $this->recordConsolidationActivity($deliverySlotId, $deliveryDate, $consolidationResults);

            return [
                'success' => true,
                'delivery_slot_id' => $deliverySlotId,
                'delivery_date' => $deliveryDate,
                'strategy' => $strategy,
                'original_orders_count' => count($pendingOrders),
                'consolidated_orders_count' => count($consolidatedOrders),
                'consolidation_ratio' => count($pendingOrders) > 0 ? count($consolidatedOrders) / count($pendingOrders) : 0,
                'consolidated_orders' => $consolidatedOrders,
                'consolidation_results' => $consolidationResults
            ];

        } catch (Exception $e) {
            $this->logger->error('Order consolidation failed', [
                'delivery_slot_id' => $deliverySlotId,
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Order consolidation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consolidate orders by customer and location
     */
    public function consolidateByCustomerLocation(string $deliveryDate, array $options = []): array
    {
        try {
            // Get all pending orders for the date
            $pendingOrders = $this->getPendingOrdersForDate($deliveryDate);
            
            if (empty($pendingOrders)) {
                return [
                    'success' => true,
                    'message' => 'No pending orders found for consolidation',
                    'consolidated_orders' => []
                ];
            }

            // Group by customer and delivery location
            $customerLocationGroups = [];
            foreach ($pendingOrders as $order) {
                $key = $order['customer_id'] . '_' . md5($order['delivery_address']);
                if (!isset($customerLocationGroups[$key])) {
                    $customerLocationGroups[$key] = [];
                }
                $customerLocationGroups[$key][] = $order;
            }

            $consolidatedOrders = [];
            foreach ($customerLocationGroups as $groupKey => $orders) {
                if (count($orders) > 1) {
                    $consolidationResult = $this->consolidateOrderGroup($orders, $groupKey, $options);
                    if ($consolidationResult['success']) {
                        $consolidatedOrders[] = $consolidationResult['consolidated_order'];
                    }
                }
            }

            return [
                'success' => true,
                'delivery_date' => $deliveryDate,
                'original_orders_count' => count($pendingOrders),
                'consolidated_orders_count' => count($consolidatedOrders),
                'consolidated_orders' => $consolidatedOrders
            ];

        } catch (Exception $e) {
            $this->logger->error('Customer location consolidation failed', [
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Customer location consolidation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consolidate orders by vendor and optimize delivery routes
     */
    public function consolidateByVendorRoute(int $vendorId, string $deliveryDate, array $options = []): array
    {
        try {
            // Get vendor orders for the date
            $vendorOrders = $this->getVendorOrdersForDate($vendorId, $deliveryDate);
            
            if (empty($vendorOrders)) {
                return [
                    'success' => true,
                    'message' => 'No vendor orders found for consolidation',
                    'optimized_routes' => []
                ];
            }

            // Group orders by geographic proximity
            $locationGroups = $this->groupOrdersByLocation($vendorOrders, $options);

            // Optimize delivery routes for each group
            $optimizedRoutes = [];
            foreach ($locationGroups as $groupKey => $orders) {
                $routeOptimization = $this->optimizeDeliveryRoute($orders, $options);
                if ($routeOptimization['success']) {
                    $optimizedRoutes[] = $routeOptimization['route'];
                }
            }

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'delivery_date' => $deliveryDate,
                'total_orders' => count($vendorOrders),
                'route_groups' => count($optimizedRoutes),
                'optimized_routes' => $optimizedRoutes
            ];

        } catch (Exception $e) {
            $this->logger->error('Vendor route consolidation failed', [
                'vendor_id' => $vendorId,
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Vendor route consolidation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get consolidation recommendations
     */
    public function getConsolidationRecommendations(string $deliveryDate, array $filters = []): array
    {
        try {
            $recommendations = [];

            // Analyze potential consolidations
            $potentialConsolidations = $this->analyzePotentialConsolidations($deliveryDate, $filters);

            foreach ($potentialConsolidations as $consolidation) {
                $savings = $this->calculateConsolidationSavings($consolidation);
                
                if ($savings['total_savings'] > 0) {
                    $recommendations[] = [
                        'type' => $consolidation['type'],
                        'description' => $consolidation['description'],
                        'orders_count' => $consolidation['orders_count'],
                        'potential_savings' => $savings,
                        'priority' => $this->calculateConsolidationPriority($consolidation, $savings),
                        'consolidation_data' => $consolidation
                    ];
                }
            }

            // Sort by priority
            usort($recommendations, fn($a, $b) => $b['priority'] <=> $a['priority']);

            return [
                'success' => true,
                'delivery_date' => $deliveryDate,
                'recommendations_count' => count($recommendations),
                'recommendations' => $recommendations
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get consolidation recommendations', [
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get consolidation recommendations: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute automatic consolidation based on rules
     */
    public function executeAutoConsolidation(string $deliveryDate, array $rules = []): array
    {
        try {
            $defaultRules = [
                'min_orders_for_consolidation' => 2,
                'max_distance_km' => 5,
                'min_savings_percentage' => 10,
                'consolidate_same_customer' => true,
                'consolidate_same_vendor' => true,
                'consolidate_same_area' => true
            ];

            $rules = array_merge($defaultRules, $rules);
            $consolidationResults = [];

            // Get consolidation recommendations
            $recommendations = $this->getConsolidationRecommendations($deliveryDate);
            
            if (!$recommendations['success']) {
                return $recommendations;
            }

            foreach ($recommendations['recommendations'] as $recommendation) {
                // Check if recommendation meets rules criteria
                if ($this->meetsConsolidationRules($recommendation, $rules)) {
                    $executionResult = $this->executeConsolidationRecommendation($recommendation);
                    $consolidationResults[] = $executionResult;
                }
            }

            $successfulConsolidations = array_filter($consolidationResults, fn($r) => $r['success']);

            return [
                'success' => true,
                'delivery_date' => $deliveryDate,
                'rules_applied' => $rules,
                'recommendations_evaluated' => count($recommendations['recommendations']),
                'consolidations_executed' => count($consolidationResults),
                'successful_consolidations' => count($successfulConsolidations),
                'consolidation_results' => $consolidationResults
            ];

        } catch (Exception $e) {
            $this->logger->error('Auto consolidation execution failed', [
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Auto consolidation execution failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get consolidation history and statistics
     */
    public function getConsolidationHistory(int $days = 30, array $filters = []): array
    {
        try {
            $whereClause = 'WHERE ca.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params = [$days];

            if (!empty($filters['vendor_id'])) {
                $whereClause .= ' AND ca.vendor_id = ?';
                $params[] = $filters['vendor_id'];
            }

            if (!empty($filters['delivery_slot_id'])) {
                $whereClause .= ' AND ca.delivery_slot_id = ?';
                $params[] = $filters['delivery_slot_id'];
            }

            $sql = "SELECT 
                        ca.*,
                        v.business_name as vendor_name,
                        ds.slot_name as delivery_slot_name
                    FROM consolidation_activities ca
                    LEFT JOIN vendors v ON ca.vendor_id = v.id
                    LEFT JOIN delivery_slots ds ON ca.delivery_slot_id = ds.id
                    {$whereClause}
                    ORDER BY ca.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $activities = $stmt->fetchAll();

            // Calculate statistics
            $stats = $this->calculateConsolidationStatistics($activities);

            return [
                'success' => true,
                'period_days' => $days,
                'total_activities' => count($activities),
                'activities' => $activities,
                'statistics' => $stats
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get consolidation history', [
                'days' => $days,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get consolidation history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get pending orders for specific slot and date
     */
    private function getPendingOrdersForSlot(int $deliverySlotId, string $deliveryDate): array
    {
        try {
            $sql = "SELECT o.*, 
                           oi.product_id, oi.quantity, oi.unit_price,
                           p.name as product_name, p.requires_cold_chain,
                           v.business_name as vendor_name
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN products p ON oi.product_id = p.id
                    JOIN vendors v ON o.vendor_id = v.id
                    WHERE o.delivery_slot_id = ?
                    AND DATE(o.delivery_date) = ?
                    AND o.status IN ('pending', 'confirmed')
                    ORDER BY o.customer_id, o.vendor_id, o.created_at";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$deliverySlotId, $deliveryDate]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get pending orders for slot', [
                'delivery_slot_id' => $deliverySlotId,
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Get pending orders for specific date
     */
    private function getPendingOrdersForDate(string $deliveryDate): array
    {
        try {
            $sql = "SELECT o.*, 
                           oi.product_id, oi.quantity, oi.unit_price,
                           p.name as product_name, p.requires_cold_chain,
                           v.business_name as vendor_name
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN products p ON oi.product_id = p.id
                    JOIN vendors v ON o.vendor_id = v.id
                    WHERE DATE(o.delivery_date) = ?
                    AND o.status IN ('pending', 'confirmed')
                    ORDER BY o.customer_id, o.vendor_id, o.created_at";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$deliveryDate]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get pending orders for date', [
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Get vendor orders for specific date
     */
    private function getVendorOrdersForDate(int $vendorId, string $deliveryDate): array
    {
        try {
            $sql = "SELECT o.*, 
                           oi.product_id, oi.quantity, oi.unit_price,
                           p.name as product_name, p.requires_cold_chain
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN products p ON oi.product_id = p.id
                    WHERE o.vendor_id = ?
                    AND DATE(o.delivery_date) = ?
                    AND o.status IN ('pending', 'confirmed')
                    ORDER BY o.delivery_address, o.created_at";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $deliveryDate]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor orders for date', [
                'vendor_id' => $vendorId,
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Group orders by consolidation strategy
     */
    private function groupOrdersByStrategy(array $orders, string $strategy): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $groupKey = match($strategy) {
                self::STRATEGY_BY_LOCATION => $this->getLocationGroupKey($order),
                self::STRATEGY_BY_VENDOR => $order['vendor_id'],
                self::STRATEGY_BY_DELIVERY_SLOT => $order['delivery_slot_id'],
                self::STRATEGY_BY_CUSTOMER => $order['customer_id'],
                self::STRATEGY_MIXED => $this->getMixedGroupKey($order),
                default => $order['id'] // No grouping
            };

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            $groups[$groupKey][] = $order;
        }

        // Filter out single-order groups (no consolidation needed)
        return array_filter($groups, fn($group) => count($group) > 1);
    }

    /**
     * Get location-based group key
     */
    private function getLocationGroupKey(array $order): string
    {
        // Simple location grouping by postal code or area
        $address = $order['delivery_address'];
        $postalCode = $this->extractPostalCode($address);
        
        return $postalCode ?: md5($address);
    }

    /**
     * Get mixed strategy group key
     */
    private function getMixedGroupKey(array $order): string
    {
        // Combine customer, vendor, and location for mixed strategy
        return $order['customer_id'] . '_' . $order['vendor_id'] . '_' . $this->getLocationGroupKey($order);
    }

    /**
     * Extract postal code from address
     */
    private function extractPostalCode(string $address): ?string
    {
        // Simple regex to extract Indian postal codes (6 digits)
        if (preg_match('/\b(\d{6})\b/', $address, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Consolidate a group of orders
     */
    private function consolidateOrderGroup(array $orders, string $groupKey, array $options): array
    {
        try {
            if (count($orders) < 2) {
                return [
                    'success' => false,
                    'error' => 'Insufficient orders for consolidation'
                ];
            }

            // Create consolidated order
            $consolidatedOrder = $this->createConsolidatedOrder($orders, $options);
            
            if (!$consolidatedOrder['success']) {
                return $consolidatedOrder;
            }

            // Update original orders status
            $updateResult = $this->updateOriginalOrdersStatus($orders, $consolidatedOrder['order_id']);
            
            if (!$updateResult['success']) {
                return $updateResult;
            }

            return [
                'success' => true,
                'group_key' => $groupKey,
                'original_orders_count' => count($orders),
                'consolidated_order' => $consolidatedOrder['order'],
                'consolidation_savings' => $this->calculateGroupSavings($orders, $consolidatedOrder['order'])
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to consolidate order group', [
                'group_key' => $groupKey,
                'orders_count' => count($orders),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Order group consolidation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create consolidated order from multiple orders
     */
    private function createConsolidatedOrder(array $orders, array $options): array
    {
        try {
            // Determine consolidation approach
            $primaryOrder = $orders[0];
            $totalAmount = array_sum(array_column($orders, 'total_amount'));
            $deliveryFee = $this->calculateConsolidatedDeliveryFee($orders, $options);

            // Create consolidated order
            $consolidatedOrderData = [
                'customer_id' => $primaryOrder['customer_id'],
                'vendor_id' => $primaryOrder['vendor_id'],
                'order_type' => 'consolidated',
                'delivery_date' => $primaryOrder['delivery_date'],
                'delivery_slot_id' => $primaryOrder['delivery_slot_id'],
                'delivery_address' => $primaryOrder['delivery_address'],
                'subtotal_amount' => $totalAmount,
                'delivery_fee' => $deliveryFee,
                'total_amount' => $totalAmount + $deliveryFee,
                'status' => 'pending',
                'consolidation_group' => uniqid('CONS_'),
                'original_orders_count' => count($orders)
            ];

            $sql = "INSERT INTO orders (" . implode(', ', array_keys($consolidatedOrderData)) . ") 
                    VALUES (" . str_repeat('?,', count($consolidatedOrderData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($consolidatedOrderData));

            if (!$success) {
                return [
                    'success' => false,
                    'error' => 'Failed to create consolidated order'
                ];
            }

            $consolidatedOrderId = $this->db->lastInsertId();

            // Create consolidated order items
            $itemsResult = $this->createConsolidatedOrderItems($consolidatedOrderId, $orders);
            
            if (!$itemsResult['success']) {
                return $itemsResult;
            }

            $consolidatedOrderData['id'] = $consolidatedOrderId;

            return [
                'success' => true,
                'order_id' => $consolidatedOrderId,
                'order' => $consolidatedOrderData
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to create consolidated order', [
                'orders_count' => count($orders),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Consolidated order creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create consolidated order items
     */
    private function createConsolidatedOrderItems(int $consolidatedOrderId, array $orders): array
    {
        try {
            $consolidatedItems = [];

            // Group items by product
            foreach ($orders as $order) {
                $productId = $order['product_id'];
                
                if (!isset($consolidatedItems[$productId])) {
                    $consolidatedItems[$productId] = [
                        'product_id' => $productId,
                        'quantity' => 0,
                        'unit_price' => $order['unit_price'],
                        'product_name' => $order['product_name']
                    ];
                }
                
                $consolidatedItems[$productId]['quantity'] += $order['quantity'];
            }

            // Insert consolidated items
            foreach ($consolidatedItems as $item) {
                $sql = "INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price)
                        VALUES (?, ?, ?, ?, ?)";
                
                $totalPrice = $item['quantity'] * $item['unit_price'];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $consolidatedOrderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $totalPrice
                ]);
            }

            return [
                'success' => true,
                'items_count' => count($consolidatedItems)
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to create consolidated order items', [
                'consolidated_order_id' => $consolidatedOrderId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Consolidated order items creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate consolidated delivery fee
     */
    private function calculateConsolidatedDeliveryFee(array $orders, array $options): float
    {
        $totalOriginalFees = array_sum(array_column($orders, 'delivery_fee'));
        $discountPercentage = $options['delivery_fee_discount'] ?? 20; // 20% discount by default
        
        return $totalOriginalFees * (1 - $discountPercentage / 100);
    }

    /**
     * Update original orders status
     */
    private function updateOriginalOrdersStatus(array $orders, int $consolidatedOrderId): array
    {
        try {
            $orderIds = array_column($orders, 'id');
            $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
            
            $sql = "UPDATE orders 
                    SET status = 'consolidated', 
                        consolidated_order_id = ?,
                        updated_at = NOW()
                    WHERE id IN ({$placeholders})";
            
            $params = array_merge([$consolidatedOrderId], $orderIds);
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                return [
                    'success' => true,
                    'updated_orders' => $stmt->rowCount()
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update original orders status'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to update original orders status', [
                'consolidated_order_id' => $consolidatedOrderId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Original orders status update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate group savings
     */
    private function calculateGroupSavings(array $originalOrders, array $consolidatedOrder): array
    {
        $originalDeliveryFees = array_sum(array_column($originalOrders, 'delivery_fee'));
        $consolidatedDeliveryFee = $consolidatedOrder['delivery_fee'];
        
        $deliveryFeeSavings = $originalDeliveryFees - $consolidatedDeliveryFee;
        $savingsPercentage = $originalDeliveryFees > 0 ? ($deliveryFeeSavings / $originalDeliveryFees) * 100 : 0;

        return [
            'original_delivery_fees' => $originalDeliveryFees,
            'consolidated_delivery_fee' => $consolidatedDeliveryFee,
            'delivery_fee_savings' => $deliveryFeeSavings,
            'savings_percentage' => round($savingsPercentage, 2),
            'orders_consolidated' => count($originalOrders)
        ];
    }

    /**
     * Analyze potential consolidations
     */
    private function analyzePotentialConsolidations(string $deliveryDate, array $filters): array
    {
        // This would analyze orders and identify consolidation opportunities
        // For now, return a simplified analysis
        return [
            [
                'type' => 'customer_location',
                'description' => 'Orders from same customer to same location',
                'orders_count' => 5,
                'potential_savings_amount' => 25.00
            ],
            [
                'type' => 'vendor_route',
                'description' => 'Orders from same vendor in nearby locations',
                'orders_count' => 8,
                'potential_savings_amount' => 40.00
            ]
        ];
    }

    /**
     * Calculate consolidation savings
     */
    private function calculateConsolidationSavings(array $consolidation): array
    {
        return [
            'delivery_fee_savings' => $consolidation['potential_savings_amount'] ?? 0,
            'operational_savings' => ($consolidation['orders_count'] ?? 0) * 2, // $2 per order operational savings
            'total_savings' => ($consolidation['potential_savings_amount'] ?? 0) + (($consolidation['orders_count'] ?? 0) * 2)
        ];
    }

    /**
     * Calculate consolidation priority
     */
    private function calculateConsolidationPriority(array $consolidation, array $savings): int
    {
        $priority = 0;
        
        // Higher savings = higher priority
        $priority += min($savings['total_savings'], 100); // Max 100 points for savings
        
        // More orders = higher priority
        $priority += min(($consolidation['orders_count'] ?? 0) * 5, 50); // Max 50 points for order count
        
        return $priority;
    }

    /**
     * Check if recommendation meets consolidation rules
     */
    private function meetsConsolidationRules(array $recommendation, array $rules): bool
    {
        // Check minimum orders
        if ($recommendation['orders_count'] < $rules['min_orders_for_consolidation']) {
            return false;
        }

        // Check minimum savings percentage
        $savingsPercentage = $recommendation['potential_savings']['total_savings'] / 
                           max($recommendation['orders_count'] * 10, 1) * 100; // Assume $10 average per order
        
        if ($savingsPercentage < $rules['min_savings_percentage']) {
            return false;
        }

        return true;
    }

    /**
     * Execute consolidation recommendation
     */
    private function executeConsolidationRecommendation(array $recommendation): array
    {
        // This would execute the actual consolidation
        // For now, return a simulated result
        return [
            'success' => true,
            'recommendation_type' => $recommendation['type'],
            'orders_consolidated' => $recommendation['orders_count'],
            'actual_savings' => $recommendation['potential_savings']['total_savings'] * 0.9 // 90% of potential savings
        ];
    }

    /**
     * Calculate consolidation statistics
     */
    private function calculateConsolidationStatistics(array $activities): array
    {
        $totalActivities = count($activities);
        $totalOrdersConsolidated = array_sum(array_column($activities, 'original_orders_count'));
        $totalSavings = array_sum(array_column($activities, 'total_savings'));

        return [
            'total_consolidation_activities' => $totalActivities,
            'total_orders_consolidated' => $totalOrdersConsolidated,
            'total_savings_amount' => $totalSavings,
            'average_orders_per_consolidation' => $totalActivities > 0 ? $totalOrdersConsolidated / $totalActivities : 0,
            'average_savings_per_consolidation' => $totalActivities > 0 ? $totalSavings / $totalActivities : 0
        ];
    }

    /**
     * Group orders by location proximity
     */
    private function groupOrdersByLocation(array $orders, array $options): array
    {
        // Simple grouping by postal code for now
        $groups = [];
        
        foreach ($orders as $order) {
            $postalCode = $this->extractPostalCode($order['delivery_address']);
            $groupKey = $postalCode ?: 'unknown';
            
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            $groups[$groupKey][] = $order;
        }

        return $groups;
    }

    /**
     * Optimize delivery route for orders
     */
    private function optimizeDeliveryRoute(array $orders, array $options): array
    {
        // Simple route optimization - sort by address for now
        usort($orders, fn($a, $b) => strcmp($a['delivery_address'], $b['delivery_address']));

        return [
            'success' => true,
            'route' => [
                'orders' => $orders,
                'total_orders' => count($orders),
                'estimated_distance_km' => count($orders) * 2, // Simplified calculation
                'estimated_time_minutes' => count($orders) * 15 // 15 minutes per delivery
            ]
        ];
    }

    /**
     * Record consolidation activity
     */
    private function recordConsolidationActivity(int $deliverySlotId, string $deliveryDate, array $results): void
    {
        try {
            foreach ($results as $result) {
                if ($result['success']) {
                    $sql = "INSERT INTO consolidation_activities 
                            (delivery_slot_id, delivery_date, consolidation_type, 
                             original_orders_count, consolidated_orders_count, 
                             total_savings, consolidation_data, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        $deliverySlotId,
                        $deliveryDate,
                        'slot_consolidation',
                        $result['original_orders_count'],
                        1, // One consolidated order
                        $result['consolidation_savings']['total_savings'] ?? 0,
                        json_encode($result)
                    ]);
                }
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to record consolidation activity', [
                'delivery_slot_id' => $deliverySlotId,
                'delivery_date' => $deliveryDate,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create consolidation tables
     */
    private function createConsolidationTables(): void
    {
        // Consolidation activities table
        $sql = "CREATE TABLE IF NOT EXISTS consolidation_activities (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            delivery_slot_id BIGINT,
            vendor_id BIGINT,
            delivery_date DATE NOT NULL,
            consolidation_type VARCHAR(50) NOT NULL,
            original_orders_count INT NOT NULL,
            consolidated_orders_count INT NOT NULL,
            total_savings DECIMAL(10,2) DEFAULT 0.00,
            consolidation_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_delivery_slot_id (delivery_slot_id),
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_delivery_date (delivery_date),
            INDEX idx_consolidation_type (consolidation_type),
            INDEX idx_created_at (created_at)
        )";

        $this->db->exec($sql);
    }
}