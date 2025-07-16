<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Automated report generation service
 */
class ReportGenerator
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Generate comprehensive vendor report
     */
    public function generateReport(int $vendorId, string $reportType = 'comprehensive', array $dateRange = []): array
    {
        try {
            $dateFrom = $dateRange['from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $dateRange['to'] ?? date('Y-m-d');

            switch ($reportType) {
                case 'sales':
                    return $this->generateSalesReport($vendorId, $dateFrom, $dateTo);
                case 'inventory':
                    return $this->generateInventoryReport($vendorId);
                case 'customer_satisfaction':
                    return $this->generateCustomerSatisfactionReport($vendorId, $dateFrom, $dateTo);
                case 'comprehensive':
                default:
                    return $this->generateComprehensiveReport($vendorId, $dateFrom, $dateTo);
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate comprehensive report
     */
    private function generateComprehensiveReport(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            $dashboardService = new VendorDashboardService();
            $feedbackAggregator = new FeedbackAggregator();

            // Get dashboard data
            $dashboardResult = $dashboardService->getDashboardData($vendorId, $dateFrom, $dateTo);
            $dashboard = $dashboardResult['success'] ? $dashboardResult['dashboard'] : [];

            // Get feedback summary
            $feedbackResult = $feedbackAggregator->getVendorFeedbackSummary($vendorId);
            $feedback = $feedbackResult['success'] ? $feedbackResult : [];

            // Generate insights and recommendations
            $insights = $this->generateInsights($dashboard, $feedback);
            $recommendations = $this->generateRecommendations($dashboard, $feedback);

            $report = [
                'report_type' => 'comprehensive',
                'vendor_id' => $vendorId,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'generated_at' => date('Y-m-d H:i:s'),
                'executive_summary' => $this->generateExecutiveSummary($dashboard),
                'performance_metrics' => $dashboard,
                'customer_feedback' => $feedback,
                'insights' => $insights,
                'recommendations' => $recommendations
            ];

            return [
                'success' => true,
                'report' => $report
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Comprehensive report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate sales report
     */
    private function generateSalesReport(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            // Sales metrics
            $sql = "SELECT 
                        COUNT(*) as total_orders,
                        SUM(total_amount) as total_revenue,
                        AVG(total_amount) as avg_order_value,
                        MIN(total_amount) as min_order_value,
                        MAX(total_amount) as max_order_value
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $salesMetrics = $stmt->fetch();

            // Daily sales breakdown
            $sql = "SELECT 
                        DATE(created_at) as sale_date,
                        COUNT(*) as orders,
                        SUM(total_amount) as revenue
                    FROM orders 
                    WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY sale_date";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $dateFrom, $dateTo]);
            $dailySales = $stmt->fetchAll();

            $report = [
                'report_type' => 'sales',
                'vendor_id' => $vendorId,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'generated_at' => date('Y-m-d H:i:s'),
                'sales_metrics' => $salesMetrics,
                'daily_breakdown' => $dailySales
            ];

            return [
                'success' => true,
                'report' => $report
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Sales report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate inventory report
     */
    private function generateInventoryReport(int $vendorId): array
    {
        try {
            // Current inventory status
            $sql = "SELECT 
                        p.name as product_name,
                        p.id as product_id,
                        SUM(ib.quantity) as current_stock,
                        COUNT(ib.id) as batch_count,
                        MIN(ib.expiry_date) as earliest_expiry,
                        MAX(ib.expiry_date) as latest_expiry
                    FROM products p
                    LEFT JOIN inventory_batches ib ON p.id = ib.product_id
                    WHERE p.vendor_id = ?
                    GROUP BY p.id
                    ORDER BY current_stock ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $inventoryStatus = $stmt->fetchAll();

            $report = [
                'report_type' => 'inventory',
                'vendor_id' => $vendorId,
                'generated_at' => date('Y-m-d H:i:s'),
                'inventory_status' => $inventoryStatus
            ];

            return [
                'success' => true,
                'report' => $report
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Inventory report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate customer satisfaction report
     */
    private function generateCustomerSatisfactionReport(int $vendorId, string $dateFrom, string $dateTo): array
    {
        try {
            $feedbackAggregator = new FeedbackAggregator();
            $feedbackResult = $feedbackAggregator->getVendorFeedbackSummary($vendorId);

            $report = [
                'report_type' => 'customer_satisfaction',
                'vendor_id' => $vendorId,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'generated_at' => date('Y-m-d H:i:s'),
                'satisfaction_metrics' => $feedbackResult['success'] ? $feedbackResult : []
            ];

            return [
                'success' => true,
                'report' => $report
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Customer satisfaction report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate executive summary
     */
    private function generateExecutiveSummary(array $dashboard): array
    {
        $overview = $dashboard['overview'] ?? [];
        
        return [
            'key_metrics' => [
                'total_orders' => $overview['total_orders'] ?? 0,
                'total_revenue' => $overview['total_revenue'] ?? 0,
                'unique_customers' => $overview['unique_customers'] ?? 0,
                'active_products' => $overview['active_products'] ?? 0
            ],
            'performance_highlights' => $this->generatePerformanceHighlights($dashboard),
            'areas_for_improvement' => $this->generateImprovementAreas($dashboard)
        ];
    }

    /**
     * Generate performance highlights
     */
    private function generatePerformanceHighlights(array $dashboard): array
    {
        $highlights = [];
        
        $overview = $dashboard['overview'] ?? [];
        if (($overview['total_revenue'] ?? 0) > 10000) {
            $highlights[] = 'Strong revenue performance with ₹' . number_format($overview['total_revenue'], 2) . ' in sales';
        }
        
        if (($overview['unique_customers'] ?? 0) > 50) {
            $highlights[] = 'Good customer base with ' . $overview['unique_customers'] . ' unique customers';
        }

        return $highlights;
    }

    /**
     * Generate improvement areas
     */
    private function generateImprovementAreas(array $dashboard): array
    {
        $improvements = [];
        
        $alerts = $dashboard['alerts'] ?? [];
        foreach ($alerts as $alert) {
            if ($alert['type'] === 'low_stock') {
                $improvements[] = 'Address low stock issues for ' . $alert['count'] . ' products';
            }
            if ($alert['type'] === 'expiring_products') {
                $improvements[] = 'Manage expiring inventory for ' . $alert['count'] . ' product batches';
            }
        }

        return $improvements;
    }

    /**
     * Generate insights
     */
    private function generateInsights(array $dashboard, array $feedback): array
    {
        $insights = [];
        
        // Sales insights
        $salesGrowth = $dashboard['sales']['sales_growth'] ?? 0;
        if ($salesGrowth > 10) {
            $insights[] = "Sales are growing strongly at {$salesGrowth}% compared to previous period";
        } elseif ($salesGrowth < -10) {
            $insights[] = "Sales have declined by {$salesGrowth}% - consider promotional strategies";
        }

        // Customer satisfaction insights
        $avgRating = $feedback['ratings_summary']['avg_overall_rating'] ?? 0;
        if ($avgRating >= 4.5) {
            $insights[] = "Excellent customer satisfaction with {$avgRating}/5 average rating";
        } elseif ($avgRating < 3.5) {
            $insights[] = "Customer satisfaction needs improvement - current rating is {$avgRating}/5";
        }

        return $insights;
    }

    /**
     * Generate recommendations
     */
    private function generateRecommendations(array $dashboard, array $feedback): array
    {
        $recommendations = [];
        
        // Inventory recommendations
        $alerts = $dashboard['alerts'] ?? [];
        foreach ($alerts as $alert) {
            if ($alert['type'] === 'low_stock') {
                $recommendations[] = [
                    'category' => 'inventory',
                    'priority' => 'high',
                    'title' => 'Restock Low Inventory Items',
                    'description' => 'Restock ' . $alert['count'] . ' products that are running low',
                    'action' => 'Review inventory levels and place orders with suppliers'
                ];
            }
        }

        // Customer satisfaction recommendations
        $avgRating = $feedback['ratings_summary']['avg_overall_rating'] ?? 0;
        if ($avgRating < 4.0) {
            $recommendations[] = [
                'category' => 'customer_service',
                'priority' => 'medium',
                'title' => 'Improve Customer Satisfaction',
                'description' => 'Current rating is ' . $avgRating . '/5 - focus on service quality',
                'action' => 'Analyze customer feedback and implement service improvements'
            ];
        }

        return $recommendations;
    }
}