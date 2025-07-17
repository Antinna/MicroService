<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\PushNotificationService;
use Antinna\Auth\Services\Logger;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for PushNotificationService
 */
class PushNotificationServiceTest extends TestCase
{
    private PushNotificationService $pushService;
    private MockObject $logger;
    private MockObject $auditLogger;
    private MockObject $userRepository;

    protected function setUp(): void
    {
        // Create mocks
        $this->logger = $this->createMock(Logger::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        // Create PushNotificationService instance
        $this->pushService = new PushNotificationService();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->pushService);
        
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($this->pushService, $this->logger);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->pushService, $this->auditLogger);

        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->pushService, $this->userRepository);
    }

    public function testSendLoginAlert(): void
    {
        $userId = 123;
        $loginContext = [
            'device' => 'iPhone 13',
            'location' => 'New York, NY',
            'ip_address' => '192.168.1.1',
            'method' => 'password'
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'push_notification_sent',
                $this->stringContains('login_alert'),
                AuditLogger::SEVERITY_INFO,
                $this->isType('array')
            );

        $result = $this->pushService->sendLoginAlert($userId, $loginContext);

        // Since no devices are registered (placeholder returns empty array), 
        // this should return no devices found
        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendSecurityAlert(): void
    {
        $userId = 123;
        $alertMessage = 'Suspicious login attempt detected';
        $securityContext = [
            'ip_address' => '192.168.1.100',
            'failed_attempts' => 5
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        $result = $this->pushService->sendSecurityAlert($userId, $alertMessage, $securityContext);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendMFARequest(): void
    {
        $userId = 123;
        $mfaCode = '123456';
        $requestContext = [
            'device' => 'Mobile App',
            'ip_address' => '127.0.0.1'
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        $result = $this->pushService->sendMFARequest($userId, $mfaCode, $requestContext);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendPasswordResetAlert(): void
    {
        $userId = 123;
        $resetContext = [
            'reset_method' => 'email_link'
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        $result = $this->pushService->sendPasswordResetAlert($userId, $resetContext);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendAccountLockedAlert(): void
    {
        $userId = 123;
        $lockContext = [
            'reason' => 'Too many failed attempts',
            'duration' => 1800
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        $result = $this->pushService->sendAccountLockedAlert($userId, $lockContext);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendNewDeviceAlert(): void
    {
        $userId = 123;
        $deviceContext = [
            'device_name' => 'iPhone 14',
            'device_type' => 'mobile',
            'location' => 'San Francisco, CA',
            'ip_address' => '10.0.0.1'
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        $result = $this->pushService->sendNewDeviceAlert($userId, $deviceContext);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendSuspiciousActivityAlert(): void
    {
        $userId = 123;
        $activityType = 'unusual_location';
        $activityContext = [
            'location' => 'Unknown Country',
            'confidence' => 0.95
        ];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'email' => 'test@example.com']);

        $result = $this->pushService->sendSuspiciousActivityAlert($userId, $activityType, $activityContext);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_DEVICES', $result['code']);
    }

    public function testSendAuthAlertUserNotFound(): void
    {
        $userId = 999;
        $alertType = PushNotificationService::TYPE_LOGIN_ALERT;

        // Mock user repository to return null
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(null);

        $result = $this->pushService->sendAuthAlert($userId, $alertType);

        $this->assertFalse($result['success']);
        $this->assertEquals('USER_NOT_FOUND', $result['code']);
    }

    public function testRegisterDeviceSuccess(): void
    {
        $userId = 123;
        $deviceData = [
            'token' => 'firebase_token_123',
            'platform' => PushNotificationService::PLATFORM_ANDROID,
            'device_name' => 'Samsung Galaxy S21',
            'device_type' => 'mobile'
        ];

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'push_device_registered',
                $this->stringContains('registered'),
                AuditLogger::SEVERITY_INFO,
                $this->isType('array')
            );

        $result = $this->pushService->registerDevice($userId, $deviceData);

        $this->assertTrue($result['success']);
        $this->assertStringContains('registered', $result['message']);
        $this->assertArrayHasKey('device_id', $result);
        $this->assertEquals('registered', $result['action']);
    }

    public function testRegisterDeviceMissingFields(): void
    {
        $userId = 123;
        $deviceData = [
            'token' => 'firebase_token_123',
            'platform' => PushNotificationService::PLATFORM_ANDROID
            // Missing device_name
        ];

        $result = $this->pushService->registerDevice($userId, $deviceData);

        $this->assertFalse($result['success']);
        $this->assertEquals('MISSING_FIELD', $result['code']);
        $this->assertStringContains('device_name', $result['message']);
    }

    public function testRegisterDeviceInvalidPlatform(): void
    {
        $userId = 123;
        $deviceData = [
            'token' => 'firebase_token_123',
            'platform' => 'invalid_platform',
            'device_name' => 'Test Device'
        ];

        $result = $this->pushService->registerDevice($userId, $deviceData);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_PLATFORM', $result['code']);
    }

    public function testUnregisterDeviceNotFound(): void
    {
        $userId = 123;
        $deviceToken = 'non_existent_token';

        $result = $this->pushService->unregisterDevice($userId, $deviceToken);

        $this->assertFalse($result['success']);
        $this->assertEquals('DEVICE_NOT_FOUND', $result['code']);
    }

    public function testGetNotificationPreferences(): void
    {
        $userId = 123;

        $result = $this->pushService->getNotificationPreferences($userId);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('preferences', $result);
        $this->assertIsArray($result['preferences']);
    }

    public function testUpdateNotificationPreferences(): void
    {
        $userId = 123;
        $preferences = [
            PushNotificationService::TYPE_LOGIN_ALERT => true,
            PushNotificationService::TYPE_SECURITY_ALERT => false,
            PushNotificationService::TYPE_MFA_REQUEST => true
        ];

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'notification_preferences_updated',
                'User updated notification preferences',
                AuditLogger::SEVERITY_INFO,
                $this->callback(function($context) use ($userId, $preferences) {
                    return $context['user_id'] === $userId &&
                           $context['preferences'] === $preferences;
                })
            );

        $result = $this->pushService->updateNotificationPreferences($userId, $preferences);

        $this->assertTrue($result['success']);
        $this->assertStringContains('updated successfully', $result['message']);
        $this->assertEquals($preferences, $result['preferences']);
    }

    public function testUpdateNotificationPreferencesInvalidType(): void
    {
        $userId = 123;
        $preferences = [
            'invalid_type' => true,
            PushNotificationService::TYPE_LOGIN_ALERT => false
        ];

        $result = $this->pushService->updateNotificationPreferences($userId, $preferences);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_TYPE', $result['code']);
        $this->assertStringContains('invalid_type', $result['message']);
    }

    public function testSendBatchNotifications(): void
    {
        $notifications = [
            [
                'user_id' => 123,
                'type' => PushNotificationService::TYPE_LOGIN_ALERT,
                'context' => ['device' => 'iPhone']
            ],
            [
                'user_id' => 124,
                'type' => PushNotificationService::TYPE_SECURITY_ALERT,
                'context' => ['alert_message' => 'Test alert']
            ]
        ];

        // Mock user repository for both users
        $this->userRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturnCallback(function($userId) {
                return ['id' => $userId, 'email' => "user{$userId}@example.com"];
            });

        $result = $this->pushService->sendBatchNotifications($notifications);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('total_notifications', $result);
        $this->assertArrayHasKey('successful_notifications', $result);
        $this->assertEquals(2, $result['total_notifications']);
    }

    public function testSendBatchNotificationsInvalidFormat(): void
    {
        $notifications = [
            [
                'type' => PushNotificationService::TYPE_LOGIN_ALERT
                // Missing user_id
            ]
        ];

        $result = $this->pushService->sendBatchNotifications($notifications);

        $this->assertArrayHasKey('results', $result);
        $this->assertFalse($result['results'][0]['success']);
        $this->assertStringContains('Invalid notification format', $result['results'][0]['message']);
    }

    public function testNotificationTypes(): void
    {
        $this->assertEquals('login_alert', PushNotificationService::TYPE_LOGIN_ALERT);
        $this->assertEquals('security_alert', PushNotificationService::TYPE_SECURITY_ALERT);
        $this->assertEquals('mfa_request', PushNotificationService::TYPE_MFA_REQUEST);
        $this->assertEquals('password_reset', PushNotificationService::TYPE_PASSWORD_RESET);
        $this->assertEquals('account_locked', PushNotificationService::TYPE_ACCOUNT_LOCKED);
        $this->assertEquals('new_device', PushNotificationService::TYPE_NEW_DEVICE);
        $this->assertEquals('suspicious_activity', PushNotificationService::TYPE_SUSPICIOUS_ACTIVITY);
        $this->assertEquals('biometric_enrolled', PushNotificationService::TYPE_BIOMETRIC_ENROLLED);
    }

    public function testPlatforms(): void
    {
        $this->assertEquals('ios', PushNotificationService::PLATFORM_IOS);
        $this->assertEquals('android', PushNotificationService::PLATFORM_ANDROID);
        $this->assertEquals('web', PushNotificationService::PLATFORM_WEB);
    }

    public function testPriorities(): void
    {
        $this->assertEquals('low', PushNotificationService::PRIORITY_LOW);
        $this->assertEquals('normal', PushNotificationService::PRIORITY_NORMAL);
        $this->assertEquals('high', PushNotificationService::PRIORITY_HIGH);
        $this->assertEquals('critical', PushNotificationService::PRIORITY_CRITICAL);
    }

    public function testCreateNotificationContent(): void
    {
        $reflection = new \ReflectionClass($this->pushService);
        $method = $reflection->getMethod('createNotificationContent');
        $method->setAccessible(true);

        $user = ['id' => 123, 'email' => 'test@example.com'];
        $context = [
            'device' => 'iPhone 13',
            'location' => 'New York, NY'
        ];

        $result = $method->invoke(
            $this->pushService,
            PushNotificationService::TYPE_LOGIN_ALERT,
            $context,
            $user
        );

        $this->assertEquals('New Login Detected', $result['title']);
        $this->assertStringContains('iPhone 13', $result['body']);
        $this->assertStringContains('New York, NY', $result['body']);
        $this->assertEquals('normal', $result['priority']);
        $this->assertEquals('security', $result['category']);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals(PushNotificationService::TYPE_LOGIN_ALERT, $result['data']['type']);
    }

    public function testMapPriorityToFirebase(): void
    {
        $reflection = new \ReflectionClass($this->pushService);
        $method = $reflection->getMethod('mapPriorityToFirebase');
        $method->setAccessible(true);

        $this->assertEquals('normal', $method->invoke($this->pushService, PushNotificationService::PRIORITY_LOW));
        $this->assertEquals('normal', $method->invoke($this->pushService, PushNotificationService::PRIORITY_NORMAL));
        $this->assertEquals('high', $method->invoke($this->pushService, PushNotificationService::PRIORITY_HIGH));
        $this->assertEquals('high', $method->invoke($this->pushService, PushNotificationService::PRIORITY_CRITICAL));
        $this->assertEquals('normal', $method->invoke($this->pushService, 'unknown_priority'));
    }

    protected function tearDown(): void
    {
        // Clean up any global state if needed
    }
}