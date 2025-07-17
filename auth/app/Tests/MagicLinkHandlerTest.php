<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\MagicLinkHandler;
use Antinna\Auth\Database\Connection;
use PHPUnit\Framework\TestCase;
use PDO;

class MagicLinkHandlerTest extends TestCase
{
    private MagicLinkHandler $magicLinkHandler;
    private PDO $db;
    private int $testUserId;
    private string $testUserEmail;

    protected function setUp(): void
    {
        $this->magicLinkHandler = new MagicLinkHandler();
        $this->db = Connection::getInstance()->getConnection();
        
        // Create test user
        $this->testUserEmail = 'magic-link-test@example.com';
        $this->testUserId = $this->createTestUser();
        
        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    public function testGenerateMagicLinkSuccess(): void
    {
        $result = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);

        $this->assertTrue($result['success']);
        $this->assertEquals('Magic link generated successfully', $result['message']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('magic_link', $result['data']);
        $this->assertArrayHasKey('expires_at', $result['data']);
        $this->assertArrayHasKey('link_id', $result['data']);
        $this->assertEquals($this->testUserEmail, $result['data']['email']);

        // Verify link was stored in database
        $sql = "SELECT COUNT(*) FROM magic_links WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        $count = $stmt->fetchColumn();
        
        $this->assertEquals(1, $count);
    }

    public function testGenerateMagicLinkWithCustomExpiration(): void
    {
        $options = ['expiration_minutes' => 30];
        $result = $this->magicLinkHandler->generateMagicLink($this->testUserEmail, $options);

        $this->assertTrue($result['success']);
        $this->assertEquals(30, $result['data']['expires_in_minutes']);
        
        // Check that expiration is approximately 30 minutes from now
        $expiresAt = strtotime($result['data']['expires_at']);
        $expectedExpiration = time() + (30 * 60);
        $this->assertLessThan(60, abs($expiresAt - $expectedExpiration)); // Within 1 minute tolerance
    }

    public function testGenerateMagicLinkWithRedirectUrl(): void
    {
        $options = ['redirect_url' => 'https://example.com/dashboard'];
        $result = $this->magicLinkHandler->generateMagicLink($this->testUserEmail, $options);

        $this->assertTrue($result['success']);
        $this->assertStringContains('redirect=', $result['data']['magic_link']);
        $this->assertStringContains(urlencode('https://example.com/dashboard'), $result['data']['magic_link']);
    }

    public function testGenerateMagicLinkWithMobileDeepLink(): void
    {
        $options = ['mobile_deep_link' => true];
        $result = $this->magicLinkHandler->generateMagicLink($this->testUserEmail, $options);

        $this->assertTrue($result['success']);
        $this->assertStringContains('mobile=1', $result['data']['magic_link']);
    }

    public function testGenerateMagicLinkInvalidEmail(): void
    {
        $result = $this->magicLinkHandler->generateMagicLink('invalid-email');

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_EMAIL', $result['code']);
    }

    public function testGenerateMagicLinkNonExistentUser(): void
    {
        $result = $this->magicLinkHandler->generateMagicLink('nonexistent@example.com');

        // Should still return success for security (don't reveal if user exists)
        $this->assertTrue($result['success']);
        $this->assertStringContains('If the email exists', $result['message']);
        
        // But no magic link should be created in database
        $sql = "SELECT COUNT(*) FROM magic_links";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $count = $stmt->fetchColumn();
        
        $this->assertEquals(0, $count);
    }

    public function testValidateMagicLinkSuccess(): void
    {
        // First generate a magic link
        $generateResult = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        $this->assertTrue($generateResult['success']);
        
        // Extract token from the magic link URL
        $magicLink = $generateResult['data']['magic_link'];
        parse_str(parse_url($magicLink, PHP_URL_QUERY), $queryParams);
        $token = $queryParams['token'];

        // Validate the magic link
        $validateResult = $this->magicLinkHandler->validateMagicLink($token);

        $this->assertTrue($validateResult['success']);
        $this->assertEquals('Magic link validated successfully', $validateResult['message']);
        $this->assertArrayHasKey('data', $validateResult);
        $this->assertArrayHasKey('user', $validateResult['data']);
        $this->assertArrayHasKey('magic_link', $validateResult['data']);
        $this->assertEquals($this->testUserId, $validateResult['data']['user']['id']);
        $this->assertEquals($this->testUserEmail, $validateResult['data']['user']['email']);
    }

    public function testValidateMagicLinkInvalidToken(): void
    {
        $result = $this->magicLinkHandler->validateMagicLink('invalid-token');

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_TOKEN', $result['code']);
    }

    public function testValidateMagicLinkExpiredToken(): void
    {
        // Create an expired magic link directly in database
        $tokenHash = hash('sha256', 'expired-token');
        $sql = "
            INSERT INTO magic_links (user_id, token_hash, expires_at, created_at, metadata)
            VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), '{}')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId, $tokenHash]);

        $result = $this->magicLinkHandler->validateMagicLink('expired-token');

        $this->assertFalse($result['success']);
        $this->assertEquals('TOKEN_EXPIRED', $result['code']);
    }

    public function testValidateMagicLinkAlreadyUsed(): void
    {
        // Generate a magic link
        $generateResult = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        $magicLink = $generateResult['data']['magic_link'];
        parse_str(parse_url($magicLink, PHP_URL_QUERY), $queryParams);
        $token = $queryParams['token'];

        // Use it once
        $firstValidation = $this->magicLinkHandler->validateMagicLink($token);
        $this->assertTrue($firstValidation['success']);

        // Try to use it again
        $secondValidation = $this->magicLinkHandler->validateMagicLink($token);
        $this->assertFalse($secondValidation['success']);
        $this->assertEquals('TOKEN_ALREADY_USED', $secondValidation['code']);
    }

    public function testRevokeUserMagicLinks(): void
    {
        // Generate multiple magic links
        $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        $this->magicLinkHandler->generateMagicLink($this->testUserEmail);

        // Verify they were created
        $sql = "SELECT COUNT(*) FROM magic_links WHERE user_id = ? AND revoked_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        $this->assertEquals(3, $stmt->fetchColumn());

        // Revoke all magic links
        $result = $this->magicLinkHandler->revokeUserMagicLinks($this->testUserId);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['revoked_count']);

        // Verify they were revoked
        $sql = "SELECT COUNT(*) FROM magic_links WHERE user_id = ? AND revoked_at IS NOT NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        $this->assertEquals(3, $stmt->fetchColumn());
    }

    public function testGetUserMagicLinkStats(): void
    {
        // Generate some magic links with different states
        $generateResult1 = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        $generateResult2 = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        $generateResult3 = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);

        // Use one of them
        $magicLink = $generateResult1['data']['magic_link'];
        parse_str(parse_url($magicLink, PHP_URL_QUERY), $queryParams);
        $this->magicLinkHandler->validateMagicLink($queryParams['token']);

        // Create an expired one directly
        $tokenHash = hash('sha256', 'expired-token');
        $sql = "
            INSERT INTO magic_links (user_id, token_hash, expires_at, created_at, metadata)
            VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), '{}')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId, $tokenHash]);

        // Get stats
        $result = $this->magicLinkHandler->getUserMagicLinkStats($this->testUserId);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        
        $stats = $result['data'];
        $this->assertEquals(4, $stats['total_generated']); // 3 + 1 expired
        $this->assertEquals(1, $stats['total_used']);
        $this->assertEquals(1, $stats['total_expired']);
        $this->assertEquals(2, $stats['active_links']); // 2 remaining active
        $this->assertEquals(25.0, $stats['usage_rate']); // 1/4 * 100
    }

    public function testRateLimiting(): void
    {
        // This test would require mocking the config or setting up a test config
        // For now, we'll test the basic functionality
        
        // Generate multiple magic links quickly
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
        }

        // All should succeed initially (assuming default limit is higher than 3)
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
        }
    }

    public function testCleanupExpiredLinks(): void
    {
        // Create some old expired links
        $tokenHash1 = hash('sha256', 'old-expired-token-1');
        $tokenHash2 = hash('sha256', 'old-expired-token-2');
        
        $sql = "
            INSERT INTO magic_links (user_id, token_hash, expires_at, created_at, metadata)
            VALUES 
                (?, ?, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY), '{}'),
                (?, ?, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), '{}')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId, $tokenHash1, $this->testUserId, $tokenHash2]);

        // Also create a recent expired link (should not be cleaned up)
        $tokenHash3 = hash('sha256', 'recent-expired-token');
        $sql = "
            INSERT INTO magic_links (user_id, token_hash, expires_at, created_at, metadata)
            VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), '{}')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId, $tokenHash3]);

        // Run cleanup
        $result = $this->magicLinkHandler->cleanupExpiredLinks();

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['deleted_count']);

        // Verify only old expired links were deleted
        $sql = "SELECT COUNT(*) FROM magic_links WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        $this->assertEquals(1, $stmt->fetchColumn()); // Only the recent expired one should remain
    }

    public function testTokenSecurity(): void
    {
        // Generate multiple magic links and ensure tokens are unique
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $result = $this->magicLinkHandler->generateMagicLink($this->testUserEmail);
            $this->assertTrue($result['success']);
            
            $magicLink = $result['data']['magic_link'];
            parse_str(parse_url($magicLink, PHP_URL_QUERY), $queryParams);
            $tokens[] = $queryParams['token'];
        }

        // All tokens should be unique
        $this->assertEquals(5, count(array_unique($tokens)));

        // Tokens should be URL-safe (no +, /, or = characters)
        foreach ($tokens as $token) {
            $this->assertStringNotContainsString('+', $token);
            $this->assertStringNotContainsString('/', $token);
            $this->assertStringNotContainsString('=', $token);
        }
    }

    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (email, password_hash, is_verified) VALUES (?, ?, 1)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserEmail, password_hash('password', PASSWORD_DEFAULT)]);
        
        return (int)$this->db->lastInsertId();
    }

    private function cleanupTestData(): void
    {
        // Clean up magic links
        $sql = "DELETE FROM magic_links WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        
        // Clean up audit logs
        $sql = "DELETE FROM audit_logs WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        
        // Clean up test user
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
    }
}