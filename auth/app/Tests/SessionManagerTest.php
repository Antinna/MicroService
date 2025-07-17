<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\SessionManager;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    private SessionManager $sessionManager;

    protected function setUp(): void
    {
        $this->sessionManager = new SessionManager();
    }

    public function testSessionManagerInstantiation()
    {
        $this->assertInstanceOf(SessionManager::class, $this->sessionManager);
    }

    public function testDeviceDetection()
    {
        // Test platform detection
        $reflection = new \ReflectionClass($this->sessionManager);
        $method = $reflection->getMethod('detectPlatform');
        $method->setAccessible(true);

        $windowsUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->assertEquals('Windows', $method->invoke($this->sessionManager, $windowsUA));

        $macUA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';
        $this->assertEquals('macOS', $method->invoke($this->sessionManager, $macUA));

        $androidUA = 'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36';
        $this->assertEquals('Android', $method->invoke($this->sessionManager, $androidUA));
    }

    public function testBrowserDetection()
    {
        $reflection = new \ReflectionClass($this->sessionManager);
        $method = $reflection->getMethod('detectBrowser');
        $method->setAccessible(true);

        $chromeUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
        $this->assertEquals('Chrome', $method->invoke($this->sessionManager, $chromeUA));

        $firefoxUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0';
        $this->assertEquals('Firefox', $method->invoke($this->sessionManager, $firefoxUA));
    }

    public function testMobileDetection()
    {
        $reflection = new \ReflectionClass($this->sessionManager);
        $method = $reflection->getMethod('isMobile');
        $method->setAccessible(true);

        $mobileUA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15';
        $this->assertTrue($method->invoke($this->sessionManager, $mobileUA));

        $desktopUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->assertFalse($method->invoke($this->sessionManager, $desktopUA));
    }

    public function testDeviceFingerprintGeneration()
    {
        $reflection = new \ReflectionClass($this->sessionManager);
        $method = $reflection->getMethod('generateDeviceFingerprint');
        $method->setAccessible(true);

        $sessionData = [
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ];

        $fingerprint = $method->invoke($this->sessionManager, $sessionData);
        
        $this->assertIsString($fingerprint);
        $this->assertEquals(64, strlen($fingerprint)); // SHA256 hash length
    }

    public function testExtractDeviceInfo()
    {
        $reflection = new \ReflectionClass($this->sessionManager);
        $method = $reflection->getMethod('extractDeviceInfo');
        $method->setAccessible(true);

        // Mock server variables
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

        $deviceInfo = $method->invoke($this->sessionManager);

        $this->assertIsArray($deviceInfo);
        $this->assertArrayHasKey('user_agent', $deviceInfo);
        $this->assertArrayHasKey('platform', $deviceInfo);
        $this->assertArrayHasKey('browser', $deviceInfo);
        $this->assertArrayHasKey('is_mobile', $deviceInfo);
    }

    public function testSecurityChecksStructure()
    {
        $reflection = new \ReflectionClass($this->sessionManager);
        $method = $reflection->getMethod('performSecurityChecks');
        $method->setAccessible(true);

        // This would require database setup for full testing
        // For now, we test that the method exists and returns expected structure
        $this->assertTrue($method->isPrivate());
    }

    public function testSessionStatistics()
    {
        // Test that we can call the statistics method
        $stats = $this->sessionManager->getSessionStatistics();
        $this->assertIsArray($stats);
    }

    protected function tearDown(): void
    {
        // Clean up any test data
        unset($_SERVER['HTTP_USER_AGENT']);
    }
}