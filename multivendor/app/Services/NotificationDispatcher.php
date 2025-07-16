<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Multi-channel notification dispatcher with Firebase, SMS, and Email integration
 */
class NotificationDispatcher
{
    private PDO $db;
    private Logger $logger;
    private NotificationService $notificationService;
    private NotificationTemplateManager $templateManager;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->notificationService = new NotificationService();
        $this->templateManager = new NotificationTemplateManager();
        $this->createPreferenceTables();
    }

    /**
     * Dispatch notification using template
     */
    public function dispatchFromTemplate(string $templateCode, array $data, array $recipientData): array
    {
        try {
            // Create notification from template
            $templateResult = $this->templateManager->createFromTemplate($templateCode, $data, $recipientData);
            
            if (!$templateResult['success']) {
                return $templateResult;
            }

            $notificationData = $templateResult['notification_data'];

            // Apply user preferences
            $notificationData = $this->applyUserPreferences($notificationData);

            // Send notification
            return $this->notificationService->send($notificationData);

        } catch (Exception $e) {
            $this->logger->error('Failed to dispatch notification from template', [
                'template_code' => $templateCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to dispatch notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Dispatch custom notification
     */
    public function dispatch(array $notificationData): array
    {
        try {
            // Apply user preferences
            $notificationData = $this->applyUserPreferences($notificationData);

            // Send notification
            return $this->notificationService->send($notificationData);

        } catch (Exception $e) {
            $this->logger->error('Failed to dispatch custom notification', [
                'error' => $e->getMessage(),
                'notification_data' => $notificationData
            ]);

            return [
                'success' => false,
                'error' => 'Failed to dispatch notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send vendor notification (convenience method)
     */
    public function sendVendorNotification(int $vendorId, string $templateCode, array $data = []): array
    {
        try {
            // Get vendor details
            $vendor = $this->getVendorDetails($vendorId);
            if (!$vendor) {
                return [
                    'success' => false,
                    'error' => 'Vendor not found'
                ];
            }

            $recipientData = [
                'recipient' => $vendor['email'] ?? $vendor['phone'],
                'recipient_id' => $vendorId
            ];

            // Add vendor data to template data
            $data = array_merge($data, [
                'vendor_name' => $vendor['business_name'] ?? $vendor['contact_person'],
                'vendor_id' => $vendorId
            ]);

            return $this->dispatchFromTemplate($templateCode, $data, $recipientData);

        } catch (Exception $e) {
            $this->logger->error('Failed to send vendor notification', [
                'vendor_id' => $vendorId,
                'template_code' => $templateCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send vendor notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send bulk notifications
     */
    public function sendBulkNotifications(array $recipients, string $templateCode, array $data = []): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($recipients as $recipient) {
            try {
                $recipientData = [
                    'recipient' => $recipient['email'] ?? $recipient['phone'],
                    'recipient_id' => $recipient['id'] ?? null
                ];

                // Merge recipient-specific data
                $recipientSpecificData = array_merge($data, $recipient['data'] ?? []);

                $result = $this->dispatchFromTemplate($templateCode, $recipientSpecificData, $recipientData);
                
                $results[] = [
                    'recipient' => $recipientData['recipient'],
                    'result' => $result
                ];

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }

                // Small delay to prevent overwhelming external services
                usleep(100000); // 100ms delay

            } catch (Exception $e) {
                $failureCount++;
                $results[] = [
                    'recipient' => $recipient['email'] ?? $recipient['phone'] ?? 'unknown',
                    'result' => [
                        'success' => false,
                        'error' => $e->getMessage()
                    ]
                ];

                $this->logger->error('Failed to send bulk notification to recipient', [
                    'recipient' => $recipient,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'success' => $successCount > 0,
            'total_recipients' => count($recipients),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results
        ];
    }

    /**
     * Send stock alert notifications
     */
    public function sendStockAlerts(): array
    {
        try {
            // Get products with low stock
            $lowStockProducts = $this->getLowStockProducts();
            
            if (empty($lowStockProducts)) {
                return [
                    'success' => true,
                    'message' => 'No low stock products found',
                    'alerts_sent' => 0
                ];
            }

            $alertsSent = 0;
            $results = [];

            foreach ($lowStockProducts as $product) {
                $data = [
                    'product_name' => $product['name'],
                    'current_stock' => $product['stock_quantity'],
                    'minimum_stock' => $product['minimum_stock_level'],
                    'product_id' => $product['id']
                ];

                $result = $this->sendVendorNotification(
                    $product['vendor_id'],
                    'low_stock_alert',
                    $data
                );

                $results[] = [
                    'product_id' => $product['id'],
                    'vendor_id' => $product['vendor_id'],
                    'result' => $result
                ];

                if ($result['success']) {
                    $alertsSent++;
                    
                    // Update last alert timestamp
                    $this->updateLastStockAlertTime($product['id']);
                }
            }

            return [
                'success' => true,
                'alerts_sent' => $alertsSent,
                'total_products' => count($lowStockProducts),
                'results' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to send stock alerts', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send stock alerts: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send order notifications
     */
    public function sendOrderNotification(int $orderId, string $notificationType = 'order_received'): array
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

            $data = [
                'order_id' => $orderId,
                'order_amount' => $order['total_amount'],
                'customer_name' => $order['customer_name'],
                'delivery_date' => $order['delivery_date'],
                'delivery_time' => $order['delivery_time_slot']
            ];

            return $this->sendVendorNotification(
                $order['vendor_id'],
                $notificationType,
                $data
            );

        } catch (Exception $e) {
            $this->logger->error('Failed to send order notification', [
                'order_id' => $orderId,
                'notification_type' => $notificationType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send order notification: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send delivery notifications
     */
    public function sendDeliveryNotification(int $orderId, string $status, array $additionalData = []): array
    {
        try {
            $templateCode = match($status) {
                'scheduled' => 'delivery_scheduled',
                'failed' => 'delivery_failed',
                'completed' => 'delivery_completed',
                default => 'delivery_status_update'
            };

            // Get order details
            $order = $this->getOrderDetails($orderId);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found'
                ];
            }

            $data = array_merge([
                'order_id' => $orderId,
                'delivery_status' => $status,
                'delivery_date' => $order['delivery_date'],
                'delivery_time' => $order['delivery_time_slot']
            ], $additionalData);

            // Send to both vendor and customer
            $results = [];

            // Send to vendor
            $vendorResult = $this->sendVendorNotification(
                $order['vendor_id'],
                $templateCode,
                $data
            );
            $results['vendor'] = $vendorResult;

            // Send to customer (if customer contact info available)
            if (!empty($order['customer_phone']) || !empty($order['customer_email'])) {
                $customerRecipientData = [
                    'recipient' => $order['customer_email'] ?? $order['customer_phone'],
                    'recipient_id' => $order['customer_id'] ?? null
                ];

                $customerResult = $this->dispatchFromTemplate($templateCode, $data, $customerRecipientData);
                $results['customer'] = $customerResult;
            }

            return [
                'success' => $vendorResult['success'] || ($results['customer']['success'] ?? false),
                'results' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to send delivery notification', [
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
     * Get or create user notification preferences
     */
    public function getUserPreferences(int $userId, string $userType = 'vendor'): array
    {
        try {
            $sql = "SELECT * FROM notification_preferences WHERE user_id = ? AND user_type = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $userType]);
            $preferences = $stmt->fetch();

            if (!$preferences) {
                // Create default preferences
                $defaultPreferences = $this->createDefaultPreferences($userId, $userType);
                return $defaultPreferences;
            }

            return [
                'success' => true,
                'preferences' => [
                    'email_enabled' => (bool)$preferences['email_enabled'],
                    'sms_enabled' => (bool)$preferences['sms_enabled'],
                    'push_enabled' => (bool)$preferences['push_enabled'],
                    'whatsapp_enabled' => (bool)$preferences['whatsapp_enabled'],
                    'in_app_enabled' => (bool)$preferences['in_app_enabled'],
                    'notification_types' => json_decode($preferences['notification_types'], true) ?? []
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get user preferences', [
                'user_id' => $userId,
                'user_type' => $userType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get user preferences: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update user notification preferences
     */
    public function updateUserPreferences(int $userId, string $userType, array $preferences): array
    {
        try {
            $sql = "INSERT INTO notification_preferences 
                    (user_id, user_type, email_enabled, sms_enabled, push_enabled, 
                     whatsapp_enabled, in_app_enabled, notification_types, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    email_enabled = VALUES(email_enabled),
                    sms_enabled = VALUES(sms_enabled),
                    push_enabled = VALUES(push_enabled),
                    whatsapp_enabled = VALUES(whatsapp_enabled),
                    in_app_enabled = VALUES(in_app_enabled),
                    notification_types = VALUES(notification_types),
                    updated_at = NOW()";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $userId,
                $userType,
                $preferences['email_enabled'] ?? true,
                $preferences['sms_enabled'] ?? true,
                $preferences['push_enabled'] ?? true,
                $preferences['whatsapp_enabled'] ?? false,
                $preferences['in_app_enabled'] ?? true,
                json_encode($preferences['notification_types'] ?? [])
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Notification preferences updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update notification preferences'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to update user preferences', [
                'user_id' => $userId,
                'user_type' => $userType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to update preferences: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Apply user preferences to notification data
     */
    private function applyUserPreferences(array $notificationData): array
    {
        try {
            $recipientId = $notificationData['recipient_id'] ?? null;
            
            if (!$recipientId) {
                return $notificationData; // No user ID, can't apply preferences
            }

            $preferencesResult = $this->getUserPreferences($recipientId);
            
            if (!$preferencesResult['success']) {
                return $notificationData; // Failed to get preferences, use original
            }

            $preferences = $preferencesResult['preferences'];
            $originalChannels = $notificationData['channels'] ?? ['in_app'];
            $filteredChannels = [];

            // Filter channels based on user preferences
            foreach ($originalChannels as $channel) {
                switch ($channel) {
                    case 'email':
                        if ($preferences['email_enabled']) {
                            $filteredChannels[] = $channel;
                        }
                        break;
                    case 'sms':
                        if ($preferences['sms_enabled']) {
                            $filteredChannels[] = $channel;
                        }
                        break;
                    case 'push':
                        if ($preferences['push_enabled']) {
                            $filteredChannels[] = $channel;
                        }
                        break;
                    case 'whatsapp':
                        if ($preferences['whatsapp_enabled']) {
                            $filteredChannels[] = $channel;
                        }
                        break;
                    case 'in_app':
                        if ($preferences['in_app_enabled']) {
                            $filteredChannels[] = $channel;
                        }
                        break;
                    default:
                        $filteredChannels[] = $channel; // Unknown channel, keep it
                }
            }

            // Ensure at least one channel remains
            if (empty($filteredChannels)) {
                $filteredChannels = ['in_app']; // Fallback to in-app
            }

            $notificationData['channels'] = $filteredChannels;

            return $notificationData;

        } catch (Exception $e) {
            $this->logger->error('Failed to apply user preferences', [
                'error' => $e->getMessage()
            ]);

            return $notificationData; // Return original on error
        }
    }

    /**
     * Get vendor details
     */
    private function getVendorDetails(int $vendorId): ?array
    {
        try {
            $sql = "SELECT * FROM vendors WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor details', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get low stock products
     */
    private function getLowStockProducts(): array
    {
        try {
            $sql = "SELECT p.*, v.id as vendor_id, v.business_name, v.email, v.phone
                    FROM products p
                    JOIN vendors v ON p.vendor_id = v.id
                    WHERE p.stock_quantity <= p.minimum_stock_level
                    AND p.is_active = 1
                    AND v.status = 'active'
                    AND (p.last_stock_alert_at IS NULL 
                         OR p.last_stock_alert_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get low stock products', [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Update last stock alert time
     */
    private function updateLastStockAlertTime(int $productId): void
    {
        try {
            $sql = "UPDATE products SET last_stock_alert_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update last stock alert time', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get order details
     */
    private function getOrderDetails(int $orderId): ?array
    {
        try {
            // This would typically join with orders, customers, and vendors tables
            // For now, we'll simulate order data
            $sql = "SELECT 
                        o.*,
                        v.business_name as vendor_name,
                        v.email as vendor_email,
                        v.phone as vendor_phone
                    FROM orders o
                    LEFT JOIN vendors v ON o.vendor_id = v.id
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
     * Create default preferences
     */
    private function createDefaultPreferences(int $userId, string $userType): array
    {
        try {
            $sql = "INSERT INTO notification_preferences 
                    (user_id, user_type, email_enabled, sms_enabled, push_enabled, 
                     whatsapp_enabled, in_app_enabled, notification_types, created_at)
                    VALUES (?, ?, 1, 1, 1, 0, 1, '[]', NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $userType]);

            return [
                'success' => true,
                'preferences' => [
                    'email_enabled' => true,
                    'sms_enabled' => true,
                    'push_enabled' => true,
                    'whatsapp_enabled' => false,
                    'in_app_enabled' => true,
                    'notification_types' => []
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to create default preferences', [
                'user_id' => $userId,
                'user_type' => $userType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create default preferences: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create preference tables
     */
    private function createPreferenceTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS notification_preferences (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id BIGINT NOT NULL,
            user_type VARCHAR(20) NOT NULL DEFAULT 'vendor',
            email_enabled BOOLEAN DEFAULT TRUE,
            sms_enabled BOOLEAN DEFAULT TRUE,
            push_enabled BOOLEAN DEFAULT TRUE,
            whatsapp_enabled BOOLEAN DEFAULT FALSE,
            in_app_enabled BOOLEAN DEFAULT TRUE,
            notification_types JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_user_type (user_id, user_type),
            INDEX idx_user_id (user_id),
            INDEX idx_user_type (user_type)
        )";

        $this->db->exec($sql);
    }
}