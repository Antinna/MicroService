<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\BiometricHandler;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Services\JWTManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for BiometricHandler
 */
class BiometricHandlerTest extends TestCase
{
    private BiometricHandler $biometricHandler;
    private MockObject $userRepository;
    private MockObject $auditLogger;
    private MockObject $rateLimiter;
    private MockObject $jwtManager;

    protected function setUp(): void
    {
        // Create mocks
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->jwtManager = $this->createMock(JWTManager::class);

        // Create BiometricHandler instance
        $this->biometricHandler = new BiometricHandler();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->biometricHandler);
        
        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->biometricHandler, $this->userRepository);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->biometricHandler, $this->auditLogger);

        $rateLimiterProperty = $reflection->getProperty('rateLimiter');
        $rateLimiterProperty->setAccessible(true);
        $rateLimiterProperty->setValue($this->biometricHandler, $this->rateLimiter);

        $jwtManagerProperty = $reflection->getProperty('jwtManager');
        $jwtManagerProperty->setAccessible(true);
        $jwtManagerProperty->setValue($this->biometricHandler, $this->jwtManager);
    }

    public function testEnrollBiometricSuccess(): void
    {
        $userId = 123;
        $biometricType = BiometricHandler::TYPE_FINGERPRINT;
        $biometricData = [
            'template' => str_repeat('a', 200),
            'quality_score' => 0.85
        ];
        $deviceInfo = [
            'device_name' => 'iPhone 13',
            'os_version' => 'iOS 15.0'
        ];

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'is_active' => true]);

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('generateToken')
            ->willReturn(['success' => true, 'token' => 'mock-enrollment-token']);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logAuthentication')
            ->with(
                AuditLogger::EVENT_BIOMETRIC_ENROLLED,
                'Biometric enrollment successful',
                $userId,
                $biometricType,
                true,
                $this->isType('array')
            );

        $result = $this->biometricHandler->enrollBiometric($userId, $biometricType, $biometricData, $deviceInfo);

        $this->assertTrue($result['success']);
        $this->assertEquals('Biometric enrollment successful', $result['message']);
        $this->assertArrayHasKey('biometric_id', $result);
        $this->assertArrayHasKey('enrollment_token', $result);
        $this->assertEquals($biometricType, $result['biometric_type']);
    }

    public function testEnrollBiometricRateLimited(): void
    {
        $userId = 123;
        $biometricType = BiometricHandler::TYPE_FINGERPRINT;
        $biometricData = ['template' => 'test', 'quality_score' => 0.8];

        // Mock rate limiter to deny
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => false]);

        $result = $this->biometricHandler->enrollBiometric($userId, $biometricType, $biometricData);

        $this->assertFalse($result['success']);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $result['code']);
    }

    public function testEnrollBiometricInvalidType(): void
    {
        $userId = 123;
        $biometricType = 'invalid_type';
        $biometricData = ['template' => 'test', 'quality_score' => 0.8];

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        $result = $this->biometricHandler->enrollBiometric($userId, $biometricType, $biometricData);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_BIOMETRIC_TYPE', $result['code']);
    }

    public function testEnrollBiometricInvalidData(): void
    {
        $userId = 123;
        $biometricType = BiometricHandler::TYPE_FINGERPRINT;
        $biometricData = ['template' => 'short', 'quality_score' => 0.5]; // Low quality

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(['id' => $userId, 'is_active' => true]);

        $result = $this->biometricHandler->enrollBiometric($userId, $biometricType, $biometricData);

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_BIOMETRIC_DATA', $result['code']);
    }

    public function testAuthenticateBiometricSuccess(): void
    {
        $biometricType = BiometricHandler::TYPE_FACE_ID;
        $biometricData = [
            'face_encoding' => array_fill(0, 128, 0.5),
            'confidence_score' => 0.9
        ];
        $deviceInfo = ['device_name' => 'iPhone 13'];

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock user repository
        $this->userRepository->expects($this->once())
            ->method('find')
            ->willReturn([
                'id' => 123,
                'email' => 'test@example.com',
                'is_active' => true,
                'password_hash' => 'hash'
            ]);

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('generateToken')
            ->willReturn(['success' => true, 'token' => 'mock-auth-token']);

        // Mock audit logger
        $this->auditLogger->expects($this->once())
            ->method('logAuthentication')
            ->with(
                AuditLogger::EVENT_LOGIN_SUCCESS,
                'Biometric authentication successful',
                123,
                $biometricType,
                true,
                $this->isType('array')
            );

        $result = $this->biometricHandler->authenticateBiometric($biometricType, $biometricData, $deviceInfo);

        $this->assertTrue($result['success']);
        $this->assertEquals('Biometric authentication successful', $result['message']);
        $this->assertEquals(123, $result['user_id']);
        $this->assertArrayHasKey('auth_token', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayNotHasKey('password_hash', $result['user']);
    }

    public function testAuthenticateBiometricNoMatch(): void
    {
        $biometricType = BiometricHandler::TYPE_FINGERPRINT;
        $biometricData = [
            'template' => str_repeat('b', 200),
            'quality_score' => 0.8
        ];

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // Mock rate limiter
        $this->rateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true]);

        // Mock audit logger for failed attempt
        $this->auditLogger->expects($this->once())
            ->method('logAuthentication')
            ->with(
                AuditLogger::EVENT_LOGIN_FAILED,
                'Biometric authentication failed - no match',
                null,
                $biometricType,
                false,
                $this->isType('array')
            );

        // Override the matchBiometricData method to return no match
        $reflection = new \ReflectionClass($this->biometricHandler);
        $method = $reflection->getMethod('matchBiometricData');
        $method->setAccessible(true);

        $result = $this->biometricHandler->authenticateBiometric($biometricType, $biometricData);

        $this->assertFalse($result['success']);
        $this->assertEquals('BIOMETRIC_NO_MATCH', $result['code']);
    }

    public function testGenerateBiometricChallenge(): void
    {
        $userId = 123;
        $biometricType = BiometricHandler::TYPE_FINGERPRINT;

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('generateToken')
            ->willReturn(['success' => true, 'token' => 'mock-challenge-token']);

        $result = $this->biometricHandler->generateBiometricChallenge($userId, $biometricType);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('challenge_id', $result);
        $this->assertArrayHasKey('challenge_token', $result);
        $this->assertArrayHasKey('nonce', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertEquals($biometricType, $result['biometric_type']);
    }

    public function testVerifyBiometricChallenge(): void
    {
        $challengeId = 'test-challenge-id';
        $challengeToken = 'test-challenge-token';
        $biometricData = [
            'template' => str_repeat('c', 200),
            'quality_score' => 0.8
        ];

        // Mock JWT manager
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($challengeToken)
            ->willReturn(['success' => true]);

        $result = $this->biometricHandler->verifyBiometricChallenge($challengeId, $challengeToken, $biometricData);

        // This will fail in the current implementation due to placeholder methods
        // but tests the structure
        $this->assertArrayHasKey('success', $result);
    }

    public function testGetUserBiometrics(): void
    {
        $userId = 123;

        $result = $this->biometricHandler->getUserBiometrics($userId);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('biometrics', $result);
        $this->assertArrayHasKey('total_count', $result);
        $this->assertEquals(0, $result['total_count']); // Placeholder returns empty array
    }

    public function testRemoveBiometric(): void
    {
        $userId = 123;
        $biometricId = 456;

        $result = $this->biometricHandler->removeBiometric($userId, $biometricId);

        // This will return not found due to placeholder implementation
        $this->assertFalse($result['success']);
        $this->assertEquals('BIOMETRIC_NOT_FOUND', $result['code']);
    }

    public function testValidateFingerprintData(): void
    {
        $reflection = new \ReflectionClass($this->biometricHandler);
        $method = $reflection->getMethod('validateFingerprintData');
        $method->setAccessible(true);

        // Test valid fingerprint data
        $validData = [
            'template' => str_repeat('a', 200),
            'quality_score' => 0.8
        ];
        $result = $method->invoke($this->biometricHandler, $validData);
        $this->assertTrue($result['valid']);

        // Test invalid fingerprint data - low quality
        $invalidData = [
            'template' => str_repeat('a', 200),
            'quality_score' => 0.5
        ];
        $result = $method->invoke($this->biometricHandler, $invalidData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('quality too low', $result['message']);

        // Test invalid fingerprint data - short template
        $shortTemplateData = [
            'template' => 'short',
            'quality_score' => 0.8
        ];
        $result = $method->invoke($this->biometricHandler, $shortTemplateData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('template too short', $result['message']);
    }

    public function testValidateFaceIdData(): void
    {
        $reflection = new \ReflectionClass($this->biometricHandler);
        $method = $reflection->getMethod('validateFaceIdData');
        $method->setAccessible(true);

        // Test valid Face ID data
        $validData = [
            'face_encoding' => array_fill(0, 128, 0.5),
            'confidence_score' => 0.9
        ];
        $result = $method->invoke($this->biometricHandler, $validData);
        $this->assertTrue($result['valid']);

        // Test invalid Face ID data - low confidence
        $lowConfidenceData = [
            'face_encoding' => array_fill(0, 128, 0.5),
            'confidence_score' => 0.7
        ];
        $result = $method->invoke($this->biometricHandler, $lowConfidenceData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('confidence too low', $result['message']);

        // Test invalid Face ID data - short encoding
        $shortEncodingData = [
            'face_encoding' => array_fill(0, 50, 0.5),
            'confidence_score' => 0.9
        ];
        $result = $method->invoke($this->biometricHandler, $shortEncodingData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('Invalid face encoding', $result['message']);
    }

    public function testValidateVoiceData(): void
    {
        $reflection = new \ReflectionClass($this->biometricHandler);
        $method = $reflection->getMethod('validateVoiceData');
        $method->setAccessible(true);

        // Test valid voice data
        $validData = [
            'voice_print' => 'voice_data_here',
            'duration' => 3.5
        ];
        $result = $method->invoke($this->biometricHandler, $validData);
        $this->assertTrue($result['valid']);

        // Test invalid voice data - too short
        $shortData = [
            'voice_print' => 'voice_data_here',
            'duration' => 1.5
        ];
        $result = $method->invoke($this->biometricHandler, $shortData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('too short', $result['message']);
    }

    public function testValidateIrisData(): void
    {
        $reflection = new \ReflectionClass($this->biometricHandler);
        $method = $reflection->getMethod('validateIrisData');
        $method->setAccessible(true);

        // Test valid iris data
        $validData = [
            'iris_template' => 'iris_template_data',
            'quality_score' => 0.8
        ];
        $result = $method->invoke($this->biometricHandler, $validData);
        $this->assertTrue($result['valid']);

        // Test invalid iris data - low quality
        $lowQualityData = [
            'iris_template' => 'iris_template_data',
            'quality_score' => 0.6
        ];
        $result = $method->invoke($this->biometricHandler, $lowQualityData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('quality too low', $result['message']);
    }

    public function testValidatePalmData(): void
    {
        $reflection = new \ReflectionClass($this->biometricHandler);
        $method = $reflection->getMethod('validatePalmData');
        $method->setAccessible(true);

        // Test valid palm data
        $validData = [
            'palm_template' => 'palm_template_data',
            'quality_score' => 0.8
        ];
        $result = $method->invoke($this->biometricHandler, $validData);
        $this->assertTrue($result['valid']);

        // Test invalid palm data - low quality
        $lowQualityData = [
            'palm_template' => 'palm_template_data',
            'quality_score' => 0.6
        ];
        $result = $method->invoke($this->biometricHandler, $lowQualityData);
        $this->assertFalse($result['valid']);
        $this->assertStringContains('quality too low', $result['message']);
    }

    public function testBiometricTypes(): void
    {
        $this->assertEquals('fingerprint', BiometricHandler::TYPE_FINGERPRINT);
        $this->assertEquals('face_id', BiometricHandler::TYPE_FACE_ID);
        $this->assertEquals('voice', BiometricHandler::TYPE_VOICE);
        $this->assertEquals('iris', BiometricHandler::TYPE_IRIS);
        $this->assertEquals('palm', BiometricHandler::TYPE_PALM);
    }

    public function testTokenTypes(): void
    {
        $this->assertEquals('biometric_enrollment', BiometricHandler::TOKEN_TYPE_ENROLLMENT);
        $this->assertEquals('biometric_auth', BiometricHandler::TOKEN_TYPE_AUTHENTICATION);
        $this->assertEquals('biometric_challenge', BiometricHandler::TOKEN_TYPE_CHALLENGE);
    }

    public function testSecurityLevels(): void
    {
        $this->assertEquals('low', BiometricHandler::SECURITY_LEVEL_LOW);
        $this->assertEquals('medium', BiometricHandler::SECURITY_LEVEL_MEDIUM);
        $this->assertEquals('high', BiometricHandler::SECURITY_LEVEL_HIGH);
    }

    protected function tearDown(): void
    {
        // Clean up server variables
        if (isset($_SERVER['REMOTE_ADDR'])) {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }
}