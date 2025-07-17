<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\TokenValidator;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test suite for TokenValidator
 */
class TokenValidatorTest extends TestCase
{
    private TokenValidator $tokenValidator;
    private MockObject $jwtManager;
    private MockObject $userRepository;
    private MockObject $auditLogger;

    protected function setUp(): void
    {
        // Create mocks
        $this->jwtManager = $this->createMock(JWTManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        // Create TokenValidator instance
        $this->tokenValidator = new TokenValidator();

        // Use reflection to inject mocks
        $reflection = new \ReflectionClass($this->tokenValidator);
        
        $jwtProperty = $reflection->getProperty('jwtManager');
        $jwtProperty->setAccessible(true);
        $jwtProperty->setValue($this->tokenValidator, $this->jwtManager);

        $userRepoProperty = $reflection->getProperty('userRepository');
        $userRepoProperty->setAccessible(true);
        $userRepoProperty->setValue($this->tokenValidator, $this->userRepository);

        $auditLoggerProperty = $reflection->getProperty('auditLogger');
        $auditLoggerProperty->setAccessible(true);
        $auditLoggerProperty->setValue($this->tokenValidator, $this->auditLogger);
    }

    public function testValidTokenValidation(): void
    {
        $token = 'valid.jwt.token';
        $payload = [
            'user_id' => 1,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read', 'user:write'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'iss' => 'auth-service',
            'jti' => 'token-123'
        ];

        // Mock JWT validation
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Mock user validation
        $user = [
            'id' => 1,
            'email' => 'test@example.com',
            'is_active' => true,
            'role' => 'user'
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Test validation
        $result = $this->tokenValidator->validateToken($token, ['user:read'], false);

        $this->assertTrue($result['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_SUCCESS, $result['status']);
        $this->assertEquals('Token is valid', $result['message']);
        $this->assertEquals($payload, $result['token_info']);
        $this->assertEquals($user, $result['user_info']);
        $this->assertEquals(['user:read', 'user:write'], $result['scopes']);
        $this->assertFalse($result['cached']);
        $this->assertArrayHasKey('validation_time', $result);
    }

    public function testInvalidTokenValidation(): void
    {
        $token = 'invalid.jwt.token';

        // Mock JWT validation failure
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => false,
                'message' => 'Invalid token signature',
                'code' => 'INVALID_SIGNATURE'
            ]);

        // Test validation
        $result = $this->tokenValidator->validateToken($token, [], false);

        $this->assertFalse($result['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_INVALID, $result['status']);
        $this->assertEquals('Invalid token signature', $result['message']);
        $this->assertEquals('INVALID_SIGNATURE', $result['code']);
        $this->assertFalse($result['cached']);
    }

    public function testTokenWithInactiveUser(): void
    {
        $token = 'valid.jwt.token';
        $payload = [
            'user_id' => 1,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'jti' => 'token-123'
        ];

        // Mock JWT validation
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Mock inactive user
        $user = [
            'id' => 1,
            'email' => 'test@example.com',
            'is_active' => false,
            'role' => 'user'
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Test validation
        $result = $this->tokenValidator->validateToken($token, [], false);

        $this->assertFalse($result['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_INVALID, $result['status']);
        $this->assertEquals('User account is inactive', $result['message']);
        $this->assertEquals('USER_INACTIVE', $result['code']);
    }

    public function testTokenWithInsufficientScopes(): void
    {
        $token = 'valid.jwt.token';
        $payload = [
            'user_id' => 1,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'jti' => 'token-123'
        ];

        // Mock JWT validation
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Mock user validation
        $user = [
            'id' => 1,
            'email' => 'test@example.com',
            'is_active' => true,
            'role' => 'user'
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Test validation with required scope not in token
        $result = $this->tokenValidator->validateToken($token, ['admin:write'], false);

        $this->assertFalse($result['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_INSUFFICIENT_SCOPE, $result['status']);
        $this->assertStringContains('Insufficient scopes', $result['message']);
        $this->assertEquals('INSUFFICIENT_SCOPE', $result['code']);
        $this->assertEquals(['admin:write'], $result['required_scopes']);
        $this->assertEquals(['user:read'], $result['available_scopes']);
    }

    public function testServiceTokenValidation(): void
    {
        $token = 'service.jwt.token';
        $payload = [
            'service_id' => 'payment-service',
            'token_type' => TokenValidator::TOKEN_TYPE_SERVICE,
            'scopes' => ['service:access'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'jti' => 'service-token-123'
        ];

        // Mock JWT validation
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Test service token validation
        $result = $this->tokenValidator->validateServiceToken($token, 'payment-service');

        $this->assertTrue($result['valid']);
        $this->assertEquals('payment-service', $result['service_id']);
        $this->assertEquals($payload, $result['token_info']);
        $this->assertEquals(['service:access'], $result['scopes']);
    }

    public function testServiceTokenWithWrongService(): void
    {
        $token = 'service.jwt.token';
        $payload = [
            'service_id' => 'payment-service',
            'token_type' => TokenValidator::TOKEN_TYPE_SERVICE,
            'scopes' => ['service:access'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'jti' => 'service-token-123'
        ];

        // Mock JWT validation
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Test service token validation with wrong expected service
        $result = $this->tokenValidator->validateServiceToken($token, 'delivery-service');

        $this->assertFalse($result['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_INVALID, $result['status']);
        $this->assertEquals('Token not issued for expected service', $result['message']);
        $this->assertEquals('INVALID_SERVICE', $result['code']);
    }

    public function testBatchTokenValidation(): void
    {
        $tokens = [
            'valid.token.1',
            'invalid.token.2',
            'valid.token.3'
        ];

        // Mock validations
        $this->jwtManager->expects($this->exactly(3))
            ->method('validateToken')
            ->willReturnOnConsecutiveCalls(
                [
                    'success' => true,
                    'payload' => [
                        'user_id' => 1,
                        'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
                        'scopes' => ['user:read'],
                        'exp' => time() + 3600,
                        'jti' => 'token-1'
                    ]
                ],
                [
                    'success' => false,
                    'message' => 'Invalid token',
                    'code' => 'INVALID_TOKEN'
                ],
                [
                    'success' => true,
                    'payload' => [
                        'user_id' => 2,
                        'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
                        'scopes' => ['user:write'],
                        'exp' => time() + 3600,
                        'jti' => 'token-3'
                    ]
                ]
            );

        // Mock user validations for valid tokens
        $this->userRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'is_active' => true],
                ['id' => 2, 'is_active' => true]
            );

        // Test batch validation
        $result = $this->tokenValidator->validateTokensBatch($tokens);

        $this->assertEquals(3, $result['total_tokens']);
        $this->assertEquals(2, $result['valid_tokens']);
        $this->assertEquals(1, $result['invalid_tokens']);
        $this->assertArrayHasKey('batch_validation_time', $result);
        $this->assertCount(3, $result['results']);
        $this->assertTrue($result['results'][0]['valid']);
        $this->assertFalse($result['results'][1]['valid']);
        $this->assertTrue($result['results'][2]['valid']);
    }

    public function testTokenInspection(): void
    {
        $token = 'jwt.token.to.inspect';
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'user_id' => 1,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'iss' => 'auth-service',
            'jti' => 'token-123'
        ];

        // Mock JWT decoding without verification
        $this->jwtManager->expects($this->once())
            ->method('decodeToken')
            ->with($token, false)
            ->willReturn([
                'success' => true,
                'header' => $header,
                'payload' => $payload
            ]);

        // Test token inspection
        $result = $this->tokenValidator->inspectToken($token);

        $this->assertTrue($result['valid_structure']);
        $this->assertEquals($header, $result['header']);
        $this->assertEquals($payload, $result['payload']);
        $this->assertEquals(TokenValidator::TOKEN_TYPE_ACCESS, $result['token_type']);
        $this->assertEquals(1, $result['user_id']);
        $this->assertEquals(['user:read'], $result['scopes']);
        $this->assertFalse($result['is_expired']);
        $this->assertEquals('auth-service', $result['issuer']);
        $this->assertEquals('token-123', $result['jti']);
    }

    public function testTokenCaching(): void
    {
        $token = 'cacheable.jwt.token';
        $payload = [
            'user_id' => 1,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'jti' => 'token-123'
        ];

        // Mock JWT validation (should only be called once due to caching)
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Mock user validation (should only be called once due to caching)
        $user = ['id' => 1, 'is_active' => true];
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // First validation (should hit JWT and user validation)
        $result1 = $this->tokenValidator->validateToken($token, ['user:read'], true);
        $this->assertTrue($result1['valid']);
        $this->assertFalse($result1['cached']);

        // Second validation (should hit cache)
        $result2 = $this->tokenValidator->validateToken($token, ['user:read'], true);
        $this->assertTrue($result2['valid']);
        $this->assertTrue($result2['cached']);
    }

    public function testWildcardScopeMatching(): void
    {
        $token = 'wildcard.jwt.token';
        $payload = [
            'user_id' => 1,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:*', 'admin:read'],
            'iat' => time() - 300,
            'exp' => time() + 3600,
            'jti' => 'token-123'
        ];

        // Mock JWT validation
        $this->jwtManager->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'success' => true,
                'payload' => $payload
            ]);

        // Mock user validation
        $user = ['id' => 1, 'is_active' => true];
        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        // Test validation with scopes that should match wildcard
        $result = $this->tokenValidator->validateToken($token, ['user:read', 'user:write', 'admin:read'], false);

        $this->assertTrue($result['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_SUCCESS, $result['status']);
    }

    public function testValidationStatistics(): void
    {
        $stats = $this->tokenValidator->getValidationStats();

        $this->assertArrayHasKey('cache_size', $stats);
        $this->assertArrayHasKey('max_cache_size', $stats);
        $this->assertArrayHasKey('cache_timeout', $stats);
        $this->assertArrayHasKey('cache_hit_ratio', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
        $this->assertArrayHasKey('uptime', $stats);
        
        $this->assertIsInt($stats['cache_size']);
        $this->assertIsInt($stats['max_cache_size']);
        $this->assertIsInt($stats['cache_timeout']);
        $this->assertIsFloat($stats['cache_hit_ratio']);
        $this->assertIsInt($stats['memory_usage']);
    }

    public function testCacheClear(): void
    {
        // This test verifies that cache can be cleared
        // In a real implementation, we would populate cache first
        $this->tokenValidator->clearCache();
        
        $stats = $this->tokenValidator->getValidationStats();
        $this->assertEquals(0, $stats['cache_size']);
    }

    public function testTokenRevocation(): void
    {
        $tokenId = 'token-to-revoke';
        $reason = 'Security breach';

        // Mock audit logging
        $this->auditLogger->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'token_revoked',
                'Token revoked: ' . $reason,
                AuditLogger::SEVERITY_INFO,
                ['token_id' => $tokenId, 'reason' => $reason]
            );

        // Test token revocation
        $result = $this->tokenValidator->revokeToken($tokenId, $reason);
        $this->assertTrue($result);
    }

    protected function tearDown(): void
    {
        // Clean up any test state
        $this->tokenValidator->clearCache();
    }
}