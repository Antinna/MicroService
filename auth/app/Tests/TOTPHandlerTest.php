<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\TOTPHandler;
use PHPUnit\Framework\TestCase;

class TOTPHandlerTest extends TestCase
{
    private TOTPHandler $totpHandler;

    protected function setUp(): void
    {
        $this->totpHandler = new TOTPHandler();
    }

    public function testTOTPHandlerInstantiation()
    {
        $this->assertInstanceOf(TOTPHandler::class, $this->totpHandler);
    }

    public function testValidateTOTPCode()
    {
        // Test valid 6-digit codes
        $this->assertTrue($this->totpHandler->validateTOTPCode('123456'));
        $this->assertTrue($this->totpHandler->validateTOTPCode('000000'));
        $this->assertTrue($this->totpHandler->validateTOTPCode('999999'));

        // Test invalid codes
        $this->assertFalse($this->totpHandler->validateTOTPCode('12345'));   // Too short
        $this->assertFalse($this->totpHandler->validateTOTPCode('1234567')); // Too long
        $this->assertFalse($this->totpHandler->validateTOTPCode('12345a'));  // Contains letter
        $this->assertFalse($this->totpHandler->validateTOTPCode(''));        // Empty
        $this->assertFalse($this->totpHandler->validateTOTPCode('abc123'));  // Contains letters
    }

    public function testBackupCodeGeneration()
    {
        $userId = 1;
        $backupCodes = $this->totpHandler->generateBackupCodes($userId);

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

    public function testGetCurrentTOTP()
    {
        // Test with a known secret
        $secret = 'JBSWY3DPEHPK3PXP'; // Base32 encoded secret
        $totp = $this->totpHandler->getCurrentTOTP($secret);

        // Should return a 6-digit string
        $this->assertIsString($totp);
        $this->assertEquals(6, strlen($totp));
        $this->assertTrue(ctype_digit($totp));
    }

    public function testSetupMFAWithInvalidMethod()
    {
        $userId = 1;
        $result = $this->totpHandler->setupMFA($userId, 'invalid_method');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_MFA_METHOD', $result['code']);
        $this->assertStringContains('Invalid MFA method', $result['error']);
    }

    public function testVerifyMFAWithInvalidMethod()
    {
        $userId = 1;
        $code = '123456';
        $result = $this->totpHandler->verifyMFA($userId, $code, 'invalid_method');

        $this->assertFalse($result);
    }

    public function testMFAStatusStructure()
    {
        // Test getMFAStatus method structure
        $userId = 1;
        $status = $this->totpHandler->getMFAStatus($userId);

        $this->assertIsArray($status);
        // Without database, this will return user not found
        $this->assertArrayHasKey('success', $status);
    }

    public function testTOTPSecretGeneration()
    {
        // Test that setup generates proper structure
        $userId = 1;
        $result = $this->totpHandler->setupMFA($userId, 'totp');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, this should fail with user not found
        if (!$result['success']) {
            $this->assertArrayHasKey('error', $result);
            $this->assertArrayHasKey('code', $result);
        }
    }

    public function testCompleteMFASetupStructure()
    {
        $userId = 1;
        $verificationCode = '123456';
        $result = $this->totpHandler->completeMFASetup($userId, $verificationCode);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, this should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    public function testRegenerateBackupCodesStructure()
    {
        $userId = 1;
        $result = $this->totpHandler->regenerateBackupCodes($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, this should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testIsMFARequiredWithoutDatabase()
    {
        $userId = 1;
        $result = $this->totpHandler->isMFARequired($userId);

        // Without database, should return false
        $this->assertFalse($result);
    }

    public function testDisableMFAWithoutDatabase()
    {
        $userId = 1;
        $result = $this->totpHandler->disableMFA($userId);

        // Without database, should return false
        $this->assertFalse($result);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}