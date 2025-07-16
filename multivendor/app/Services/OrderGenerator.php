<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Automated order generation service for subscriptions
 */
class OrderGenerator
{
    private PDO $db;
    private SubscriptionManager $subscriptionManager;
    private InventoryTracker $inventoryTracker;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->subscriptionManager = new SubscriptionManager();
        $this->inventoryTracker = new InventoryTracker();
    }

    /**
     * Generate subscription orders for a specific date
     */
    public function generateSubscriptionOrders(string $date = null, int $vendorId = null): array
    {
        try {
            $targetDate = $date ?? date('Y-m-d');
            $startTime = microtime(true);

            // Get active subscriptions due for order generation
            $subscriptions = $this->getSubscriptionsDueForOrders($targetDate, $vendorId);
            
            $results = [
                'target_date' => $targetDate,
                'vendor_id' => $vendorId,
                'total_subscriptions_processed' => count($subscriptions),
                'orders_generated' => 0,
                'orders_failed' => 0,
                'capacity_issues' => 0,
                'inventory_issues' => 0,
                'order_details' => [],
                'errors' => []
            ];

            foreach ($subscriptions as $subscription) {
                $orderResult = $this->generateOrderForSubscription($subscription, $targetDate);
                
                if ($orderResult['success']) {
                    $results['orders_generated']++;
                    $results['order_details'][] = $orderResult;
                } else {
                    $results['orders_failed']++;
                    $results['errors'][] = [
                        'subscription_id' => $subscription['id'],
                        'error' => $orderResult['error']
                    ];

                    // Categorize errors
                    if (strpos($orderResult['error'], 'capacity') !== false) {
                        $results['capacity_issues']++;
                    } elseif (strpos($orderResult['error'], 'inventory') !== false) {
                        $results['inventory_issues']++;
                    }
                }
            }

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000); // milliseconds

            return [
                'success' => true,
                'message' => "Generated {$results['orders_generated']} orders from {$results['total_subscriptions_processed']} subscriptions",
                'execution_time_ms' => $executionTime,
                'results' => $results
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Order generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate order for a specific subscription
     */
    public function generateOrderForSubscription(array $subscription, string $targetDate): array
    {
        try {
            // Check vendor capacity
            $capacityCheck = $this->checkVendorCapacity($subscription['vendor_id'], $targetDate);
            if (!$capacityCheck['available']) {
                return [
                    'success' => false,
                    'error' => 'Vendor capacity exceeded for date: ' . $targetDate
                ];
            }

            // Decode subscription products
            $products = json_decode($subscription['products'], true);
            if (!$products) {
                return [
                    'success' => false,
                    'error' => 'Invalid subscription products data'
                ];
            }

            // Check inventory availability
            $inventoryCheck = $this->checkInventoryAvailability($products, $subscription['vendor_id']);
            if (!$inventoryCheck['available']) {
                return [
                    'success' => false,
                    'error' => 'Insufficient inventory: ' . implode(', ', $inventoryCheck['unavailable_products'])
                ];
            }

            // Create order
            $orderData = [
                'subscription_id' => $subscription['id'],
                'customer_id' => $subscription['customer_id'],
                'vendor_id' => $subscription['vendor_id'],
                'order_type' => 'subscription',
                'products' => $products,
                'delivery_date' => $targetDate,
                'delivery_preferences' => json_decode($subscription['delivery_preferences'], true),
                'total_amount' => $this->calculateOrderTotal($products),
                'status' => 'confirmed',
                'generated_at' => date('Y-m-d H:i:s')
            ];

            $orderId = $this->createOrder($orderData);

            if ($orderId) {
                // Reserve inventory
                $this->reserveInventoryForOrder($orderId, $products);

                // Update subscription last order date
                $this->updateSubscriptionLastOrder($subscription['id'], $targetDate);

                // Update vendor capacity
                $this->updateVendorCapacity($subscription['vendor_id'], $targetDate, count($products));

                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'subscription_id' => $subscription['id'],
                    'customer_id' => $subscription['customer_id'],
                    'vendor_id' => $subscription['vendor_id'],
                    'total_amount' => $orderData['total_amount'],
                    'product_count' => count($products),
                    'delivery_date' => $targetDate
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create order record'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Order generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get subscriptions due for order generation
     */
    private function getSubscriptionsDueForOrders(string $targetDate, ?int $vendorId = null): array
    {
        try {
            $vendorCondition = $vendorId ? 'AND vendor_id = ?' : '';
            $params = [$targetDate];
            if ($vendorId) {
                $params[] = $vendorId;
            }

            $sql = "SELECT s.* 
                    FROM customer_subscriptions s
                    WHERE s.status = 'active'
                    AND s.start_date <= ?
                    AND (s.end_date IS NULL OR s.end_date >= ?)
                    AND (
                        (s.delivery_frequency = 'daily') OR
                        (s.delivery_frequency = 'weekly' AND DAYOFWEEK(?) = s.delivery_day) OR
                        (s.delivery_frequency = 'monthly' AND DAY(?) = s.delivery_day)
                    )
                    {$vendorCondition}
                    ORDER BY s.vendor_id, s.created_at";

            // Add target date for frequency checks
            array_splice($params, 1, 0, [$targetDate, $targetDate, $targetDate]);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Check vendor capacity for date
     */
    private function checkVendorCapacity(int $vendorId, string $date): array
    {
        try {
            // Get vendor's daily capacity
            $sql = "SELECT daily_order_capacity FROM vendors WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $vendor = $stmt->fetch();

            if (!$vendor) {
                return ['available' => false, 'reason' => 'Vendor not found'];
            }

            $dailyCapacity = $vendor['daily_order_capacity'] ?? 100; // Default capacity

            // Get current orders for the date
            $sql = "SELECT COUNT(*) as current_orders 
                    FROM orders 
                    WHERE vendor_id = ? AND delivery_date = ? AND status != 'cancelled'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $date]);
            $result = $stmt->fetch();

            $currentOrders = $result['current_orders'] ?? 0;
            $available = $currentOrders < $dailyCapacity;

            return [
                'available' => $available,
                'daily_capacity' => $dailyCapacity,
                'current_orders' => $currentOrders,
                'remaining_capacity' => $dailyCapacity - $currentOrders
            ];

        } catch (Exception $e) {
            return ['available' => false, 'reason' => 'Capacity check failed'];
        }
    }

    /**
     * Check inventory availability for products
     */
    private function checkInventoryAvailability(array $products, int $vendorId): array
    {
        try {
            $unavailableProducts = [];
            $availableProducts = [];

            foreach ($products as $product) {
                $productId = $product['product_id'];
                $requiredQuantity = $product['quantity'];

                // Get current stock level
                $stockResult = $this->inventoryTracker->getStockLevel($productId);
                
                if ($stockResult['success']) {
                    $availableStock = $stockResult['available_stock'];
                    
                    if ($availableStock >= $requiredQuantity) {
                        $availableProducts[] = $product;
                    } else {
                        $unavailableProducts[] = [
                            'product_id' => $productId,
                            'product_name' => $product['name'] ?? "Product {$productId}",
                            'required' => $requiredQuantity,
                            'available' => $availableStock
                        ];
                    }
                } else {
                    $unavailableProducts[] = [
                        'product_id' => $productId,
                        'product_name' => $product['name'] ?? "Product {$productId}",
                        'error' => 'Stock check failed'
                    ];
                }
            }

            return [
                'available' => empty($unavailableProducts),
                'available_products' => $availableProducts,
                'unavailable_products' => $unavailableProducts
            ];

        } catch (Exception $e) {
            return [
                'available' => false,
                'error' => 'Inventory check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate order total amount
     */
    private function calculateOrderTotal(array $products): float
    {
        $total = 0.0;

        foreach ($products as $product) {
            $price = $product['price'] ?? 0;
            $quantity = $product['quantity'] ?? 1;
            $total += $price * $quantity;
        }

        return round($total, 2);
    }

    /**
     * Create order record
     */
    private function createOrder(array $orderData): ?int
    {
        try {
            $sql = "INSERT INTO orders (
                        subscription_id, customer_id, vendor_id, order_type,
                        products, delivery_date, delivery_preferences,
                        total_amount, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $orderData['subscription_id'],
                $orderData['customer_id'],
                $orderData['vendor_id'],
                $orderData['order_type'],
                json_encode($orderData['products']),
                $orderData['delivery_date'],
                json_encode($orderData['delivery_preferences']),
                $orderData['total_amount'],
                $orderData['status']
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Reserve inventory for order
     */
    private function reserveInventoryForOrder(int $orderId, array $products): void
    {
        try {
            foreach ($products as $product) {
                $productId = $product['product_id'];
                $quantity = $product['quantity'];

                // Update stock levels
                $this->inventoryTracker->updateStock(
                    $productId,
                    -$quantity,
                    "Reserved for order #{$orderId}",
                    null
                );
            }

        } catch (Exception $e) {
            // Log error but don't fail the order creation
            error_log("Failed to reserve inventory for order {$orderId}: " . $e->getMessage());
        }
    }

    /**
     * Update subscription last order date
     */
    private function updateSubscriptionLastOrder(int $subscriptionId, string $orderDate): void
    {
        try {
            $sql = "UPDATE customer_subscriptions 
                    SET last_order_date = ?, updated_at = NOW() 
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderDate, $subscriptionId]);

        } catch (Exception $e) {
            // Log error but don't fail
            error_log("Failed to update subscription last order date: " . $e->getMessage());
        }
    }

    /**
     * Update vendor capacity tracking
     */
    private function updateVendorCapacity(int $vendorId, string $date, int $orderCount): void
    {
        try {
            // This could be implemented to track daily capacity usage
            // For now, we'll just log it
            error_log("Vendor {$vendorId} processed {$orderCount} orders for {$date}");

        } catch (Exception $e) {
            // Log error but don't fail
            error_log("Failed to update vendor capacity: " . $e->getMessage());
        }
    }

    /**
     * Get order generation statistics
     */
    public function getOrderGenerationStats(string $dateFrom = null, string $dateTo = null, int $vendorId = null): array
    {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateTo ?? date('Y-m-d');

            $vendorCondition = $vendorId ? 'AND vendor_id = ?' : '';
            $params = [$dateFrom, $dateTo];
            if ($vendorId) {
                $params[] = $vendorId;
            }

            $sql = "SELECT 
                        DATE(created_at) as order_date,
                        vendor_id,
                        COUNT(*) as orders_generated,
                        SUM(total_amount) as total_revenue,
                        AVG(total_amount) as avg_order_value
                    FROM orders 
                    WHERE order_type = 'subscription'
                    AND DATE(created_at) BETWEEN ? AND ?
                    {$vendorCondition}
                    GROUP BY DATE(created_at), vendor_id
                    ORDER BY order_date DESC, vendor_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $dailyStats = $stmt->fetchAll();

            // Calculate summary statistics
            $totalOrders = array_sum(array_column($dailyStats, 'orders_generated'));
            $totalRevenue = array_sum(array_column($dailyStats, 'total_revenue'));
            $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

            return [
                'success' => true,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'vendor_id' => $vendorId,
                'summary' => [
                    'total_orders' => $totalOrders,
                    'total_revenue' => round($totalRevenue, 2),
                    'avg_order_value' => round($avgOrderValue, 2),
                    'days_with_orders' => count($dailyStats)
                ],
                'daily_stats' => $dailyStats
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get order generation stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get failed order generation attempts
     */
    public function getFailedOrderAttempts(string $date = null, int $vendorId = null): array
    {
        try {
            $targetDate = $date ?? date('Y-m-d');
            
            // This would typically be stored in a separate failed_orders table
            // For now, we'll return a mock structure
            return [
                'success' => true,
                'date' => $targetDate,
                'vendor_id' => $vendorId,
                'failed_attempts' => [
                    // Mock data structure
                    [
                        'subscription_id' => 1,
                        'customer_id' => 1,
                        'vendor_id' => 1,
                        'failure_reason' => 'Insufficient inventory',
                        'attempted_at' => date('Y-m-d H:i:s'),
                        'products' => [
                            ['product_id' => 1, 'quantity' => 2, 'available' => 1]
                        ]
                    ]
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get failed order attempts: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Retry failed order generation
     */
    public function retryFailedOrders(string $date = null, int $vendorId = null): array
    {
        try {
            $targetDate = $date ?? date('Y-m-d');
            
            // Get failed attempts
            $failedAttempts = $this->getFailedOrderAttempts($targetDate, $vendorId);
            
            if (!$failedAttempts['success']) {
                return $failedAttempts;
            }

            $results = [
                'total_retries' => 0,
                'successful_retries' => 0,
                'failed_retries' => 0,
                'retry_results' => []
            ];

            foreach ($failedAttempts['failed_attempts'] as $attempt) {
                // Get subscription data
                $subscription = $this->getSubscriptionById($attempt['subscription_id']);
                
                if ($subscription) {
                    $retryResult = $this->generateOrderForSubscription($subscription, $targetDate);
                    
                    $results['total_retries']++;
                    $results['retry_results'][] = $retryResult;
                    
                    if ($retryResult['success']) {
                        $results['successful_retries']++;
                    } else {
                        $results['failed_retries']++;
                    }
                }
            }

            return [
                'success' => true,
                'message' => "Retried {$results['total_retries']} failed orders, {$results['successful_retries']} succeeded",
                'results' => $results
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to retry orders: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get subscription by ID
     */
    private function getSubscriptionById(int $subscriptionId): ?array
    {
        try {
            $sql = "SELECT * FROM customer_subscriptions WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$subscriptionId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Schedule order generation job
     */
    public function scheduleOrderGeneration(string $date, int $vendorId = null, string $time = '02:00'): array
    {
        try {
            $cronManager = new CronJobManager();
            
            $jobConfig = [
                'name' => 'generate_orders_' . $date . ($vendorId ? "_vendor_{$vendorId}" : ''),
                'description' => "Generate subscription orders for {$date}" . ($vendorId ? " (Vendor {$vendorId})" : ''),
                'schedule' => $this->convertTimeToSchedule($time, $date),
                'command' => 'generate_subscription_orders',
                'parameters' => [
                    'date' => $date,
                    'vendor_id' => $vendorId
                ],
                'enabled' => true,
                'max_execution_time' => 1800, // 30 minutes
                'retry_attempts' => 2
            ];

            $result = $cronManager->registerJob($jobConfig);

            return [
                'success' => $result['success'],
                'message' => $result['success'] ? 
                    'Order generation scheduled successfully' : 
                    $result['error'],
                'job_id' => $result['job_id'] ?? null,
                'scheduled_time' => $time,
                'target_date' => $date
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to schedule order generation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Convert time to cron schedule
     */
    private function convertTimeToSchedule(string $time, string $date): string
    {
        $timeParts = explode(':', $time);
        $hour = $timeParts[0];
        $minute = $timeParts[1] ?? '00';
        
        // For specific date, create a one-time schedule
        $dateObj = new DateTime($date);
        $day = $dateObj->format('j');
        $month = $dateObj->format('n');
        
        return "{$minute} {$hour} {$day} {$month} *";
    }
}