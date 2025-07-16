<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\NotificationDispatcher;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class NotificationDispatcherTest extends TestCase
{
    private NotificationDispatcher $dispatcher;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->dispatcher = new NotificationDispatcher();
    }

    public function testDispatchFromTemplate()
    {
        $templateCode = 'vendor_registration_welcome';
        $data = [
            'vendor_name' => 'Test Vendor',
            'registration_date' => date('Y-m-d')
        ];
        $recipientData = [
            'recipient' => 'test@example.com',
            'recipient_id' => 1
        ];

        $result = $this->dispatcher->dispatchFromTemplate($templateCode, $data, $recipientData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('notification_id', $result);
            $this->assertArrayHasKey('channel_results', $result);
        }
    }

    public function testDispatchCustomNotification()
    {
        $notificationData = [
            'type' => 'test_notification',
            'recipient' => 'test@example.com',
            'recipient_id' => 1,
            'subject' => 'Test Notification',
            'message' => 'This is a test notification',
            'channels' => ['email', 'in_app'],
            'priority' => 'normal'
        ];

        $result = $this->dispatcher->dispatch($notificationData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('notification_id', $result);
            $this->assertArrayHasKey('channel_results', $result);
        }
    }

    public function testSendVendorNotification()
    {
        // This test would require a vendor to exist in the database
        // For now, we'll test the error case
        $result = $this->dispatcher->sendVendorNotification(999, 'vendor_registration_welcome');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Vendor not found', $result['error']);
    }

    public function testSendBulkNotifications()
    {
        $recipients = [
            [
                'email' => 'vendor1@example.com',
                'id' => 1,
                'data' => ['vendor_name' => 'Vendor One']
            ],
            [
                'email' => 'vendor2@example.com',
                'id' => 2,
                'data' => ['vendor_name' => 'Vendor Two']
            ]
        ];

        $result = $this->dispatcher->sendBulkNotifications($recipients, 'vendor_registration_welcome');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('total_recipients', $result);
        $this->assertArrayHasKey('success_count', $result);
        $this->assertArrayHasKey('failure_count', $result);
        $this->assertArrayHasKey('results', $result);
        
        $this->assertEquals(2, $result['total_recipients']);
        $this->assertCount(2, $result['results']);
    }

    public function testGetUserPreferences()
    {
        $result = $this->dispatcher->getUserPreferences(1, 'vendor');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('preferences', $result);
            $preferences = $result['preferences'];
            
            $this->assertArrayHasKey('email_enabled', $preferences);
            $this->assertArrayHasKey('sms_enabled', $preferences);
            $this->assertArrayHasKey('push_enabled', $preferences);
            $this->assertArrayHasKey('whatsapp_enabled', $preferences);
            $this->assertArrayHasKey('in_app_enabled', $preferences);
            $this->assertArrayHasKey('notification_types', $preferences);
        }
    }

    public function testUpdateUserPreferences()
    {
        $preferences = [
            'email_enabled' => true,
            'sms_enabled' => false,
            'push_enabled' => true,
            'whatsapp_enabled' => true,
            'in_app_enabled' => true,
            'notification_types' => ['order_notification', 'stock_alert']
        ];

        $result = $this->dispatcher->updateUserPreferences(1, 'vendor', $preferences);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('message', $result);
        }
    }

    public function testSendStockAlerts()
    {
        $result = $this->dispatcher->sendStockAlerts();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('alerts_sent', $result);
        
        if (isset($result['total_products'])) {
            $this->assertIsInt($result['total_products']);
            $this->assertArrayHasKey('results', $result);
        }
    }

    public function testSendOrderNotification()
    {
        // Test with non-existent order
        $result = $this->dispatcher->sendOrderNotification(999);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testSendDeliveryNotification()
    {
        // Test with non-existent order
        $result = $this->dispatcher->sendDeliveryNotification(999, 'scheduled');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Order not found', $result['error']);
    }

    public function testInvalidTemplateCode()
    {
        $result = $this->dispatcher->dispatchFromTemplate('invalid_template', [], [
            'recipient' => 'test@example.com',
            'recipient_id' => 1
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContains('Template not found', $result['error']);
    }

    public function testNotificationValidation()
    {
        $invalidNotificationData = [
            'type' => 'test_notification',
            // Missing required fields: recipient, message
            'subject' => 'Test Notification',
            'channels' => ['email'],
            'priority' => 'normal'
        ];

        $result = $this->dispatcher->dispatch($invalidNotificationData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testChannelFiltering()
    {
        // Test that invalid channels are handled properly
        $notificationData = [
            'type' => 'test_notification',
            'recipient' => 'test@example.com',
            'recipient_id' => 1,
            'subject' => 'Test Notification',
            'message' => 'This is a test notification',
            'channels' => ['email', 'invalid_channel', 'sms'],
            'priority' => 'normal'
        ];

        $result = $this->dispatcher->dispatch($notificationData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('channel_results', $result);
            $channelResults = $result['channel_results'];
            
            // Should have results for valid channels
            $this->assertArrayHasKey('email', $channelResults);
            $this->assertArrayHasKey('sms', $channelResults);
            
            // Invalid channel should have error
            if (isset($channelResults['invalid_channel'])) {
                $this->assertFalse($channelResults['invalid_channel']['success']);
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}