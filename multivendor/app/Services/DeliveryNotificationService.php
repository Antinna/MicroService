<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Delivery notification service for real-time status updates
 */
class DeliveryNotificationService
{
    private PDO $db;
    private Logger $logger;
    private NotificationDispatcher $notificationDispatcher;

    // Delivery statuses
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_PICKED_UP = 'picked_up';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_RETURNED = 'returned';

    // Notification types
    const NOTIFICATION_CUSTOMER = 'customer';
    const NOTIFICATION_VENDOR = 'vendor';
    const NOTIFICATION_DELIVERY_AGENT = 'delivery_agent';
    const NOTIFICATION_ADMIN = 'admin';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->notificationDispatcher = new NotificationDispatcher();
        $this->createDeliveryNotificationTables();
    }

    /**
     * Send delivery status notification
     */
    public function sendDeliveryStatusNotification(int $orderId, string $status, array $additionalData = []): array
    {
        try {
            // Get order details
            $order = $this->getOrderDetails($orderId);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found'
                ];
            }

            // Prepare notification data
            $notificationData = $this->prepareNotificationData($order, $status, $additionalData);

            // Send notifications to different recipients
            $results = [];

            // Send to customer
            if ($this->shouldNotifyCustomer($status)) {
                $customerResult = $this->sendCustomerNotification($order, $notificationData);
                $results['customer'] = $customerResult;
            }

            // Send to vendor
            if ($this->shouldNotifyVendor($status)) {
                $vendorResult = $this->sendVendorNotification($order, $notificationData);
                $results['vendor'] = $vendorResult;
            }

            // Send to delivery agent
            if ($this->shouldNotifyDeliveryAgent($status) && !empty($order['delivery_agent_id'])) {
                $agentResult = $this->sendDeliveryAgentNotification($order, $notificationData);
                $results['delivery_agent'] = $agentResult;
            }

            // Record delivery notification
            $this->recordDeliveryNotification($orderId, $status, $notificationData, $results);

            // Update order status
            $this->updateOrderStatus($orderId, $status, $additionalData);

            $successCount = 0;
            foreach ($results as $result) {
                if ($result['success']) {
                    $successCount++;
                }
            }

            return [
                'success' => $successCount > 0,
                'order_id' => $orderId,
                'status' => $status,
                'notifications_sent' => $successCount,
                'results' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to send delivery status notification', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send delivery notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send bulk delivery notifications
     */
    public function sendBulkDeliveryNotifications(array $orderUpdates): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($orderUpdates as $update) {
            try {
                $orderId = $update['order_id'];
                $status = $update['status'];
                $additionalData = $update['additional_data'] ?? [];

                $result = $this->sendDeliveryStatusNotification($orderId, $status, $additionalData);
                
                $results[] = [
                    'order_id' => $orderId,
                    'status' => $status,
                    'result' => $result
                ];

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }

                // Small delay to prevent overwhelming notification services
                usleep(50000); // 50ms delay

            } catch (Exception $e) {
                $failureCount++;
                $results[] = [
                    'order_id' => $update['order_id'] ?? 'unknown',
                    'status' => $update['status'] ?? 'unknown',
                    'result' => [
                        'success' => false,
                        'error' => $e->getMessage()
                    ]
                ];

                $this->logger->error('Failed to send bulk delivery notification', [
                    'update' => $update,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'success' => $successCount > 0,
            'total_orders' => count($orderUpdates),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results
        ];
    }

    /**
     * Send delivery failure notification with retry scheduling
     */
    public function sendDeliveryFailureNotification(int $orderId, string $failureReason, array $retryOptions = []): array
    {
        try {
            $additionalData = [
                'failure_reason' => $failureReason,
                'retry_scheduled' => !empty($retryOptions),
                'retry_date' => $retryOptions['retry_date'] ?? null,
                'retry_time_slot' => $retryOptions['retry_time_slot'] ?? null,
                'max_retry_attempts' => $retryOptions['max_attempts'] ?? 3,
                'current_attempt' => $retryOptions['current_attempt'] ?? 1
            ];

            // Send failure notification
            $result = $this->sendDeliveryStatusNotification($orderId, self::STATUS_FAILED, $additionalData);

            // Schedule retry if options provided
            if (!empty($retryOptions) && $result['success']) {
                $retryResult = $this->scheduleDeliveryRetry($orderId, $retryOptions);
                $result['retry_scheduled'] = $retryResult['success'];
                $result['retry_details'] = $retryResult;
            }

            return $result;

        } catch (Exception $e) {
            $this->logger->error('Failed to send delivery failure notification', [
                'order_id' => $orderId,
                'failure_reason' => $failureReason,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send delivery failure notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send delivery completion notification with feedback request
     */
    public function sendDeliveryCompletionNotification(int $orderId, array $deliveryDetails = []): array
    {
        try {
            $additionalData = array_merge([
                'delivery_completed_at' => date('Y-m-d H:i:s'),
                'request_feedback' => true,
                'feedback_url' => $this->generateFeedbackUrl($orderId)
            ], $deliveryDetails);

            $result = $this->sendDeliveryStatusNotification($orderId, self::STATUS_DELIVERED, $additionalData);

            // Schedule follow-up feedback reminder
            if ($result['success']) {
                $this->scheduleFeedbackReminder($orderId);
            }

            return $result;

        } catch (Exception $e) {
            $this->logger->error('Failed to send delivery completion notification', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send delivery completion notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get delivery notification history
     */
    public function getDeliveryNotificationHistory(int $orderId = null, int $days = 7): array
    {
        try {
            $whereClause = $orderId ? 'WHERE dn.order_id = ?' : '';
            $params = $orderId ? [$orderId, $days] : [$days];

            $sql = "SELECT 
                        dn.*,
                        o.order_number,
                        v.business_name as vendor_name,
                        COALESCE(c.name, o.customer_name) as customer_name
                    FROM delivery_notifications dn
                    LEFT JOIN orders o ON dn.order_id = o.id
                    LEFT JOIN vendors v ON o.vendor_id = v.id
                    LEFT JOIN customers c ON o.customer_id = c.id
                    {$whereClause}
                    " . ($orderId ? "AND" : "WHERE") . " dn.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ORDER BY dn.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $notifications = $stmt->fetchAll();

            // Group by status for summary
            $summary = [];
            foreach ($notifications as $notification) {
                $status = $notification['delivery_status'];
                if (!isset($summary[$status])) {
                    $summary[$status] = 0;
                }
                $summary[$status]++;
            }

            return [
                'success' => true,
                'notifications' => $notifications,
                'summary' => $summary,
                'total_notifications' => count($notifications),
                'period_days' => $days
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get delivery notification history', [
                'order_id' => $orderId,
                'days' => $days,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get notification history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get delivery statistics
     */
    public function getDeliveryStatistics(int $vendorId = null, int $days = 30): array
    {
        try {
            $whereClause = $vendorId ? 'AND o.vendor_id = ?' : '';
            $params = $vendorId ? [$days, $vendorId] : [$days];

            $sql = "SELECT 
                        dn.delivery_status,
                        COUNT(*) as count,
                        COUNT(DISTINCT dn.order_id) as unique_orders,
                        AVG(CASE WHEN dn.delivery_status = 'delivered' 
                            THEN TIMESTAMPDIFF(HOUR, o.created_at, dn.created_at) 
                            ELSE NULL END) as avg_delivery_time_hours
                    FROM delivery_notifications dn
                    JOIN orders o ON dn.order_id = o.id
                    WHERE dn.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    {$whereClause}
                    GROUP BY dn.delivery_status
                    ORDER BY count DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $statusStats = $stmt->fetchAll();

            // Get daily delivery trends
            $trendSql = "SELECT 
                            DATE(dn.created_at) as date,
                            dn.delivery_status,
                            COUNT(*) as count
                        FROM delivery_notifications dn
                        JOIN orders o ON dn.order_id = o.id
                        WHERE dn.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                        {$whereClause}
                        GROUP BY DATE(dn.created_at), dn.delivery_status
                        ORDER BY date DESC, dn.delivery_status";

            $stmt = $this->db->prepare($trendSql);
            $stmt->execute($params);
            $dailyTrends = $stmt->fetchAll();

            // Calculate success rate
            $totalDeliveries = 0;
            $successfulDeliveries = 0;
            $failedDeliveries = 0;

            foreach ($statusStats as $stat) {
                $totalDeliveries += $stat['count'];
                if ($stat['delivery_status'] === self::STATUS_DELIVERED) {
                    $successfulDeliveries += $stat['count'];
                } elseif ($stat['delivery_status'] === self::STATUS_FAILED) {
                    $failedDeliveries += $stat['count'];
                }
            }

            $successRate = $totalDeliveries > 0 ? ($successfulDeliveries / $totalDeliveries) * 100 : 0;

            return [
                'success' => true,
                'period_days' => $days,
                'vendor_id' => $vendorId,
                'status_statistics' => $statusStats,
                'daily_trends' => $dailyTrends,
                'summary' => [
                    'total_deliveries' => $totalDeliveries,
                    'successful_deliveries' => $successfulDeliveries,
                    'failed_deliveries' => $failedDeliveries,
                    'success_rate_percentage' => round($successRate, 2)
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get delivery statistics', [
                'vendor_id' => $vendorId,
                'days' => $days,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get delivery statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get order details
     */
    private function getOrderDetails(int $orderId): ?array
    {
        try {
            $sql = "SELECT 
                        o.*,
                        v.business_name as vendor_name,
                        v.email as vendor_email,
                        v.phone as vendor_phone,
                        COALESCE(c.name, o.customer_name) as customer_name,
                        COALESCE(c.email, o.customer_email) as customer_email,
                        COALESCE(c.phone, o.customer_phone) as customer_phone,
                        da.name as delivery_agent_name,
                        da.phone as delivery_agent_phone
                    FROM orders o
                    LEFT JOIN vendors v ON o.vendor_id = v.id
                    LEFT JOIN customers c ON o.customer_id = c.id
                    LEFT JOIN delivery_agents da ON o.delivery_agent_id = da.id
                    WHERE o.id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Prepare notification data
     */
    private function prepareNotificationData(array $order, string $status, array $additionalData): array
    {
        $statusMessages = [
            self::STATUS_SCHEDULED => 'Your order has been scheduled for delivery',
            self::STATUS_PICKED_UP => 'Your order has been picked up and is on its way',
            self::STATUS_IN_TRANSIT => 'Your order is in transit',
            self::STATUS_OUT_FOR_DELIVERY => 'Your order is out for delivery',
            self::STATUS_DELIVERED => 'Your order has been delivered successfully',
            self::STATUS_FAILED => 'Delivery attempt failed',
            self::STATUS_CANCELLED => 'Your order delivery has been cancelled',
            self::STATUS_RETURNED => 'Your order has been returned'
        ];

        return array_merge([
            'order_id' => $order['id'],
            'order_number' => $order['order_number'] ?? $order['id'],
            'delivery_status' => $status,
            'status_message' => $statusMessages[$status] ?? 'Delivery status updated',
            'customer_name' => $order['customer_name'],
            'vendor_name' => $order['vendor_name'],
            'delivery_date' => $order['delivery_date'],
            'delivery_time_slot' => $order['delivery_time_slot'],
            'delivery_address' => $order['delivery_address'],
            'order_amount' => $order['total_amount'],
            'delivery_agent_name' => $order['delivery_agent_name'] ?? null,
            'delivery_agent_phone' => $order['delivery_agent_phone'] ?? null,
            'notification_time' => date('Y-m-d H:i:s')
        ], $additionalData);
    }

    /**
     * Send customer notification
     */
    private function sendCustomerNotification(array $order, array $notificationData): array
    {
        if (empty($order['customer_email']) && empty($order['customer_phone'])) {
            return [
                'success' => false,
                'error' => 'No customer contact information available'
            ];
        }

        $templateCode = $this->getCustomerTemplateCode($notificationData['delivery_status']);
        
        $recipientData = [
            'recipient' => $order['customer_email'] ?? $order['customer_phone'],
            'recipient_id' => $order['customer_id'] ?? null
        ];

        return $this->notificationDispatcher->dispatchFromTemplate($templateCode, $notificationData, $recipientData);
    }

    /**
     * Send vendor notification
     */
    private function sendVendorNotification(array $order, array $notificationData): array
    {
        $templateCode = $this->getVendorTemplateCode($notificationData['delivery_status']);
        
        return $this->notificationDispatcher->sendVendorNotification(
            $order['vendor_id'],
            $templateCode,
            $notificationData
        );
    }

    /**
     * Send delivery agent notification
     */
    private function sendDeliveryAgentNotification(array $order, array $notificationData): array
    {
        if (empty($order['delivery_agent_phone'])) {
            return [
                'success' => false,
                'error' => 'No delivery agent contact information available'
            ];
        }

        $templateCode = $this->getDeliveryAgentTemplateCode($notificationData['delivery_status']);
        
        $recipientData = [
            'recipient' => $order['delivery_agent_phone'],
            'recipient_id' => $order['delivery_agent_id']
        ];

        return $this->notificationDispatcher->dispatchFromTemplate($templateCode, $notificationData, $recipientData);
    }

    /**
     * Determine if customer should be notified for status
     */
    private function shouldNotifyCustomer(string $status): bool
    {
        return in_array($status, [
            self::STATUS_SCHEDULED,
            self::STATUS_OUT_FOR_DELIVERY,
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED
        ]);
    }

    /**
     * Determine if vendor should be notified for status
     */
    private function shouldNotifyVendor(string $status): bool
    {
        return in_array($status, [
            self::STATUS_PICKED_UP,
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
            self::STATUS_RETURNED
        ]);
    }

    /**
     * Determine if delivery agent should be notified for status
     */
    private function shouldNotifyDeliveryAgent(string $status): bool
    {
        return in_array($status, [
            self::STATUS_SCHEDULED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED
        ]);
    }

    /**
     * Get customer template code for status
     */
    private function getCustomerTemplateCode(string $status): string
    {
        $templates = [
            self::STATUS_SCHEDULED => 'delivery_scheduled',
            self::STATUS_OUT_FOR_DELIVERY => 'delivery_out_for_delivery',
            self::STATUS_DELIVERED => 'delivery_completed',
            self::STATUS_FAILED => 'delivery_failed',
            self::STATUS_CANCELLED => 'delivery_cancelled'
        ];

        return $templates[$status] ?? 'delivery_status_update';
    }

    /**
     * Get vendor template code for status
     */
    private function getVendorTemplateCode(string $status): string
    {
        $templates = [
            self::STATUS_PICKED_UP => 'delivery_picked_up',
            self::STATUS_DELIVERED => 'delivery_completed_vendor',
            self::STATUS_FAILED => 'delivery_failed_vendor',
            self::STATUS_RETURNED => 'delivery_returned'
        ];

        return $templates[$status] ?? 'delivery_status_update_vendor';
    }

    /**
     * Get delivery agent template code for status
     */
    private function getDeliveryAgentTemplateCode(string $status): string
    {
        $templates = [
            self::STATUS_SCHEDULED => 'delivery_assignment',
            self::STATUS_FAILED => 'delivery_failed_agent',
            self::STATUS_CANCELLED => 'delivery_cancelled_agent'
        ];

        return $templates[$status] ?? 'delivery_status_update_agent';
    }

    /**
     * Record delivery notification
     */
    private function recordDeliveryNotification(int $orderId, string $status, array $notificationData, array $results): void
    {
        try {
            $sql = "INSERT INTO delivery_notifications 
                    (order_id, delivery_status, notification_data, notification_results, created_at)
                    VALUES (?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $orderId,
                $status,
                json_encode($notificationData),
                json_encode($results)
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to record delivery notification', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update order status
     */
    private function updateOrderStatus(int $orderId, string $status, array $additionalData): void
    {
        try {
            $sql = "UPDATE orders 
                    SET delivery_status = ?, 
                        updated_at = NOW()";
            
            $params = [$status];

            // Add specific fields based on status
            if ($status === self::STATUS_DELIVERED) {
                $sql .= ", delivered_at = NOW()";
            } elseif ($status === self::STATUS_FAILED && !empty($additionalData['failure_reason'])) {
                $sql .= ", delivery_notes = ?";
                $params[] = $additionalData['failure_reason'];
            }

            $sql .= " WHERE id = ?";
            $params[] = $orderId;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

        } catch (Exception $e) {
            $this->logger->error('Failed to update order status', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Schedule delivery retry
     */
    private function scheduleDeliveryRetry(int $orderId, array $retryOptions): array
    {
        try {
            $sql = "INSERT INTO delivery_retries 
                    (order_id, retry_date, retry_time_slot, attempt_number, 
                     max_attempts, retry_reason, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $orderId,
                $retryOptions['retry_date'],
                $retryOptions['retry_time_slot'] ?? null,
                $retryOptions['current_attempt'] ?? 1,
                $retryOptions['max_attempts'] ?? 3,
                $retryOptions['retry_reason'] ?? 'Delivery failed'
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'retry_id' => $this->db->lastInsertId(),
                    'message' => 'Delivery retry scheduled successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to schedule delivery retry'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to schedule delivery retry', [
                'order_id' => $orderId,
                'retry_options' => $retryOptions,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to schedule retry: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate feedback URL
     */
    private function generateFeedbackUrl(int $orderId): string
    {
        // In a real implementation, this would generate a secure feedback URL
        return "https://example.com/feedback?order=" . base64_encode($orderId);
    }

    /**
     * Schedule feedback reminder
     */
    private function scheduleFeedbackReminder(int $orderId): void
    {
        try {
            // Schedule a reminder to be sent after 24 hours if no feedback received
            $sql = "INSERT INTO scheduled_notifications 
                    (order_id, notification_type, scheduled_for, created_at)
                    VALUES (?, 'feedback_reminder', DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to schedule feedback reminder', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create delivery notification tables
     */
    private function createDeliveryNotificationTables(): void
    {
        // Delivery notifications table
        $sql1 = "CREATE TABLE IF NOT EXISTS delivery_notifications (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            delivery_status VARCHAR(50) NOT NULL,
            notification_data JSON,
            notification_results JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_delivery_status (delivery_status),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )";

        // Delivery retries table
        $sql2 = "CREATE TABLE IF NOT EXISTS delivery_retries (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            retry_date DATE NOT NULL,
            retry_time_slot VARCHAR(50),
            attempt_number INT DEFAULT 1,
            max_attempts INT DEFAULT 3,
            retry_reason TEXT,
            status ENUM('scheduled', 'completed', 'failed', 'cancelled') DEFAULT 'scheduled',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_retry_date (retry_date),
            INDEX idx_status (status),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )";

        // Scheduled notifications table
        $sql3 = "CREATE TABLE IF NOT EXISTS scheduled_notifications (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            scheduled_for TIMESTAMP NOT NULL,
            sent_at TIMESTAMP NULL,
            status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_notification_type (notification_type),
            INDEX idx_scheduled_for (scheduled_for),
            INDEX idx_status (status),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
        $this->db->exec($sql3);
    }
}