<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\MFAManager;
use PHPUnit\Framework\TestCase;

class MFAManagerTest extends TestCase
{
    private MFAManager $mfaManager;

    protected function setUp(): void
    {
        $this->mfaManager = new MFAManager();
    }

    public function testMFAManagerInstantiation()
    {
        $this->assertInstanceOf(MFAManager::class, $this->mfaManager);
    }

    public function testGetMFAPolicies()
    {
        $policies = $this->mfaManager->getMFAPolicies();

        $this->assertIsArray($policies);
        $this->assertArrayHasKey('admin', $policies);
        $this->assertArrayHasKey('vendor', $policies);
        $this->assertArrayHasKey('customer', $policies);
        $this->assertArrayHasKey('guest', $policies);
        $this->assertArrayHasKey('delivery_partner', $policies);
        $this->assertArrayHasKey('management_staff', $policies);

        // Check admin policy structure
        $adminPolicy = $policies['admin'];
        $this->assertArrayHasKey('required', $adminPolicy);
        $this->assertArrayHasKey('methods', $adminPolicy);
        $this->assertTrue($adminPolicy['required']); // Admin should require MFA
        $this->assertIsArray($adminPolicy['methods']);
        $this->assertContains('totp', $adminPolicy['methods']);
        $this->assertContains('sms', $adminPolicy['methods']);

        // Check guest policy
        $guestPolicy = $policies['guest'];
        $this->assertFalse($guestPolicy['required']); // Guest should not require MFA
        $this->assertEmpty($guestPolicy['methods']); // Guest should have no MFA methods
    }

    public function testUpdateMFAPolicy()
    {
        // Test valid policy update
        $newPolicy = [
            'required' => true,
            'methods' => ['totp']
        ];

        $result = $this->mfaManager->updateMFAPolicy('customer', $newPolicy);
        $this->assertTrue($result['success']);
        $this->assertStringContains('customer', $result['message']);

        // Verify policy was updated
        $policies = $this->mfaManager->getMFAPolicies();
        $this->assertEquals($newPolicy, $policies['customer']);
    }

    public function testUpdateMFAPolicyWithInvalidRole()
    {
        $newPolicy = [
            'required' => true,
            'methods' => ['totp']
        ];

        $result = $this->mfaManager->updateMFAPolicy('invalid_role', $newPolicy);
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_ROLE', $result['code']);
    }

    public function testUpdateMFAPolicyWithInvalidStructure()
    {
        // Missing 'required' field
        $invalidPolicy = [
            'methods' => ['totp']
        ];

        $result = $this->mfaManager->updateMFAPolicy('customer', $invalidPolicy);
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_POLICY_STRUCTURE', $result['code']);

        // Invalid data types
        $invalidPolicy2 = [
            'required' => 'yes', // Should be boolean
            'methods' => ['totp']
        ];

        $result2 = $this->mfaManager->updateMFAPolicy('customer', $invalidPolicy2);
        $this->assertFalse($result2['success']);
        $this->assertEquals('INVALID_POLICY_DATA', $result2['code']);
    }

    public function testUpdateMFAPolicyWithInvalidMethod()
    {
        $invalidPolicy = [
            'required' => true,
            'methods' => ['invalid_method']
        ];

        $result = $this->mfaManager->updateMFAPolicy('customer', $invalidPolicy);
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_MFA_METHOD', $result['code']);
        $this->assertStringContains('invalid_method', $result['error']);
    }

    public function testIsMFARequiredWithoutDatabase()
    {
        $userId = 1;
        $result = $this->mfaManager->isMFARequired($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('required', $result);
        
        // Without database, should return error
        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['required']);
    }

    public function testSetupMFAWithoutDatabase()
    {
        $userId = 1;
        $result = $this->mfaManager->setupMFA($userId, 'totp');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    public function testVerifyMFAWithoutDatabase()
    {
        $userId = 1;
        $code = '123456';
        $result = $this->mfaManager->verifyMFA($userId, $code, 'totp');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    public function testGetMFAStatusWithoutDatabase()
    {
        $userId = 1;
        $result = $this->mfaManager->getMFAStatus($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testDisableMFAWithoutDatabase()
    {
        $userId = 1;
        $result = $this->mfaManager->disableMFA($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    public function testNeedsMFASetupWithoutDatabase()
    {
        $userId = 1;
        $result = $this->mfaManager->needsMFASetup($userId);

        // Without database, should return false
        $this->assertFalse($result);
    }

    public function testVerifyBackupCodeWithoutDatabase()
    {
        $userId = 1;
        $code = 'ABCD1234';
        $result = $this->mfaManager->verifyBackupCode($userId, $code);

        // Without database, should return false
        $this->assertFalse($result);
    }

    public function testRegenerateBackupCodesWithoutDatabase()
    {
        $userId = 1;
        $result = $this->mfaManager->regenerateBackupCodes($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetMFAStatistics()
    {
        $stats = $this->mfaManager->getMFAStatistics();

        $this->assertIsArray($stats);
        
        // Without database, should return error or empty stats
        if (isset($stats['error'])) {
            $this->assertArrayHasKey('error', $stats);
            $this->assertArrayHasKey('message', $stats);
        } else {
            // If no error, should have expected structure
            $this->assertArrayHasKey('total_users', $stats);
            $this->assertArrayHasKey('mfa_enabled_users', $stats);
            $this->assertArrayHasKey('by_role', $stats);
            $this->assertArrayHasKey('by_method', $stats);
        }
    }

    public function testGetUserMFAMethodPrivateMethod()
    {
        $reflection = new \ReflectionClass($this->mfaManager);
        $method = $reflection->getMethod('getUserMFAMethod');
        $method->setAccessible(true);

        // Without database, should return null
        $result = $method->invoke($this->mfaManager, 1);
        $this->assertNull($result);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}