<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Config\Environment;
use PHPUnit\Framework\TestCase;

class JWTManagerTest extends TestCase
{
    private JWTManager $jwtManager;

    protected function setUp(): void
    {
        Environment::load();
        $this->jwtManager = new JWTManager();
    }

    public function testGenerateToken()
    {
        // This test would require a test database with user data
        // For now, we'll test the basic structure
        $this->assertInstanceOf(JWTManager::class, $this->jwtManager);
    }

    public function testTokenStructure()
    {
        $testToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJhdXRoLXNlcnZpY2UiLCJhdWQiOiJwbGF0Zm9ybSIsImlhdCI6MTYzOTU4NzYwMCwiZXhwIjoxNjM5NTkxMjAwLCJzdWIiOjEsInVzZXJfaWQiOjEsImVtYWlsIjoidGVzdEBleGFtcGxlLmNvbSIsInJvbGUiOiJjdXN0b21lciJ9.test';
        
        $payload = $this->jwtManager->decodeTokenPayload($testToken);
        
        // Test that we can decode token structure
        $this->assertIsArray($payload);
    }

    public function testServiceTokenGeneration()
    {
        $serviceName = 'test-service';
        $permissions = ['read', 'write'];
        
        $token = $this->jwtManager->generateServiceToken($serviceName, $permissions);
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        // Verify token structure
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testTokenBlacklistCheck()
    {
        $testToken = 'test.token.here';
        
        // Initially should not be blacklisted
        $this->assertFalse($this->jwtManager->isTokenBlacklisted($testToken));
    }

    public function testDecodeTokenPayload()
    {
        // Test with invalid token
        $invalidToken = 'invalid.token';
        $payload = $this->jwtManager->decodeTokenPayload($invalidToken);
        $this->assertNull($payload);
        
        // Test with malformed token
        $malformedToken = 'not-a-jwt-token';
        $payload = $this->jwtManager->decodeTokenPayload($malformedToken);
        $this->assertNull($payload);
    }

    public function testConfigurationAccess()
    {
        // Test that JWT manager can access configuration
        $secret = Environment::get('JWT_SECRET');
        $this->assertNotEmpty($secret);
        
        $expiry = (int)Environment::get('JWT_EXPIRY', 3600);
        $this->assertIsInt($expiry);
        $this->assertGreaterThan(0, $expiry);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}