<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\SMSMFAHandler;
use PHPUnit\Framework\TestCase;

class SMSMFAHandlerTest extends TestCase
{
    private SMSMFAHandler $smsHandler;

    protected function setUp(): void
    {
        $this->smsHandler = new SMSMFAHandler();
    }

    public function testSMSHandlerInstantiation()
    {
        $this->assertInstanceOf(SMSMFAHandler::class, $this->smsHandler);
    }

    public function testValidateSMSCode()
    {
        $reflection = new \ReflectionClass($this->smsHandler);
        $method = $reflection->getMethod('validateSMSCode');
        $method->setAccessible(true);

        // Test valid 6-digit codes
        $this->assertTrue($method->invoke($this->smsHandler, '123456'));
        $this->assertTrue($method->invoke($this->smsHandler, '000000'));
        $this->assertTrue($method->invoke($this->smsHandler, '999999'));

        // Test invalid codes
        $this->assertFalse($method->invoke($this->smsHandler, '12345'));   // Too short
        $this->assertFalse($method->invoke($this->smsHandler, '1234567')); // Too long
        $this->assertFalse($method->invoke($this->smsHandler, '12345a'));  // Contains letter
        $this->assertFalse($method->invoke($this->smsHandler, ''));        // Empty
        $this->assertFalse($method->invoke($this->smsHandler, 'abc123'));  // Contains letters
    }

    public function testMaskPhoneNumber()
    {
        $reflection = new \ReflectionClass($this->smsHandler);
        $method = $reflection->getMethod('maskPhoneNumber');
        $method->setAccessible(true);

        // Test normal phone number
        $this->assertEquals('12****89', $method->invoke($this->smsHandler, '12345689'));
        
        // Test longer phone number
        $this->assertEquals('+1****5678', $method->invoke($this->smsHandler, '+1234565678'));
        
        // Test short phone number
        $this->assertEquals('****', $method->invoke($this->smsHandler, '1234'));
        
        // Test very short phone number
        $this->assertEquals('**', $method->invoke($this->smsHandler, '12'));
    }

    public function testBackupCodeGeneration()
    {
        $userId = 1;
        $backupCodes = $this->smsHandler->generateBackupCodes($userId);

        $this->assertIsArray($backupCodes);
        $this->assertArrayHasKey('codes', $backupCodes);
        $this->assertArrayHasKey('generated_at', $backupCodes);
        $this->assertArrayHasKey('used_codes', $backupCodes);

        // Should generate 10 codes
        $this->assertCount(10, $backupCodes['codes']);

        // Each code should be 8 characters long and uppercase hex
        foreach ($backupCodes['codes'] as $code) {
            $this->assertEquals(8, strlen($code));
            $this->assertTrue(ctype_xdigit($code));
            $this->assertEquals(strtoupper($code), $code);
        }

        // All codes should be unique
        $this->assertEquals(count($backupCodes['codes']), count(array_unique($backupCodes['codes'])));
    }

    public function testSetupMFAWithInvalidMethod()
    {
        $userId = 1;
        $result = $this->smsHandler->setupMFA($userId, 'invalid_method');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_MFA_METHOD', $result['code']);
        $this->assertStringContains('Invalid MFA method', $result['error']);
    }

    public function testVerifyMFAWithInvalidMethod()
    {
        $userId = 1;
        $code = '123456';
        $result = $this->smsHandler->verifyMFA($userId, $code, 'invalid_method');

        $this->assertFalse($result);
    }

    public function testSendSMSCodeStructure()
    {
        $userId = 1;
        $result = $this->smsHandler->sendSMSCode($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, this should fail with user not found
        if (!$result['success']) {
            $this->assertArrayHasKey('error', $result);
            $this->assertArrayHasKey('code', $result);
        }
    }

    public function testCompleteSMSMFASetupStructure()
    {
        $userId = 1;
        $verificationCode = '123456';
        $result = $this->smsHandler->completeSMSMFASetup($userId, $verificationCode);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, this should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    public function testVerificationCodeStorage()
    {
        $reflection = new \ReflectionClass($this->smsHandler);
        
        $storeMethod = $reflection->getMethod('storeVerificationCode');
        $storeMethod->setAccessible(true);
        
        $getMethod = $reflection->getMethod('getStoredVerificationCode');
        $getMethod->setAccessible(true);
        
        $clearMethod = $reflection->getMethod('clearStoredVerificationCode');
        $clearMethod->setAccessible(true);

        $userId = 999; // Use a test user ID
        $code = '123456';

        // Store code
        $storeMethod->invoke($this->smsHandler, $userId, $code);

        // Retrieve code
        $storedData = $getMethod->invoke($this->smsHandler, $userId);
        $this->assertIsArray($storedData);
        $this->assertEquals($code, $storedData['code']);
        $this->assertArrayHasKey('created_at', $storedData);

        // Clear code
        $clearMethod->invoke($this->smsHandler, $userId);

        // Verify code is cleared
        $clearedData = $getMethod->invoke($this->smsHandler, $userId);
        $this->assertNull($clearedData);
    }

    public function testIsMFARequiredWithoutDatabase()
    {
        $userId = 1;
        $result = $this->smsHandler->isMFARequired($userId);

        // Without database, should return false
        $this->assertFalse($result);
    }

    public function testDisableMFAWithoutDatabase()
    {
        $userId = 1;
        $result = $this->smsHandler->disableMFA($userId);

        // Without database, should return false
        $this->assertFalse($result);
    }

    public function testSendSMSMethod()
    {
        $reflection = new \ReflectionClass($this->smsHandler);
        $method = $reflection->getMethod('sendSMS');
        $method->setAccessible(true);

        // Test SMS sending (will use development mode without API key)
        $result = $method->invoke($this->smsHandler, '+1234567890', '123456');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // In development mode (no API key), should succeed
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    protected function tearDown(): void
    {
        // Clean up any temporary files created during testing
        $tempDir = sys_get_temp_dir();
        $pattern = "{$tempDir}/sms_code_*.json";
        
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}