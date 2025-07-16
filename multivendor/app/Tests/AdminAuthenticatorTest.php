<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\AdminAuthenticator;
use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Exceptions\UnauthorizedException;

class AdminAuthenticatorTest extends TestCase
{
    private AdminAuthenticator $authenticator;
    private Logger $logger;

    protected function setUp(): void
    {
        // Clear any existing session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        // Clear environment variables
        putenv('ADMIN_USERNAME=');
        putenv('ADMIN_PASSWORD=');
        putenv('ADMIN_SESSION_TIMEOUT=');
        
        $this->logger = $this->createMock(Logger::class);
        $this->authenticator = new AdminAuthenticator($this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        // Clear environment variables
        putenv('ADMIN_USERNAME=');
        putenv('ADMIN_PASSWORD=');
        putenv('ADMIN_SESSION_TIMEOUT=');
    }

    public function testDefaultCredentials(): void
    {
        $this->logger->expects($this->exactly(2))
            ->method('info');

        $result = $this->authenticator->authenticate('admin', 'admin');

        $this->assertTrue($result['success']);
        $this->assertEquals('Authentication successful', $result['message']);
        $this->assertArrayHasKey('session', $result);
        $this->assertArrayHasKey('expires_at', $result);
    }

    public function testEnvironmentCredentials(): void
    {
        putenv('ADMIN_USERNAME=testadmin');
        putenv('ADMIN_PASSWORD=testpass123');
        
        $authenticator = new AdminAuthenticator($this->logger);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $result = $authenticator->authenticate('testadmin', 'testpass123');

        $this->assertTrue($result['success']);
        $this->assertEquals('testadmin', $result['session']['username']);
    }

    public function testInvalidCredentials(): void
    {
        $this->logger->expects($this->once())
            ->method('info');
        
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Admin authentication failed - invalid credentials');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid admin credentials');

        $this->authenticator->authenticate('wrong', 'credentials');
    }

    public function testSessionCreation(): void
    {
        $this->logger->expects($this->exactly(2))
            ->method('info');

        $result = $this->authenticator->authenticate('admin', 'admin');

        $this->assertTrue($this->authenticator->isAuthenticated());
        
        $sessionInfo = $this->authenticator->getSessionInfo();
        $this->assertNotNull($sessionInfo);
        $this->assertEquals('admin', $sessionInfo['username']);
        $this->assertIsInt($sessionInfo['login_time']);
        $this->assertIsInt($sessionInfo['expires_at']);
        $this->assertGreaterThan(0, $sessionInfo['time_remaining']);
    }

    public function testSessionExpiry(): void
    {
        // Set very short session timeout
        putenv('ADMIN_SESSION_TIMEOUT=1');
        $authenticator = new AdminAuthenticator($this->logger);

        $this->logger->expects($this->exactly(3))
            ->method('info');

        $authenticator->authenticate('admin', 'admin');
        $this->assertTrue($authenticator->isAuthenticated());

        // Wait for session to expire
        sleep(2);

        $this->assertFalse($authenticator->isAuthenticated());
    }

    public function testSessionExtension(): void
    {
        $this->logger->expects($this->exactly(3))
            ->method('info');

        $this->authenticator->authenticate('admin', 'admin');
        
        $originalInfo = $this->authenticator->getSessionInfo();
        $originalExpiresAt = $originalInfo['expires_at'];

        $result = $this->authenticator->extendSession();

        $this->assertTrue($result['success']);
        $this->assertEquals('Session extended', $result['message']);
        
        $newInfo = $this->authenticator->getSessionInfo();
        $this->assertGreaterThan($originalExpiresAt, $newInfo['expires_at']);
    }

    public function testExtendSessionWithoutAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('No active admin session');

        $this->authenticator->extendSession();
    }

    public function testLogout(): void
    {
        $this->logger->expects($this->exactly(3))
            ->method('info');

        $this->authenticator->authenticate('admin', 'admin');
        $this->assertTrue($this->authenticator->isAuthenticated());

        $result = $this->authenticator->logout();

        $this->assertTrue($result['success']);
        $this->assertEquals('Logout successful', $result['message']);
        $this->assertFalse($this->authenticator->isAuthenticated());
        $this->assertNull($this->authenticator->getSessionInfo());
    }

    public function testRequireAuthentication(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Unauthorized admin access attempt');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Admin authentication required');

        $this->authenticator->requireAuthentication();
    }

    public function testRequireAuthenticationWithValidSession(): void
    {
        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->authenticator->authenticate('admin', 'admin');
        
        // Should not throw exception
        $this->authenticator->requireAuthentication();
        $this->assertTrue(true); // Assert we got here without exception
    }

    public function testGetCredentialsInfo(): void
    {
        $info = $this->authenticator->getCredentialsInfo();

        $this->assertEquals('default', $info['username_source']);
        $this->assertEquals('default', $info['password_source']);
        $this->assertEquals(3600, $info['session_timeout']);
        $this->assertEquals('admin', $info['default_credentials']['username']);
        $this->assertEquals('admin', $info['default_credentials']['password']);
    }

    public function testGetCredentialsInfoWithEnvironment(): void
    {
        putenv('ADMIN_USERNAME=envuser');
        putenv('ADMIN_PASSWORD=envpass');
        putenv('ADMIN_SESSION_TIMEOUT=7200');
        
        $authenticator = new AdminAuthenticator($this->logger);
        $info = $authenticator->getCredentialsInfo();

        $this->assertEquals('environment', $info['username_source']);
        $this->assertEquals('environment', $info['password_source']);
        $this->assertEquals(7200, $info['session_timeout']);
    }

    public function testSessionTokenValidation(): void
    {
        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->authenticator->authenticate('admin', 'admin');
        $sessionId = session_id();

        $this->assertTrue($this->authenticator->validateSessionToken($sessionId));
        $this->assertFalse($this->authenticator->validateSessionToken('invalid-token'));
    }

    public function testTimingSafeCredentialComparison(): void
    {
        // This test ensures timing attacks are prevented
        $this->logger->expects($this->exactly(2))
            ->method('info');
        
        $this->logger->expects($this->exactly(2))
            ->method('warning');

        // Both should take similar time regardless of how wrong they are
        $start1 = microtime(true);
        try {
            $this->authenticator->authenticate('a', 'b');
        } catch (UnauthorizedException $e) {
            // Expected
        }
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        try {
            $this->authenticator->authenticate('completely_wrong_username', 'completely_wrong_password');
        } catch (UnauthorizedException $e) {
            // Expected
        }
        $time2 = microtime(true) - $start2;

        // Times should be relatively similar (within reasonable bounds)
        $timeDiff = abs($time1 - $time2);
        $this->assertLessThan(0.01, $timeDiff, 'Timing difference too large, potential timing attack vulnerability');
    }

    public function testSessionSecurityHeaders(): void
    {
        // Mock server variables for HTTPS detection
        $_SERVER['HTTPS'] = 'on';
        
        $authenticator = new AdminAuthenticator($this->logger);
        
        // Session should be configured with security settings
        $this->assertEquals('1', ini_get('session.cookie_httponly'));
        $this->assertEquals('1', ini_get('session.cookie_secure'));
        $this->assertEquals('Strict', ini_get('session.cookie_samesite'));
        $this->assertEquals('1', ini_get('session.use_strict_mode'));

        // Clean up
        unset($_SERVER['HTTPS']);
    }

    public function testIpConsistencyCheck(): void
    {
        // Mock initial IP
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        
        $this->logger->expects($this->exactly(3))
            ->method('info');
        
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Admin session IP mismatch detected');

        $this->authenticator->authenticate('admin', 'admin');
        $this->assertTrue($this->authenticator->isAuthenticated());

        // Change IP
        $_SERVER['REMOTE_ADDR'] = '192.168.1.200';

        // Should detect IP change and invalidate session
        $this->assertFalse($this->authenticator->isAuthenticated());

        // Clean up
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testMultipleIpHeaders(): void
    {
        // Test X-Forwarded-For header
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1, 192.168.1.100';
        
        $authenticator = new AdminAuthenticator($this->logger);
        
        $this->logger->expects($this->exactly(2))
            ->method('info');

        $result = $authenticator->authenticate('admin', 'admin');
        $this->assertTrue($result['success']);

        // Clean up
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }
}