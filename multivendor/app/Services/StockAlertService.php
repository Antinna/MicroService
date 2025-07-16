<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Stock alert service with predictive inventory notifications
 */
class StockAlertService
{
    private PDO $db;
    private Logger $logger;
    private NotificationDispatcher $notificationDispatcher;

    // Alert thresholds
    const CRITICAL_STOCK_PERCENTAGE = 10; // 10% of minimum stock level
    const LOW_STOCK_PERCENTAGE = 25; // 25% of minimum stock level
    const MODERATE_STOCK_PERCENTAGE = 50; // 50% of minimum stock level

    // Alert frequencies (in hours)
    const CRITICAL_ALERT_FREQUENCY = 2; // Every 2 hours
    const LOW_ALERT_FREQUENCY = 6; // Every 6 hours
    const MODERATE_ALERT_FREQUENCY = 24; // Every 24 hours

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->notificationDispatcher = new NotificationDispatcher();
        $this->createStockAlertTables();
    }

    /**
     * Check and send stock alerts
     */
    public function checkAndSendAlerts(): array
    {
        try {
            $this->logger->info('Starting stock alert check');

            // Get products that need stock alerts
            $alertProducts = $this->getProductsNeedingAlerts();
            
            if (empty($alertProducts)) {
                return [
                    'success' => true,
                    'message' => 'No products need stock alerts',
                    'alerts_sent' => 0
                ];
            }

            $alertsSent = 0;
            $results = [];

            foreach ($alertProducts as $product) {
                $alertResult = $this->sendStockAlert($product);
                $results[] = $alertResult;
                
                if ($alertResult['success']) {
                    $alertsSent++;
                }
            }

            $this->logger->info('Stock alert check completed', [
                'products_checked' => count($alertProducts),
                'alerts_sent' => $alertsSent
            ]);

            return [
                'success' => true,
                'products_checked' => count($alertProducts),
                'alerts_sent' => $alertsSent,
                'results' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to check and send stock alerts', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to check stock alerts: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send stock alert for specific product
     */
    public function sendStockAlert(array $product): array
    {
        try {
            $alertLevel = $this->determineAlertLevel($product);
            $alertData = $this->prepareAlertData($product, $alertLevel);

            // Send notification
            $notificationResult = $this->notificationDispatcher->sendVendorNotification(
                $product['vendor_id'],
                'low_stock_alert',
                $alertData
            );

            if ($notificationResult['success']) {
                // Record the alert
                $this->recordStockAlert($product['id'], $alertLevel, $alertData);
                
                // Update last alert timestamp
                $this->updateLastAlertTime($product['id']);
            }

            return [
                'success' => $notificationResult['success'],
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'alert_level' => $alertLevel,
                'notification_result' => $notificationResult
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to send stock alert', [
                'product_id' => $product['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'product_id' => $product['id'],
                'error' => 'Failed to send stock alert: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get predictive stock recommendations
     */
    public function getPredictiveRecommendations(int $vendorId = null): array
    {
        try {
            $whereClause = $vendorId ? 'AND p.vendor_id = ?' : '';
            $params = $vendorId ? [$vendorId] : [];

            $sql = "SELECT 
                        p.*,
                        v.business_name,
                        v.email,
                        v.phone,
                        COALESCE(sales.avg_daily_sales, 0) as avg_daily_sales,
                        COALESCE(sales.trend_factor, 1) as trend_factor,
                        CASE 
                            WHEN COALESCE(sales.avg_daily_sales, 0) > 0 
                            THEN FLOOR(p.stock_quantity / sales.avg_daily_sales)
                            ELSE 999
                        END as days_until_stockout,
                        CASE 
                            WHEN COALESCE(sales.avg_daily_sales, 0) > 0 
                            THEN CEIL(sales.avg_daily_sales * 7 * sales.trend_factor)
                            ELSE p.minimum_stock_level
                        END as recommended_reorder_quantity
                    FROM products p
                    JOIN vendors v ON p.vendor_id = v.id
                    LEFT JOIN (
                        SELECT 
                            product_id,
                            AVG(daily_quantity) as avg_daily_sales,
                            CASE 
                                WHEN AVG(recent_quantity) > AVG(older_quantity) THEN 1.2
                                WHEN AVG(recent_quantity) < AVG(older_quantity) THEN 0.8
                                ELSE 1.0
                            END as trend_factor
                        FROM (
                            SELECT 
                                oi.product_id,
                                DATE(o.created_at) as order_date,
                                SUM(oi.quantity) as daily_quantity,
                                CASE 
                                    WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                                    THEN SUM(oi.quantity) 
                                    ELSE 0 
                                END as recent_quantity,
                                CASE 
                                    WHEN o.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) 
                                    THEN SUM(oi.quantity) 
                                    ELSE 0 
                                END as older_quantity
                            FROM order_items oi
                            JOIN orders o ON oi.order_id = o.id
                            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                            AND o.status IN ('completed', 'delivered')
                            GROUP BY oi.product_id, DATE(o.created_at)
                        ) daily_sales
                        GROUP BY product_id
                    ) sales ON p.id = sales.product_id
                    WHERE p.is_active = 1 
                    AND v.status = 'active'
                    {$whereClause}
                    ORDER BY days_until_stockout ASC, p.stock_quantity ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            $recommendations = [];
            foreach ($products as $product) {
                $recommendation = $this->generateRecommendation($product);
                $recommendations[] = $recommendation;
            }

            return [
                'success' => true,
                'recommendations' => $recommendations,
                'total_products' => count($recommendations)
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get predictive recommendations', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get recommendations: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get stock alert history
     */
    public function getAlertHistory(int $vendorId = null, int $days = 30): array
    {
        try {
            $whereClause = $vendorId ? 'AND p.vendor_id = ?' : '';
            $params = $vendorId ? [$days, $vendorId] : [$days];

            $sql = "SELECT 
                        sa.*,
                        p.name as product_name,
                        p.sku,
                        v.business_name as vendor_name
                    FROM stock_alerts sa
                    JOIN products p ON sa.product_id = p.id
                    JOIN vendors v ON p.vendor_id = v.id
                    WHERE sa.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    {$whereClause}
                    ORDER BY sa.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $alerts = $stmt->fetchAll();

            // Group by alert level for summary
            $summary = [
                'critical' => 0,
                'low' => 0,
                'moderate' => 0,
                'total' => count($alerts)
            ];

            foreach ($alerts as $alert) {
                if (isset($summary[$alert['alert_level']])) {
                    $summary[$alert['alert_level']]++;
                }
            }

            return [
                'success' => true,
                'alerts' => $alerts,
                'summary' => $summary,
                'period_days' => $days
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get alert history', [
                'vendor_id' => $vendorId,
                'days' => $days,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get alert history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Configure stock alert settings for vendor
     */
    public function configureAlertSettings(int $vendorId, array $settings): array
    {
        try {
            $sql = "INSERT INTO vendor_stock_alert_settings 
                    (vendor_id, critical_threshold, low_threshold, moderate_threshold,
                     critical_frequency, low_frequency, moderate_frequency, 
                     enable_predictive_alerts, enable_weekend_alerts, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    critical_threshold = VALUES(critical_threshold),
                    low_threshold = VALUES(low_threshold),
                    moderate_threshold = VALUES(moderate_threshold),
                    critical_frequency = VALUES(critical_frequency),
                    low_frequency = VALUES(low_frequency),
                    moderate_frequency = VALUES(moderate_frequency),
                    enable_predictive_alerts = VALUES(enable_predictive_alerts),
                    enable_weekend_alerts = VALUES(enable_weekend_alerts),
                    updated_at = NOW()";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $vendorId,
                $settings['critical_threshold'] ?? self::CRITICAL_STOCK_PERCENTAGE,
                $settings['low_threshold'] ?? self::LOW_STOCK_PERCENTAGE,
                $settings['moderate_threshold'] ?? self::MODERATE_STOCK_PERCENTAGE,
                $settings['critical_frequency'] ?? self::CRITICAL_ALERT_FREQUENCY,
                $settings['low_frequency'] ?? self::LOW_ALERT_FREQUENCY,
                $settings['moderate_frequency'] ?? self::MODERATE_ALERT_FREQUENCY,
                $settings['enable_predictive_alerts'] ?? true,
                $settings['enable_weekend_alerts'] ?? false
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Stock alert settings updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update stock alert settings'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to configure alert settings', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to configure alert settings: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get vendor alert settings
     */
    public function getVendorAlertSettings(int $vendorId): array
    {
        try {
            $sql = "SELECT * FROM vendor_stock_alert_settings WHERE vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $settings = $stmt->fetch();

            if (!$settings) {
                // Return default settings
                $settings = [
                    'vendor_id' => $vendorId,
                    'critical_threshold' => self::CRITICAL_STOCK_PERCENTAGE,
                    'low_threshold' => self::LOW_STOCK_PERCENTAGE,
                    'moderate_threshold' => self::MODERATE_STOCK_PERCENTAGE,
                    'critical_frequency' => self::CRITICAL_ALERT_FREQUENCY,
                    'low_frequency' => self::LOW_ALERT_FREQUENCY,
                    'moderate_frequency' => self::MODERATE_ALERT_FREQUENCY,
                    'enable_predictive_alerts' => true,
                    'enable_weekend_alerts' => false
                ];
            }

            return [
                'success' => true,
                'settings' => $settings
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor alert settings', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get alert settings: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get products needing alerts
     */
    private function getProductsNeedingAlerts(): array
    {
        try {
            $sql = "SELECT 
                        p.*,
                        v.business_name,
                        v.email,
                        v.phone,
                        COALESCE(settings.critical_threshold, ?) as critical_threshold,
                        COALESCE(settings.low_threshold, ?) as low_threshold,
                        COALESCE(settings.moderate_threshold, ?) as moderate_threshold,
                        COALESCE(settings.critical_frequency, ?) as critical_frequency,
                        COALESCE(settings.low_frequency, ?) as low_frequency,
                        COALESCE(settings.moderate_frequency, ?) as moderate_frequency,
                        COALESCE(settings.enable_predictive_alerts, 1) as enable_predictive_alerts,
                        COALESCE(settings.enable_weekend_alerts, 0) as enable_weekend_alerts
                    FROM products p
                    JOIN vendors v ON p.vendor_id = v.id
                    LEFT JOIN vendor_stock_alert_settings settings ON v.id = settings.vendor_id
                    WHERE p.is_active = 1 
                    AND v.status = 'active'
                    AND (
                        -- Critical stock level
                        (p.stock_quantity <= (p.minimum_stock_level * COALESCE(settings.critical_threshold, ?) / 100)
                         AND (p.last_stock_alert_at IS NULL 
                              OR p.last_stock_alert_at < DATE_SUB(NOW(), INTERVAL COALESCE(settings.critical_frequency, ?) HOUR)))
                        OR
                        -- Low stock level
                        (p.stock_quantity <= (p.minimum_stock_level * COALESCE(settings.low_threshold, ?) / 100)
                         AND p.stock_quantity > (p.minimum_stock_level * COALESCE(settings.critical_threshold, ?) / 100)
                         AND (p.last_stock_alert_at IS NULL 
                              OR p.last_stock_alert_at < DATE_SUB(NOW(), INTERVAL COALESCE(settings.low_frequency, ?) HOUR)))
                        OR
                        -- Moderate stock level
                        (p.stock_quantity <= (p.minimum_stock_level * COALESCE(settings.moderate_threshold, ?) / 100)
                         AND p.stock_quantity > (p.minimum_stock_level * COALESCE(settings.low_threshold, ?) / 100)
                         AND (p.last_stock_alert_at IS NULL 
                              OR p.last_stock_alert_at < DATE_SUB(NOW(), INTERVAL COALESCE(settings.moderate_frequency, ?) HOUR)))
                    )
                    AND (
                        COALESCE(settings.enable_weekend_alerts, 0) = 1
                        OR DAYOFWEEK(NOW()) NOT IN (1, 7) -- Not Sunday or Saturday
                    )
                    ORDER BY 
                        CASE 
                            WHEN p.stock_quantity <= (p.minimum_stock_level * COALESCE(settings.critical_threshold, ?) / 100) THEN 1
                            WHEN p.stock_quantity <= (p.minimum_stock_level * COALESCE(settings.low_threshold, ?) / 100) THEN 2
                            ELSE 3
                        END,
                        p.stock_quantity ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                self::CRITICAL_STOCK_PERCENTAGE, self::LOW_STOCK_PERCENTAGE, self::MODERATE_STOCK_PERCENTAGE,
                self::CRITICAL_ALERT_FREQUENCY, self::LOW_ALERT_FREQUENCY, self::MODERATE_ALERT_FREQUENCY,
                self::CRITICAL_STOCK_PERCENTAGE, self::CRITICAL_ALERT_FREQUENCY,
                self::LOW_STOCK_PERCENTAGE, self::CRITICAL_STOCK_PERCENTAGE, self::LOW_ALERT_FREQUENCY,
                self::MODERATE_STOCK_PERCENTAGE, self::LOW_STOCK_PERCENTAGE, self::MODERATE_ALERT_FREQUENCY,
                self::CRITICAL_STOCK_PERCENTAGE, self::LOW_STOCK_PERCENTAGE
            ]);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get products needing alerts', [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Determine alert level for product
     */
    private function determineAlertLevel(array $product): string
    {
        $criticalThreshold = ($product['minimum_stock_level'] * $product['critical_threshold']) / 100;
        $lowThreshold = ($product['minimum_stock_level'] * $product['low_threshold']) / 100;
        $moderateThreshold = ($product['minimum_stock_level'] * $product['moderate_threshold']) / 100;

        if ($product['stock_quantity'] <= $criticalThreshold) {
            return 'critical';
        } elseif ($product['stock_quantity'] <= $lowThreshold) {
            return 'low';
        } elseif ($product['stock_quantity'] <= $moderateThreshold) {
            return 'moderate';
        }

        return 'normal';
    }

    /**
     * Prepare alert data
     */
    private function prepareAlertData(array $product, string $alertLevel): array
    {
        $urgencyMessages = [
            'critical' => 'URGENT: Critical stock level reached!',
            'low' => 'WARNING: Low stock level detected',
            'moderate' => 'NOTICE: Stock level is getting low'
        ];

        return [
            'product_name' => $product['name'],
            'product_sku' => $product['sku'],
            'current_stock' => $product['stock_quantity'],
            'minimum_stock' => $product['minimum_stock_level'],
            'alert_level' => $alertLevel,
            'urgency_message' => $urgencyMessages[$alertLevel] ?? 'Stock alert',
            'stock_percentage' => round(($product['stock_quantity'] / $product['minimum_stock_level']) * 100, 1),
            'vendor_name' => $product['business_name'],
            'alert_time' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate recommendation
     */
    private function generateRecommendation(array $product): array
    {
        $recommendation = [
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'sku' => $product['sku'],
            'current_stock' => $product['stock_quantity'],
            'minimum_stock' => $product['minimum_stock_level'],
            'avg_daily_sales' => $product['avg_daily_sales'],
            'days_until_stockout' => $product['days_until_stockout'],
            'recommended_reorder_quantity' => $product['recommended_reorder_quantity'],
            'trend_factor' => $product['trend_factor'],
            'priority' => 'normal'
        ];

        // Determine priority
        if ($product['days_until_stockout'] <= 3) {
            $recommendation['priority'] = 'urgent';
            $recommendation['message'] = 'Immediate reorder required - will run out in ' . $product['days_until_stockout'] . ' days';
        } elseif ($product['days_until_stockout'] <= 7) {
            $recommendation['priority'] = 'high';
            $recommendation['message'] = 'Reorder soon - will run out in ' . $product['days_until_stockout'] . ' days';
        } elseif ($product['stock_quantity'] <= $product['minimum_stock_level']) {
            $recommendation['priority'] = 'medium';
            $recommendation['message'] = 'Below minimum stock level';
        } else {
            $recommendation['message'] = 'Stock level is adequate';
        }

        return $recommendation;
    }

    /**
     * Record stock alert
     */
    private function recordStockAlert(int $productId, string $alertLevel, array $alertData): void
    {
        try {
            $sql = "INSERT INTO stock_alerts 
                    (product_id, alert_level, stock_quantity, minimum_stock_level, 
                     alert_data, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $productId,
                $alertLevel,
                $alertData['current_stock'],
                $alertData['minimum_stock'],
                json_encode($alertData)
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to record stock alert', [
                'product_id' => $productId,
                'alert_level' => $alertLevel,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update last alert time
     */
    private function updateLastAlertTime(int $productId): void
    {
        try {
            $sql = "UPDATE products SET last_stock_alert_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update last alert time', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create stock alert tables
     */
    private function createStockAlertTables(): void
    {
        // Stock alerts table
        $sql1 = "CREATE TABLE IF NOT EXISTS stock_alerts (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            product_id BIGINT NOT NULL,
            alert_level ENUM('critical', 'low', 'moderate') NOT NULL,
            stock_quantity INT NOT NULL,
            minimum_stock_level INT NOT NULL,
            alert_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_product_id (product_id),
            INDEX idx_alert_level (alert_level),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )";

        // Vendor stock alert settings table
        $sql2 = "CREATE TABLE IF NOT EXISTS vendor_stock_alert_settings (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL UNIQUE,
            critical_threshold INT DEFAULT 10,
            low_threshold INT DEFAULT 25,
            moderate_threshold INT DEFAULT 50,
            critical_frequency INT DEFAULT 2,
            low_frequency INT DEFAULT 6,
            moderate_frequency INT DEFAULT 24,
            enable_predictive_alerts BOOLEAN DEFAULT TRUE,
            enable_weekend_alerts BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_vendor_id (vendor_id),
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
    }
}