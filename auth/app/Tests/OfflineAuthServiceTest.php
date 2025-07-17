<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\OfflineAuthService;
use Antinna\Auth\Services\Logger;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for OfflineAuthService
 */
class OfflineAuthServiceTest extends TestCase
{
    private OfflineAuthService $offlineAuthService;
    private MockObject $logger;
    private MockObject $auditLogger;
    private MockObject $jwtManager;
    private MockObject $userRepository;

    protected function setUp(): void
    {
        // Create mocks
        $this->logger = $this->createMock(Logger::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->jwtManager = $this->createMock(JWTManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        // Create OfflineAuthService instance
        $this->offlineAuthService = new OfflineAuthService();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->offlineAuthService);
        
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($this->offlineAuthService, $this->logger);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->offlineAuthService, $this->auditLogger);

        $jwtManagerProperty = $reflection->getProperty('jwtManager');
        $jwtManagerProperty->setAccessible(true);
        $jwtManagerProperty->setValue($this->offlineAuthService, $this->jwtManager);

        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->offlineAuthService, $this->userRepository);
    }

    public function testPrepareOfflineDataSuccess(): void
    {
        $userId = 123;
        $options = ['duration' => 86400];

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'email' => 'test@example.com',
                'name' => 'Test User'
            ]);

        // Mock JWT manager for token generation
        $this->jwtManager->expects($this->exactly(2))
            ->method('generateToken')
            ->willReturn(['token' => 'mock_token_' . uniqid()]);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'offline_data_prepared',
                'User data prepared for offline access',
                AuditLogger::SEVERITY_INFO,
                $this->isType('array')
            );

        $result = $this->offlineAuthService->prepareOfflineData($userId, $options);

        $this->assertTrue($result['success']);
        $this->assertEquals('Offline data prepared successfully', $result['message']);
        $this->assertArrayHasKey('cache_id', $result);
        $this->assertArrayHasKey('offline_tokens', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('sync_required_at', $result);
    }

    public function testPrepareOfflineDataUserNotFound(): void
    {
        $userId = 999;

        // Mock user repository to return null
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(null);

        $result = $this->offlineAuthService->prepareOfflineData($userId);

        $this->assertFalse($result['success']);
        $this->assertEquals('USER_NOT_FOUND', $result['code']);
    }

    public function testValidateOfflineAuthCacheNotFound(): void
    {
        $cacheId = 'non_existent_cache';
        $credentials = ['password' => 'test123'];

        $result = $this->offlineAuthService->validateOfflineAuth($cacheId, $credentials);

        $this->assertFalse($result['success']);
        $this->assertEquals('CACHE_NOT_FOUND', $result['code']);
    }

    public function testValidateOfflineTokenValid(): void
    {
        $token = 'valid_offline_token';
        $payload = [
            'user_id' => 123,
            'offline' => true,
            'offline_exp' => time() + 3600,
            'permissions' => ['read', 'write'],
            'cache_id' => 'cache_123'
        ];

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        $result = $this->offlineAuthService->validateOfflineToken($token);

        $this->assertTrue($result['valid']);
        $this->assertEquals(123, $result['user_id']);
        $this->assertEquals(['read', 'write'], $result['permissions']);
        $this->assertEquals('cache_123', $result['cache_id']);
    }

    public function testValidateOfflineTokenInvalid(): void
    {
        $token = 'invalid_token';

        // Mock JWT manager to return failure
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => false,
                'message' => 'Invalid token'
            ]);

        $result = $this->offlineAuthService->validateOfflineToken($token);

        $this->assertFalse($result['valid']);
        $this->assertEquals('INVALID_TOKEN', $result['code']);
    }

    public function testValidateOfflineTokenNotOfflineToken(): void
    {
        $token = 'regular_token';
        $payload = [
            'user_id' => 123,
            'offline' => false // Not an offline token
        ];

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        $result = $this->offlineAuthService->validateOfflineToken($token);

        $this->assertFalse($result['valid']);
        $this->assertEquals('NOT_OFFLINE_TOKEN', $result['code']);
    }

    public function testValidateOfflineTokenExpired(): void
    {
        $token = 'expired_offline_token';
        $payload = [
            'user_id' => 123,
            'offline' => true,
            'offline_exp' => time() - 3600 // Expired 1 hour ago
        ];

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        $result = $this->offlineAuthService->validateOfflineToken($token);

        $this->assertFalse($result['valid']);
        $this->assertEquals('OFFLINE_TOKEN_EXPIRED', $result['code']);
    }

    public function testHandleNetworkDegradationNoCacheAvailable(): void
    {
        $userId = 123;
        $operation = 'authenticate';

        $result = $this->offlineAuthService->handleNetworkDegradation($userId, $operation);

        $this->assertFalse($result['success']);
        $this->assertEquals('NO_OFFLINE_CACHE', $result['code']);
        $this->assertArrayHasKey('fallback_options', $result);
    }

    public function testSyncOfflineDataCacheNotFound(): void
    {
        $cacheId = 'non_existent_cache';
        $localChanges = [];

        $result = $this->offlineAuthService->syncOfflineData($cacheId, $localChanges);

        $this->assertFalse($result['success']);
        $this->assertEquals('CACHE_NOT_FOUND', $result['code']);
    }

    public function testClearOfflineCache(): void
    {
        $cacheId = 'test_cache_id';
        $userId = 123;

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'offline_cache_cleared',
                'Offline cache cleared',
                AuditLogger::SEVERITY_INFO,
                $this->callback(function($context) use ($userId, $cacheId) {
                    return $context['user_id'] === $userId &&
                           $context['cache_id'] === $cacheId &&
                           $context['success'] === true;
                })
            );

        $result = $this->offlineAuthService->clearOfflineCache($cacheId, $userId);

        $this->assertTrue($result['success']);
        $this->assertStringContains('successfully', $result['message']);
    }

    public function testCacheTypes(): void
    {
        $this->assertEquals('token', OfflineAuthService::CACHE_TYPE_TOKEN);
        $this->assertEquals('user_data', OfflineAuthService::CACHE_TYPE_USER_DATA);
        $this->assertEquals('permissions', OfflineAuthService::CACHE_TYPE_PERMISSIONS);
        $this->assertEquals('biometric', OfflineAuthService::CACHE_TYPE_BIOMETRIC);
        $this->assertEquals('settings', OfflineAuthService::CACHE_TYPE_SETTINGS);
    }

    public function testSyncStrategies(): void
    {
        $this->assertEquals('immediate', OfflineAuthService::SYNC_STRATEGY_IMMEDIATE);
        $this->assertEquals('background', OfflineAuthService::SYNC_STRATEGY_BACKGROUND);
        $this->assertEquals('manual', OfflineAuthService::SYNC_STRATEGY_MANUAL);
    }

    public function testOfflineModes(): void
    {
        $this->assertEquals('full_offline', OfflineAuthService::MODE_FULL_OFFLINE);
        $this->assertEquals('partial_offline', OfflineAuthService::MODE_PARTIAL_OFFLINE);
        $this->assertEquals('online_only', OfflineAuthService::MODE_ONLINE_ONLY);
    }

    public function testEncryptDecryptCacheData(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        
        $encryptMethod = $reflection->getMethod('encryptCacheData');
        $encryptMethod->setAccessible(true);
        
        $decryptMethod = $reflection->getMethod('decryptCacheData');
        $decryptMethod->setAccessible(true);

        $originalData = [
            'user_id' => 123,
            'email' => 'test@example.com',
            'permissions' => ['read', 'write'],
            'timestamp' => time()
        ];

        // Encrypt data
        $encrypted = $encryptMethod->invoke($this->offlineAuthService, $originalData);
        $this->assertIsString($encrypted);
        $this->assertNotEquals(json_encode($originalData), $encrypted);

        // Decrypt data
        $decrypted = $decryptMethod->invoke($this->offlineAuthService, $encrypted);
        $this->assertEquals($originalData, $decrypted);
    }

    public function testValidateOfflineCredentialsNoCredentials(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('validateOfflineCredentials');
        $method->setAccessible(true);

        $cachedData = ['user_data' => ['id' => 123]];
        $credentials = []; // No credentials provided

        $result = $method->invoke($this->offlineAuthService, $cachedData, $credentials);

        $this->assertFalse($result['valid']);
        $this->assertEquals('NO_CREDENTIALS', $result['code']);
        $this->assertEquals('missing_credentials', $result['reason']);
    }

    public function testValidateOfflinePin(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('validateOfflinePin');
        $method->setAccessible(true);

        $pin = '123456';
        $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
        
        $cachedData = [
            'user_data' => [
                'offline_pin_hash' => $hashedPin
            ]
        ];

        // Test valid PIN
        $result = $method->invoke($this->offlineAuthService, $cachedData, $pin);
        $this->assertTrue($result['valid']);
        $this->assertEquals('PIN_VALID', $result['code']);

        // Test invalid PIN
        $result = $method->invoke($this->offlineAuthService, $cachedData, '654321');
        $this->assertFalse($result['valid']);
        $this->assertEquals('PIN_INVALID', $result['code']);
    }

    public function testValidateOfflinePinNotConfigured(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('validateOfflinePin');
        $method->setAccessible(true);

        $cachedData = [
            'user_data' => [] // No PIN configured
        ];

        $result = $method->invoke($this->offlineAuthService, $cachedData, '123456');

        $this->assertFalse($result['valid']);
        $this->assertEquals('NO_PIN_CONFIGURED', $result['code']);
        $this->assertEquals('no_pin', $result['reason']);
    }

    public function testValidateOfflinePassword(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('validateOfflinePassword');
        $method->setAccessible(true);

        $password = 'test_password';
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $cachedData = [
            'user_data' => [
                'password_hash' => $hashedPassword
            ]
        ];

        // Test valid password
        $result = $method->invoke($this->offlineAuthService, $cachedData, $password);
        $this->assertTrue($result['valid']);
        $this->assertEquals('PASSWORD_VALID', $result['code']);

        // Test invalid password
        $result = $method->invoke($this->offlineAuthService, $cachedData, 'wrong_password');
        $this->assertFalse($result['valid']);
        $this->assertEquals('PASSWORD_INVALID', $result['code']);
    }

    public function testGenerateOfflineSessionToken(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('generateOfflineSessionToken');
        $method->setAccessible(true);

        $userData = [
            'id' => 123,
            'email' => 'test@example.com'
        ];

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('generateToken')
            ->willReturn(['token' => 'mock_session_token']);

        $token = $method->invoke($this->offlineAuthService, $userData);

        $this->assertEquals('mock_session_token', $token);
    }

    public function testIsSyncRequired(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('isSyncRequired');
        $method->setAccessible(true);

        // Test sync required (old timestamp)
        $cachedData = [
            'sync_timestamp' => time() - 400 // 400 seconds ago (> 300 second interval)
        ];
        $result = $method->invoke($this->offlineAuthService, $cachedData);
        $this->assertTrue($result);

        // Test sync not required (recent timestamp)
        $cachedData = [
            'sync_timestamp' => time() - 100 // 100 seconds ago (< 300 second interval)
        ];
        $result = $method->invoke($this->offlineAuthService, $cachedData);
        $this->assertFalse($result);

        // Test sync required (no timestamp)
        $cachedData = [];
        $result = $method->invoke($this->offlineAuthService, $cachedData);
        $this->assertTrue($result);
    }

    public function testDetermineOfflineMode(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('determineOfflineMode');
        $method->setAccessible(true);

        // Test full offline mode
        $capabilities = [
            'biometric_auth' => true,
            'pin_auth' => false,
            'password_auth' => true
        ];
        $result = $method->invoke($this->offlineAuthService, $capabilities);
        $this->assertEquals(OfflineAuthService::MODE_FULL_OFFLINE, $result);

        // Test partial offline mode
        $capabilities = [
            'biometric_auth' => false,
            'pin_auth' => false,
            'password_auth' => true
        ];
        $result = $method->invoke($this->offlineAuthService, $capabilities);
        $this->assertEquals(OfflineAuthService::MODE_PARTIAL_OFFLINE, $result);

        // Test online only mode
        $capabilities = [];
        $result = $method->invoke($this->offlineAuthService, $capabilities);
        $this->assertEquals(OfflineAuthService::MODE_ONLINE_ONLY, $result);
    }

    public function testGetAvailableOfflineOperations(): void
    {
        $reflection = new \ReflectionClass($this->offlineAuthService);
        $method = $reflection->getMethod('getAvailableOfflineOperations');
        $method->setAccessible(true);

        // Test with biometric capabilities
        $capabilities = [
            'biometric_auth' => true,
            'pin_auth' => false
        ];
        $result = $method->invoke($this->offlineAuthService, $capabilities, 'authenticate');
        
        $this->assertContains('view_profile', $result);
        $this->assertContains('authenticate', $result);
        $this->assertContains('view_sensitive_data', $result);

        // Test without advanced capabilities
        $capabilities = [
            'biometric_auth' => false,
            'pin_auth' => false
        ];
        $result = $method->invoke($this->offlineAuthService, $capabilities, 'authenticate');
        
        $this->assertContains('view_profile', $result);
        $this->assertNotContains('authenticate', $result);
    }

    protected function tearDown(): void
    {
        // Clean up any global state if needed
    }
}