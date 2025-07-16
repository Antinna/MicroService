<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Vendor dashboard service for performance metrics compilation
 */
class VendorDashboardService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Get comprehensive vendor dashboard data
     */
    public function getDashboardData(int $vendorId, string $dateFrom = null, string $dateTo = null): array
    {
        try {
            $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateTo ?? date('Y-m-d');

            $dashboard = [
                'vendor_id' => $vendorId,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'overview' => $this->getOverviewMetrics($vendorId, $dateFrom, $dateTo),
                'sales' => $this->getSalesMetrics($vendorId, $dateFrom, $dateTo),
                'products' => $this->getProductMetrics($vendorId, $dateFrom, $dateTo),
                'customers' => $this->getCustomerMetrics($vendorId, $dateFrom, $dateTo),
                'delivery' => $this->getDeliveryMetrics($vendorId, $dateFrom, $dateTo),
                'inventory' => $this->getInventoryMetrics($vendorId),
                'trends' => $this->getTrendAnalysis($vendorId, $dateFrom, $dateTo),
                'alerts' => $this->getAlerts($vendorId),
                'generated_at' => date('Y-m-d H:i:s')
            ];

            return [
                'success' => true,
                'dashboard' => $dashboard
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Dashboard data compilation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get overview metrics
     */
    private function getOverviewMetrics(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Total orders
            $sql = "SELECT COUNT(*) as total_orders,
                           SUM(total_amount) as total_revenue,
                           AVG(total_amount) as avg_order_value
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $orderStats = $stmt->fetch();

            // Active products
            $sql = "SELECT COUNT(*) as active_products FROM products WHERE vendor_id = ? AND status = 'active'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $productCount = $stmt->fetchColumn();

            // Active subscriptions
            $sql = "SELECT COUNT(*) as active_subscriptions FROM customer_subscriptions WHERE vendor_id = ? AND status = 'active'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $subscriptionCount = $stmt->fetchColumn();

            // Customer count
            $sql = "SELECT COUNT(DISTINCT customer_id) as unique_customers 
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $customerCount = $stmt->fetchColumn();

            return [
                'total_orders' => (int)$orderStats['total_orders'],
                'total_revenue' => round($orderStats['total_revenue'] ?? 0, 2),
                'avg_order_value' => round($orderStats['avg_order_value'] ?? 0, 2),
                'active_products' => (int)$productCount,
                'active_subscriptions' => (int)$subscriptionCount,
                'unique_customers' => (int)$customerCount
            ];

        } catch (Exception $e) {
            return [
                'total_orders' => 0,
                'total_revenue' => 0,
                'avg_order_value' => 0,
                'active_products' => 0,
                'active_subscriptions' => 0,
                'unique_customers' => 0
            ];
        }
    }

    /**
     * Get sales metrics
     */
    private function getSalesMetrics(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Daily sales
            $sql = "SELECT DATE(created_at) as sale_date,
                           COUNT(*) as orders,
                           SUM(total_amount) as revenue
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY sale_date";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $dailySales = $stmt->fetchAll();

            // Top selling products
            $sql = "SELECT p.name, p.id,
                           COUNT(*) as order_count,
                           SUM(JSON_EXTRACT(o.products, '$[*].quantity')) as total_quantity,
                           SUM(JSON_EXTRACT(o.products, '$[*].price') * JSON_EXTRACT(o.products, '$[*].quantity')) as revenue
                    FROM orders o
                    JOIN products p ON JSON_EXTRACT(o.products, '$[0].product_id') = p.id
                    WHERE o.vendor_id = ? AND DATE(o.created_at) BETWEEN ? AND ?
                    GROUP BY p.id
                    ORDER BY revenue DESC
                    LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $topProducts = $stmt->fetchAll();

            return [
                'daily_sales' => $dailySales,
                'top_products' => $topProducts,
                'sales_growth' => $this->calculateSalesGrowth($vendorId, $dateFrom, $dateTo)
            ];

        } catch (Exception $e) {
            return [
                'daily_sales' => [],
                'top_products' => [],
                'sales_growth' => 0
            ];
        }
    }

    /**
     * Get product metrics
     */
    private function getProductMetrics(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Product performance
            $sql = "SELECT 
                        COUNT(*) as total_products,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_products,
                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_products,
                        SUM(CASE WHEN is_perishable = 1 THEN 1 ELSE 0 END) as perishable_products
                    FROM products 
                    WHERE vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $productStats = $stmt->fetch();

            // Low performing products
            $sql = "SELECT p.name, p.id, COALESCE(order_count, 0) as order_count
                    FROM products p
                    LEFT JOIN (
                        SELECT JSON_EXTRACT(products, '$[0].product_id') as product_id,
                               COUNT(*) as order_count
                        FROM orders 
                        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
                        GROUP BY JSON_EXTRACT(products, '$[0].product_id')
                    ) o ON p.id = o.product_id
                    WHERE p.vendor_id = ? AND p.status = 'active'
                    ORDER BY order_count ASC
                    LIMIT 5";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo, $vendorId]);
            $lowPerformingProducts = $stmt->fetchAll();

            return [
                'product_stats' => $productStats,
                'low_performing_products' => $lowPerformingProducts
            ];

        } catch (Exception $e) {
            return [
                'product_stats' => [],
                'low_performing_products' => []
            ];
        }
    } 
   /**
     * Get customer metrics
     */
    private function getCustomerMetrics(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Customer acquisition
            $sql = "SELECT 
                        COUNT(DISTINCT customer_id) as total_customers,
                        COUNT(DISTINCT CASE WHEN DATE(created_at) BETWEEN ? AND ? THEN customer_id END) as new_customers
                    FROM orders 
                    WHERE vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dateFrom, $dateTo, $vendorId]);
            $customerStats = $stmt->fetch();

            // Top customers
            $sql = "SELECT customer_id,
                           COUNT(*) as order_count,
                           SUM(total_amount) as total_spent
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY customer_id
                    ORDER BY total_spent DESC
                    LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $topCustomers = $stmt->fetchAll();

            return [
                'customer_stats' => $customerStats,
                'top_customers' => $topCustomers,
                'retention_rate' => $this->calculateRetentionRate($vendorId, $dateFrom, $dateTo)
            ];

        } catch (Exception $e) {
            return [
                'customer_stats' => [],
                'top_customers' => [],
                'retention_rate' => 0
            ];
        }
    }

    /**
     * Get delivery metrics
     */
    private function getDeliveryMetrics(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Mock delivery metrics - would integrate with actual delivery system
            return [
                'on_time_delivery_rate' => 87.5,
                'average_delivery_time' => 45, // minutes
                'delivery_success_rate' => 94.2,
                'failed_deliveries' => 12,
                'total_deliveries' => 205
            ];

        } catch (Exception $e) {
            return [
                'on_time_delivery_rate' => 0,
                'average_delivery_time' => 0,
                'delivery_success_rate' => 0,
                'failed_deliveries' => 0,
                'total_deliveries' => 0
            ];
        }
    }

    /**
     * Get inventory metrics
     */
    private function getInventoryMetrics(int $vendorId): array
    {
        try {
            // Current inventory status
            $sql = "SELECT 
                        COUNT(*) as total_batches,
                        SUM(quantity) as total_quantity,
                        SUM(CASE WHEN expiry_date <= CURDATE() THEN 1 ELSE 0 END) as expired_batches,
                        SUM(CASE WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as expiring_soon
                    FROM inventory_batches ib
                    JOIN products p ON ib.product_id = p.id
                    WHERE p.vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $inventoryStats = $stmt->fetch();

            // Low stock products
            $sql = "SELECT p.name, p.id, SUM(ib.quantity) as current_stock
                    FROM products p
                    LEFT JOIN inventory_batches ib ON p.id = ib.product_id
                    WHERE p.vendor_id = ? AND p.status = 'active'
                    GROUP BY p.id
                    HAVING current_stock < 10 OR current_stock IS NULL
                    ORDER BY current_stock ASC
                    LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $lowStockProducts = $stmt->fetchAll();

            return [
                'inventory_stats' => $inventoryStats,
                'low_stock_products' => $lowStockProducts
            ];

        } catch (Exception $e) {
            return [
                'inventory_stats' => [],
                'low_stock_products' => []
            ];
        }
    }

    /**
     * Get trend analysis
     */
    private function getTrendAnalysis(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Weekly trends
            $sql = "SELECT 
                        WEEK(created_at) as week_number,
                        COUNT(*) as orders,
                        SUM(total_amount) as revenue
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY WEEK(created_at)
                    ORDER BY week_number";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $weeklyTrends = $stmt->fetchAll();

            return [
                'weekly_trends' => $weeklyTrends,
                'growth_rate' => $this->calculateGrowthRate($vendorId, $dateFrom, $dateTo)
            ];

        } catch (Exception $e) {
            return [
                'weekly_trends' => [],
                'growth_rate' => 0
            ];
        }
    }

    /**
     * Get alerts for vendor
     */
    private function getAlerts(int $vendorId): array
    {
        try {
            $alerts = [];

            // Low stock alerts
            $sql = "SELECT COUNT(*) FROM products p
                    LEFT JOIN inventory_batches ib ON p.id = ib.product_id
                    WHERE p.vendor_id = ? AND p.status = 'active'
                    GROUP BY p.id
                    HAVING SUM(ib.quantity) < 10 OR SUM(ib.quantity) IS NULL";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $lowStockCount = $stmt->rowCount();

            if ($lowStockCount > 0) {
                $alerts[] = [
                    'type' => 'low_stock',
                    'severity' => 'warning',
                    'message' => "{$lowStockCount} products are running low on stock",
                    'count' => $lowStockCount
                ];
            }

            // Expiring products
            $sql = "SELECT COUNT(*) FROM inventory_batches ib
                    JOIN products p ON ib.product_id = p.id
                    WHERE p.vendor_id = ? AND ib.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $expiringCount = $stmt->fetchColumn();

            if ($expiringCount > 0) {
                $alerts[] = [
                    'type' => 'expiring_products',
                    'severity' => 'high',
                    'message' => "{$expiringCount} product batches are expiring soon",
                    'count' => $expiringCount
                ];
            }

            return $alerts;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Calculate sales growth
     */
    private function calculateSalesGrowth(int $vendorId, string $dateFrom, string $dateTo): float
    {
        try {
            $currentPeriodDays = (new DateTime($dateTo))->diff(new DateTime($dateFrom))->days + 1;
            $previousDateFrom = date('Y-m-d', strtotime($dateFrom . " -{$currentPeriodDays} days"));
            $previousDateTo = date('Y-m-d', strtotime($dateFrom . " -1 day"));

            // Current period revenue
            $sql = "SELECT SUM(total_amount) as revenue FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $currentRevenue = $stmt->fetchColumn() ?? 0;

            // Previous period revenue
            $stmt->execute([$vendorId, $previousDateFrom, $previousDateTo]);
            $previousRevenue = $stmt->fetchColumn() ?? 0;

            if ($previousRevenue > 0) {
                return round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2);
            }

            return 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Calculate retention rate
     */
    private function calculateRetentionRate(int $vendorId, string $dateFrom, string $dateTo): float
    {
        try {
            // Mock calculation - would need more complex logic for actual retention
            return 78.5;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Calculate growth rate
     */
    private function calculateGrowthRate(int $vendorId, string $dateFrom, string $dateTo): float
    {
        try {
            // Mock calculation - would analyze trends over time
            return 12.3;

        } catch (Exception $e) {
            return 0;
        }
    }
}