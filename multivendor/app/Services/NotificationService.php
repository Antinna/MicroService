<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Comprehensive notification service
 */
class NotificationService
{
    private PDO $db;
    private Logger $logger;

    // Notification channels
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_SMS = 'sms';
    const CHANNEL_PUSH = 'push';
    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_IN_APP = 'in_app';

    // Notification priorities
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->createNotificationTables();
    }

    /**
     * Send notification
     */
    public function send(array $notificationData): array
    {
        try {
            // Validate notification data
            $validation = $this->validateNotificationData($notificationData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Create notification record
            $notificationId = $this->createNotificationRecord($notificationData);

            if (!$notificationId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create notification record'
                ];
            }

            // Process notification based on channel
            $channels = $notificationData['channels'] ?? [self::CHANNEL_IN_APP];
            $results = [];

            foreach ($channels as $channel) {
                $channelResult = $this->sendToChannel($notificationId, $channel, $notificationData);
                $results[$channel] = $channelResult;

                // Update notification status for this channel
                $this->updateChannelStatus($notificationId, $channel, $channelResult);
            }

            // Check if any channel succeeded
            $anySuccess = false;
            foreach ($results as $result) {
                if ($result['success']) {
                    $anySuccess = true;
                    break;
                }
            }

            // Update overall notification status
            $status = $anySuccess ? 'sent' : 'failed';
            $this->updateNotificationStatus($notificationId, $status);

            return [
                'success' => $anySuccess,
                'notification_id' => $notificationId,
                'channel_results' => $results,
                'status' => $status
            ];

        } catch (Exception $e) {
            $this->logger->error('Notification sending failed', [
                'error' => $e->getMessage(),
                'notification_data' => $notificationData
            ]);

            return [
                'success' => false,
                'error' => 'Notification sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send notification to specific channel
     */
    private function sendToChannel(int $notificationId, string $channel, array $notificationData): array
    {
        try {
            switch ($channel) {
                case self::CHANNEL_EMAIL:
                    return $this->sendEmail($notificationData);
                case self::CHANNEL_SMS:
                    return $this->sendSms($notificationData);
                case self::CHANNEL_PUSH:
                    return $this->sendPushNotification($notificationData);
                case self::CHANNEL_WHATSAPP:
                    return $this->sendWhatsapp($notificationData);
                case self::CHANNEL_IN_APP:
                    return $this->createInAppNotification($notificationId, $notificationData);
                default:
                    return [
                        'success' => false,
                        'error' => 'Unsupported notification channel: ' . $channel
                    ];
            }

        } catch (Exception $e) {
            $this->logger->error("Failed to send notification to channel: {$channel}", [
                'error' => $e->getMessage(),
                'notification_id' => $notificationId
            ]);

            return [
                'success' => false,
                'error' => "Channel {$channel} failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Send email notification
     */
    private function sendEmail(array $notificationData): array
    {
        // In production, this would integrate with an email service
        // For now, we'll simulate email sending

        $recipient = $notificationData['recipient'];
        $subject = $notificationData['subject'] ?? 'Notification';
        $message = $notificationData['message'];

        // Log the email sending attempt
        $this->logger->info("Sending email notification", [
            'recipient' => $recipient,
            'subject' => $subject
        ]);

        // Simulate email sending success (90% success rate)
        $success = (rand(1, 100) <= 90);

        if ($success) {
            return [
                'success' => true,
                'channel' => self::CHANNEL_EMAIL,
                'message' => "Email sent to {$recipient}"
            ];
        } else {
            return [
                'success' => false,
                'channel' => self::CHANNEL_EMAIL,
                'error' => 'Failed to send email (simulated failure)'
            ];
        }
    }

    /**
     * Send SMS notification
     */
    private function sendSms(array $notificationData): array
    {
        // In production, this would integrate with an SMS gateway
        // For now, we'll simulate SMS sending

        $recipient = $notificationData['recipient'];
        $message = $notificationData['message'];

        // Log the SMS sending attempt
        $this->logger->info("Sending SMS notification", [
            'recipient' => $recipient
        ]);

        // Simulate SMS sending success (85% success rate)
        $success = (rand(1, 100) <= 85);

        if ($success) {
            return [
                'success' => true,
                'channel' => self::CHANNEL_SMS,
                'message' => "SMS sent to {$recipient}"
            ];
        } else {
            return [
                'success' => false,
                'channel' => self::CHANNEL_SMS,
                'error' => 'Failed to send SMS (simulated failure)'
            ];
        }
    }

    /**
     * Send push notification
     */
    private function sendPushNotification(array $notificationData): array
    {
        // In production, this would integrate with FCM, APNS, etc.
        // For now, we'll simulate push notification sending

        $recipient = $notificationData['recipient'];
        $title = $notificationData['subject'] ?? 'Notification';
        $message = $notificationData['message'];

        // Log the push notification sending attempt
        $this->logger->info("Sending push notification", [
            'recipient' => $recipient,
            'title' => $title
        ]);

        // Simulate push notification success (80% success rate)
        $success = (rand(1, 100) <= 80);

        if ($success) {
            return [
                'success' => true,
                'channel' => self::CHANNEL_PUSH,
                'message' => "Push notification sent to {$recipient}"
            ];
        } else {
            return [
                'success' => false,
                'channel' => self::CHANNEL_PUSH,
                'error' => 'Failed to send push notification (simulated failure)'
            ];
        }
    }

    /**
     * Send WhatsApp notification
     */
    private function sendWhatsapp(array $notificationData): array
    {
        // In production, this would integrate with WhatsApp Business API
        // For now, we'll simulate WhatsApp sending

        $recipient = $notificationData['recipient'];
        $message = $notificationData['message'];

        // Log the WhatsApp sending attempt
        $this->logger->info("Sending WhatsApp notification", [
            'recipient' => $recipient
        ]);

        // Simulate WhatsApp sending success (75% success rate)
        $success = (rand(1, 100) <= 75);

        if ($success) {
            return [
                'success' => true,
                'channel' => self::CHANNEL_WHATSAPP,
                'message' => "WhatsApp message sent to {$recipient}"
            ];
        } else {
            return [
                'success' => false,
                'channel' => self::CHANNEL_WHATSAPP,
                'error' => 'Failed to send WhatsApp message (simulated failure)'
            ];
        }
    }

    /**
     * Create in-app notification
     */
    private function createInAppNotification(int $notificationId, array $notificationData): array
    {
        try {
            $recipientId = $notificationData['recipient_id'] ?? null;
            
            if (!$recipientId) {
                return [
                    'success' => false,
                    'channel' => self::CHANNEL_IN_APP,
                    'error' => 'Recipient ID is required for in-app notifications'
                ];
            }

            $sql = "INSERT INTO in_app_notifications (
                        notification_id, recipient_id, title, message,
                        action_url, icon, is_read, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $notificationId,
                $recipientId,
                $notificationData['subject'] ?? 'Notification',
                $notificationData['message'],
                $notificationData['action_url'] ?? null,
                $notificationData['icon'] ?? 'notification'
            ]);

            if ($success) {
                $inAppId = $this->db->lastInsertId();
                
                return [
                    'success' => true,
                    'channel' => self::CHANNEL_IN_APP,
                    'in_app_id' => $inAppId,
                    'message' => "In-app notification created for user {$recipientId}"
                ];
            } else {
                return [
                    'success' => false,
                    'channel' => self::CHANNEL_IN_APP,
                    'error' => 'Failed to create in-app notification'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'channel' => self::CHANNEL_IN_APP,
                'error' => 'In-app notification creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get user notifications
     */
    public function getUserNotifications(int $userId, bool $unreadOnly = false, int $page = 1, int $limit = 20): array
    {
        try {
            $unreadCondition = $unreadOnly ? 'AND is_read = 0' : '';
            $offset = ($page - 1) * $limit;

            $sql = "SELECT ian.*, n.type, n.priority, n.data
                    FROM in_app_notifications ian
                    JOIN notifications n ON ian.notification_id = n.id
                    WHERE ian.recipient_id = ? {$unreadCondition}
                    ORDER BY ian.created_at DESC
                    LIMIT ? OFFSET ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $limit, $offset]);
            $notifications = $stmt->fetchAll();

            // Get total count
            $countSql = "SELECT COUNT(*) FROM in_app_notifications 
                         WHERE recipient_id = ? {$unreadCondition}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute([$userId]);
            $totalCount = $countStmt->fetchColumn();

            // Get unread count
            $unreadSql = "SELECT COUNT(*) FROM in_app_notifications 
                          WHERE recipient_id = ? AND is_read = 0";
            $unreadStmt = $this->db->prepare($unreadSql);
            $unreadStmt->execute([$userId]);
            $unreadCount = $unreadStmt->fetchColumn();

            return [
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalCount,
                    'pages' => ceil($totalCount / $limit)
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get user notifications', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get notifications: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): array
    {
        try {
            $sql = "UPDATE in_app_notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE id = ? AND recipient_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$notificationId, $userId]);

            if ($stmt->rowCount() > 0) {
                return [
                    'success' => true,
                    'message' => 'Notification marked as read'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Notification not found or not owned by user'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to mark notification as read', [
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to mark notification as read: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate notification data
     */
    private function validateNotificationData(array $data): array
    {
        $errors = [];

        // Required fields
        if (empty($data['recipient'])) {
            $errors['recipient'] = 'Recipient is required';
        }

        if (empty($data['message'])) {
            $errors['message'] = 'Message is required';
        }

        if (empty($data['type'])) {
            $errors['type'] = 'Notification type is required';
        }

        // Validate channels if provided
        if (!empty($data['channels'])) {
            if (!is_array($data['channels'])) {
                $errors['channels'] = 'Channels must be an array';
            } else {
                $validChannels = [
                    self::CHANNEL_EMAIL,
                    self::CHANNEL_SMS,
                    self::CHANNEL_PUSH,
                    self::CHANNEL_WHATSAPP,
                    self::CHANNEL_IN_APP
                ];

                foreach ($data['channels'] as $channel) {
                    if (!in_array($channel, $validChannels)) {
                        $errors['channels'] = 'Invalid channel: ' . $channel;
                        break;
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Create notification record
     */
    private function createNotificationRecord(array $data): ?int
    {
        try {
            $sql = "INSERT INTO notifications (
                        type, recipient, subject, message, priority,
                        data, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $data['type'],
                $data['recipient'],
                $data['subject'] ?? null,
                $data['message'],
                $data['priority'] ?? self::PRIORITY_NORMAL,
                json_encode($data['data'] ?? [])
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to create notification record', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return null;
        }
    }

    /**
     * Update notification status
     */
    private function updateNotificationStatus(int $notificationId, string $status): void
    {
        try {
            $sql = "UPDATE notifications 
                    SET status = ?, updated_at = NOW() 
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$status, $notificationId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update notification status', [
                'notification_id' => $notificationId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update channel status
     */
    private function updateChannelStatus(int $notificationId, string $channel, array $result): void
    {
        try {
            $sql = "INSERT INTO notification_channels (
                        notification_id, channel, status, response_data, created_at
                    ) VALUES (?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $notificationId,
                $channel,
                $result['success'] ? 'sent' : 'failed',
                json_encode($result)
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update channel status', [
                'notification_id' => $notificationId,
                'channel' => $channel,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create notification tables
     */
    private function createNotificationTables(): void
    {
        // Notifications table
        $sql1 = "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(255),
            message TEXT NOT NULL,
            priority VARCHAR(20) DEFAULT 'normal',
            data JSON,
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_type (type),
            INDEX idx_recipient (recipient),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        )";

        // Notification channels table
        $sql2 = "CREATE TABLE IF NOT EXISTS notification_channels (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            notification_id BIGINT NOT NULL,
            channel VARCHAR(20) NOT NULL,
            status ENUM('sent', 'failed') NOT NULL,
            response_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_notification_id (notification_id),
            INDEX idx_channel (channel),
            INDEX idx_status (status),
            FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
        )";

        // In-app notifications table
        $sql3 = "CREATE TABLE IF NOT EXISTS in_app_notifications (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            notification_id BIGINT NOT NULL,
            recipient_id BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            action_url VARCHAR(500),
            icon VARCHAR(50),
            is_read BOOLEAN DEFAULT FALSE,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_notification_id (notification_id),
            INDEX idx_recipient_id (recipient_id),
            INDEX idx_is_read (is_read),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
        $this->db->exec($sql3);
    }
}