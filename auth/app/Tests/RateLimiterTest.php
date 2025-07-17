<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Database\Connection;
use PHPUnit\Framework\TestCase;
use PDO;

class RateLimiterTest extends TestCase
{
    private RateLimiter $rateLimiter;
    private PDO $db;
    private string $testIdentifier;

    protected function setUp(): void
    {
        $this->rateLimiter = new RateLimiter();
        $this->db = Connection::getInstance()->getConnection();
        $this->testIdentifier = 'test_' . uniqid();
        
        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    public function testBasicRateLimiting(): void
    {
        $identifier = $this->testIdentifier;
        $action = 'login_attempts';
        
        // First few requests should be allowed
        for ($i = 0; $i < 3; $i++) {
            $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
            $this->assertTrue($result['allowed']);
            $this->assertGreaterThan(0, $result['remaining']);
        }
        
        // Exceed the limit
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        }
        
        // Next request should be blocked
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    public function testCustomLimits(): void
    {
        $identifier = $this->testIdentifier;
        $action = 'custom_action';
        $customLimit = 2;
        $customWindow = 60;
        
        // First request should be allowed
        $result = $this->rateLimiter->checkLimit(
            $identifier, 
            RateLimiter::LIMIT_TYPE_IP, 
            $action, 
            $customLimit, 
            $customWindow
        );
        $this->assertTrue($result['allowed']);
        $this->assertEquals($customLimit, $result['limit']);
        $this->assertEquals($customLimit - 1, $result['remaining']);
        
        // Second request should be allowed
        $result = $this->rateLimiter->checkLimit(
            $identifier, 
            RateLimiter::LIMIT_TYPE_IP, 
            $action, 
            $customLimit, 
            $customWindow
        );
        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        
        // Third request should be blocked
        $result = $this->rateLimiter->checkLimit(
            $identifier, 
            RateLimiter::LIMIT_TYPE_IP, 
            $action, 
            $customLimit, 
            $customWindow
        );
        $this->assertFalse($result['allowed']);
    }

    public function testWhitelist(): void
    {
        $identifier = $this->testIdentifier;
        $action = 'login_attempts';
        
        // Add to whitelist
        $this->assertTrue($this->rateLimiter->addToWhitelist($identifier, RateLimiter::LIMIT_TYPE_IP, 'Test whitelist'));
        
        // Make many requests (should all be allowed due to whitelist)
        for ($i = 0; $i < 20; $i++) {
            $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
            $this->assertTrue($result['allowed']);
            $this->assertTrue($result['whitelisted']);
        }
        
        // Remove from whitelist
        $this->assertTrue($this->rateLimiter->removeFromWhitelist($identifier, RateLimiter::LIMIT_TYPE_IP));
        
        // Now requests should be rate limited
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        }
        
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        $this->assertFalse($result['allowed']);
    }

    public function testBanning(): void
    {
        $identifier = $this->testIdentifier;
        $action = 'login_attempts';
        
        // Ban the identifier
        $this->assertTrue($this->rateLimiter->banIdentifier($identifier, RateLimiter::LIMIT_TYPE_IP, 3600, 'Test ban'));
        
        // All requests should be blocked
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['banned']);
        $this->assertEquals('Identifier is banned', $result['reason']);
        
        // Unban the identifier
        $this->assertTrue($this->rateLimiter->unbanIdentifier($identifier, RateLimiter::LIMIT_TYPE_IP));
        
        // Requests should now be allowed (within limits)
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        $this->assertTrue($result['allowed']);
        $this->assertArrayNotHasKey('banned', $result);
    }

    public function testMultipleLimits(): void
    {
        $identifier = $this->testIdentifier;
        
        $checks = [
            ['type' => RateLimiter::LIMIT_TYPE_IP, 'action' => 'login_attempts'],
            ['type' => RateLimiter::LIMIT_TYPE_IP, 'action' => 'api_requests'],
            ['type' => RateLimiter::LIMIT_TYPE_IP, 'action' => 'magic_link_requests']
        ];
        
        // First check should allow all
        $result = $this->rateLimiter->checkMultipleLimits($identifier, $checks);
        $this->assertTrue($result['allowed']);
        $this->assertCount(3, $result['results']);
        
        // Exhaust one limit
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'login_attempts');
        }
        
        // Now multiple check should fail
        $result = $this->rateLimiter->checkMultipleLimits($identifier, $checks);
        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['results']['login_attempts']['allowed']);
        $this->assertTrue($result['results']['api_requests']['allowed']);
    }

    public function testDifferentLimitTypes(): void
    {
        $identifier = $this->testIdentifier;
        $action = 'api_requests';
        
        // Test IP-based limiting
        $result1 = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        $this->assertTrue($result1['allowed']);
        
        // Test user-based limiting (same identifier, different type)
        $result2 = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_USER, $action);
        $this->assertTrue($result2['allowed']);
        
        // These should be tracked separately
        $this->assertEquals($result1['remaining'], $result2['remaining']);
    }

    public function testRateLimitConstants(): void
    {
        $this->assertEquals('ip', RateLimiter::LIMIT_TYPE_IP);
        $this->assertEquals('user', RateLimiter::LIMIT_TYPE_USER);
        $this->assertEquals('endpoint', RateLimiter::LIMIT_TYPE_ENDPOINT);
        $this->assertEquals('global', RateLimiter::LIMIT_TYPE_GLOBAL);
        
        $this->assertEquals(60, RateLimiter::WINDOW_MINUTE);
        $this->assertEquals(3600, RateLimiter::WINDOW_HOUR);
        $this->assertEquals(86400, RateLimiter::WINDOW_DAY);
    }

    public function testStatistics(): void
    {
        $identifier = $this->testIdentifier;
        
        // Generate some test data
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'login_attempts');
        }
        
        // Exceed limit to generate violations
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'login_attempts');
        }
        
        $result = $this->rateLimiter->getStatistics('1 hour');
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('statistics', $result);
        
        $stats = $result['statistics'];
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('blocked_requests', $stats);
        $this->assertArrayHasKey('top_violators', $stats);
        $this->assertArrayHasKey('most_limited_actions', $stats);
        $this->assertArrayHasKey('whitelist_count', $stats);
        $this->assertArrayHasKey('ban_count', $stats);
        
        $this->assertGreaterThan(0, $stats['total_requests']);
    }

    public function testCleanup(): void
    {
        $identifier = $this->testIdentifier;
        
        // Create some old test data by directly inserting into database
        $sql = "
            INSERT INTO rate_limit_requests (identifier, limit_type, action, created_at)
            VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 10 DAY))
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$identifier, RateLimiter::LIMIT_TYPE_IP, 'old_action']);
        
        // Create recent data
        $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'recent_action');
        
        // Run cleanup
        $result = $this->rateLimiter->cleanup(7);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('results', $result);
        $this->assertGreaterThan(0, $result['results']['requests_cleaned']);
        
        // Verify old data was cleaned up
        $sql = "SELECT COUNT(*) FROM rate_limit_requests WHERE identifier = ? AND action = 'old_action'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$identifier]);
        $this->assertEquals(0, $stmt->fetchColumn());
        
        // Verify recent data still exists
        $sql = "SELECT COUNT(*) FROM rate_limit_requests WHERE identifier = ? AND action = 'recent_action'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$identifier]);
        $this->assertGreaterThan(0, $stmt->fetchColumn());
    }

    public function testResetTime(): void
    {
        $identifier = $this->testIdentifier;
        $action = 'login_attempts';
        
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
        
        $this->assertTrue($result['allowed']);
        $this->assertArrayHasKey('reset_time', $result);
        $this->assertGreaterThan(time(), $result['reset_time']);
        $this->assertLessThanOrEqual(time() + 3600, $result['reset_time']); // Should be within an hour
    }

    public function testFailOpen(): void
    {
        // Test that rate limiter fails open when there are errors
        // This is tested by the error handling in the checkLimit method
        
        $identifier = 'test_fail_open';
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'unknown_action');
        
        // Should allow request even if there might be errors
        $this->assertTrue($result['allowed']);
    }

    public function testTemporaryBan(): void
    {
        $identifier = $this->testIdentifier;
        
        // Ban for 1 second
        $this->assertTrue($this->rateLimiter->banIdentifier($identifier, RateLimiter::LIMIT_TYPE_IP, 1, 'Temporary test ban'));
        
        // Should be banned initially
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'login_attempts');
        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['banned']);
        
        // Wait for ban to expire (in a real test, you might mock time)
        sleep(2);
        
        // Clean up expired bans
        $this->rateLimiter->cleanup();
        
        // Should now be allowed
        $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, 'login_attempts');
        $this->assertTrue($result['allowed']);
    }

    public function testDifferentActions(): void
    {
        $identifier = $this->testIdentifier;
        
        $actions = ['login_attempts', 'magic_link_requests', 'api_requests', 'registration'];
        
        foreach ($actions as $action) {
            $result = $this->rateLimiter->checkLimit($identifier, RateLimiter::LIMIT_TYPE_IP, $action);
            $this->assertTrue($result['allowed']);
            $this->assertArrayHasKey('limit', $result);
            $this->assertArrayHasKey('remaining', $result);
        }
    }

    private function cleanupTestData(): void
    {
        // Clean up rate limit requests
        $sql = "DELETE FROM rate_limit_requests WHERE identifier LIKE 'test_%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        // Clean up whitelist
        $sql = "DELETE FROM rate_limit_whitelist WHERE identifier LIKE 'test_%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        // Clean up bans
        $sql = "DELETE FROM rate_limit_bans WHERE identifier LIKE 'test_%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        // Clean up audit logs
        $sql = "DELETE FROM audit_logs WHERE metadata LIKE '%test_%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
    }
}