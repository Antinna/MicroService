<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Notification template management service
 */
class NotificationTemplateManager
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->createTemplateTables();
        $this->registerDefaultTemplates();
    }

    /**
     * Create notification from template
     */
    public function createFromTemplate(string $templateCode, array $data, array $recipientData): array
    {
        try {
            // Get template
            $template = $this->getTemplateByCode($templateCode);
            if (!$template) {
                return [
                    'success' => false,
                    'error' => 'Template not found: ' . $templateCode
                ];
            }

            // Parse template
            $parsedTemplate = $this->parseTemplate($template, $data);

            // Create notification data
            $notificationData = [
                'type' => $template['notification_type'],
                'recipient' => $recipientData['recipient'],
                'recipient_id' => $recipientData['recipient_id'] ?? null,
                'subject' => $parsedTemplate['subject'],
                'message' => $parsedTemplate['message'],
                'channels' => $template['channels'] ? json_decode($template['channels'], true) : ['in_app'],
                'priority' => $template['priority'] ?? 'normal',
                'data' => $data
            ];

            return [
                'success' => true,
                'notification_data' => $notificationData
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to create notification from template', [
                'template_code' => $templateCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create notification from template: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get template by code
     */
    private function getTemplateByCode(string $code): ?array
    {
        try {
            $sql = "SELECT * FROM notification_templates WHERE code = ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$code]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get template by code', [
                'code' => $code,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Parse template with data
     */
    private function parseTemplate(array $template, array $data): array
    {
        $subject = $this->replacePlaceholders($template['subject'], $data);
        $message = $this->replacePlaceholders($template['message'], $data);

        return [
            'subject' => $subject,
            'message' => $message
        ];
    }

    /**
     * Replace placeholders in text
     */
    private function replacePlaceholders(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $text = str_replace('{{' . $key . '}}', $value, $text);
            }
        }

        return $text;
    }

    /**
     * Create template tables
     */
    private function createTemplateTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS notification_templates (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(100) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            notification_type VARCHAR(50) NOT NULL,
            subject VARCHAR(255),
            message TEXT NOT NULL,
            channels JSON,
            priority VARCHAR(20) DEFAULT 'normal',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_code (code),
            INDEX idx_type (notification_type),
            INDEX idx_active (is_active)
        )";

        $this->db->exec($sql);
    }

    /**
     * Register default templates
     */
    private function registerDefaultTemplates(): void
    {
        $templates = [
            [
                'code' => 'vendor_registration_welcome',
                'name' => 'Vendor Registration Welcome',
                'description' => 'Welcome message for newly registered vendors',
                'notification_type' => 'vendor_registration',
                'subject' => 'Welcome to Our Platform, {{vendor_name}}!',
                'message' => 'Dear {{vendor_name}}, welcome to our multivendor platform! Your registration is being processed.',
                'channels' => json_encode(['email', 'sms', 'in_app']),
                'priority' => 'normal'
            ],
            [
                'code' => 'vendor_kyc_approved',
                'name' => 'KYC Approval Notification',
                'description' => 'Notification when vendor KYC is approved',
                'notification_type' => 'kyc_status',
                'subject' => 'KYC Approved - {{vendor_name}}',
                'message' => 'Congratulations {{vendor_name}}! Your KYC documents have been approved. You can now start selling.',
                'channels' => json_encode(['email', 'sms', 'in_app']),
                'priority' => 'high'
            ],
            [
                'code' => 'low_stock_alert',
                'name' => 'Low Stock Alert',
                'description' => 'Alert when product stock is running low',
                'notification_type' => 'stock_alert',
                'subject' => 'Low Stock Alert - {{product_name}}',
                'message' => 'Your product "{{product_name}}" is running low. Current stock: {{current_stock}} units.',
                'channels' => json_encode(['email', 'in_app']),
                'priority' => 'high'
            ],
            [
                'code' => 'order_received',
                'name' => 'New Order Notification',
                'description' => 'Notification when vendor receives a new order',
                'notification_type' => 'order_notification',
                'subject' => 'New Order #{{order_id}}',
                'message' => 'You have received a new order #{{order_id}} worth ₹{{order_amount}}. Please prepare for delivery.',
                'channels' => json_encode(['sms', 'in_app', 'push']),
                'priority' => 'high'
            ],
            [
                'code' => 'delivery_scheduled',
                'name' => 'Delivery Scheduled',
                'description' => 'Notification when delivery is scheduled',
                'notification_type' => 'delivery_notification',
                'subject' => 'Delivery Scheduled - Order #{{order_id}}',
                'message' => 'Your order #{{order_id}} is scheduled for delivery on {{delivery_date}} between {{delivery_time}}.',
                'channels' => json_encode(['sms', 'email', 'push']),
                'priority' => 'normal'
            ],
            [
                'code' => 'delivery_failed',
                'name' => 'Delivery Failed',
                'description' => 'Notification when delivery fails',
                'notification_type' => 'delivery_notification',
                'subject' => 'Delivery Failed - Order #{{order_id}}',
                'message' => 'Unfortunately, delivery for order #{{order_id}} failed. Reason: {{failure_reason}}. We will reschedule.',
                'channels' => json_encode(['sms', 'email', 'in_app']),
                'priority' => 'urgent'
            ]
        ];

        foreach ($templates as $template) {
            try {
                $sql = "INSERT IGNORE INTO notification_templates 
                        (code, name, description, notification_type, subject, message, channels, priority)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $template['code'],
                    $template['name'],
                    $template['description'],
                    $template['notification_type'],
                    $template['subject'],
                    $template['message'],
                    $template['channels'],
                    $template['priority']
                ]);

            } catch (Exception $e) {
                $this->logger->error('Failed to register template', [
                    'template_code' => $template['code'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}