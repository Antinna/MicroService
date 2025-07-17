<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\PasskeyManager;
use Antinna\Auth\Database\Connection;
use PHPUnit\Framework\TestCase;
use PDO;

class PasskeyManagerTest extends TestCase
{
    private PasskeyManager $passkeyManager;
    private PDO $db;
    private int $testUserId;

    protected function setUp(): void
    {
        $this->passkeyManager = new PasskeyManager();
        $this->db = Connection::getInstance()->getConnection();
        
        // Create test user
        $this->testUserId = $this->createTestUser();
        
        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    public function testRegisterDevice(): void
    {
        $credentialId = 'test_credential_' . uniqid();
        $publicKey = 'test_public_key_' . uniqid();
        $deviceName = 'Test Device';
        $metadata = ['browser' => 'Chrome', 'os' => 'Windows'];

        $result = $this->passkeyManager->registerDevice(
            $this->testUserId,
            $credentialId,
            $publicKey,
            $deviceName,
            $metadata
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('device_id', $result);
        $this->assertEquals($deviceName, $result['device_name']);
        $this->assertEquals('Device registered successfully', $result['message']);

        // Verify device was stored in database
        $devices = $this->passkeyManager->getUserDevices($this->testUserId);
        $this->assertTrue($devices['success']);
        $this->assertEquals(1, $devices['total_count']);
        $this->assertEquals($deviceName, $devices['devices'][0]['device_name']);
    }

    public function testRegisterDeviceWithDuplicateName(): void
    {
        $deviceName = 'Duplicate Device';
        
        // Register first device
        $result1 = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_1',
            'public_key_1',
            $deviceName
        );
        $this->assertTrue($result1['success']);

        // Try to register second device with same name
        $result2 = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_2',
            'public_key_2',
            $deviceName
        );
        
        $this->assertFalse($result2['success']);
        $this->assertEquals('DEVICE_NAME_EXISTS', $result2['code']);
    }

    public function testRegisterDeviceWithDuplicateCredentialId(): void
    {
        $credentialId = 'duplicate_credential';
        
        // Register first device
        $result1 = $this->passkeyManager->registerDevice(
            $this->testUserId,
            $credentialId,
            'public_key_1',
            'Device 1'
        );
        $this->assertTrue($result1['success']);

        // Try to register second device with same credential ID
        $result2 = $this->passkeyManager->registerDevice(
            $this->testUserId,
            $credentialId,
            'public_key_2',
            'Device 2'
        );
        
        $this->assertFalse($result2['success']);
        $this->assertEquals('CREDENTIAL_EXISTS', $result2['code']);
    }

    public function testRegisterDeviceWithInvalidName(): void
    {
        $invalidNames = [
            '', // Empty
            str_repeat('a', 51), // Too long
            'Device@#$%', // Invalid characters
        ];

        foreach ($invalidNames as $invalidName) {
            $result = $this->passkeyManager->registerDevice(
                $this->testUserId,
                'credential_' . uniqid(),
                'public_key_' . uniqid(),
                $invalidName
            );
            
            $this->assertFalse($result['success']);
            $this->assertEquals('INVALID_DEVICE_NAME', $result['code']);
        }
    }

    public function testDeviceLimitEnforcement(): void
    {
        // Register maximum allowed devices (10)
        for ($i = 1; $i <= 10; $i++) {
            $result = $this->passkeyManager->registerDevice(
                $this->testUserId,
                'credential_' . $i,
                'public_key_' . $i,
                'Device ' . $i
            );
            $this->assertTrue($result['success']);
        }

        // Try to register one more device (should fail)
        $result = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_11',
            'public_key_11',
            'Device 11'
        );
        
        $this->assertFalse($result['success']);
        $this->assertEquals('DEVICE_LIMIT_EXCEEDED', $result['code']);
    }

    public function testGetUserDevices(): void
    {
        // Register multiple devices
        $devices = [
            ['name' => 'iPhone', 'credential' => 'cred_1', 'key' => 'key_1'],
            ['name' => 'MacBook', 'credential' => 'cred_2', 'key' => 'key_2'],
            ['name' => 'Windows PC', 'credential' => 'cred_3', 'key' => 'key_3'],
        ];

        foreach ($devices as $device) {
            $this->passkeyManager->registerDevice(
                $this->testUserId,
                $device['credential'],
                $device['key'],
                $device['name']
            );
        }

        $result = $this->passkeyManager->getUserDevices($this->testUserId);
        
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['total_count']);
        $this->assertCount(3, $result['devices']);

        // Check device properties
        foreach ($result['devices'] as $device) {
            $this->assertArrayHasKey('device_name', $device);
            $this->assertArrayHasKey('created_at', $device);
            $this->assertArrayHasKey('last_used', $device);
            $this->assertArrayHasKey('sign_count', $device);
            $this->assertArrayHasKey('is_recently_used', $device);
            $this->assertArrayHasKey('usage_frequency', $device);
        }
    }

    public function testUpdateDeviceName(): void
    {
        // Register a device
        $originalName = 'Original Device';
        $newName = 'Updated Device';
        
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'test_credential',
            'test_public_key',
            $originalName
        );
        $this->assertTrue($registerResult['success']);
        $deviceId = $registerResult['device_id'];

        // Update device name
        $updateResult = $this->passkeyManager->updateDeviceName(
            $this->testUserId,
            $deviceId,
            $newName
        );
        
        $this->assertTrue($updateResult['success']);
        $this->assertEquals('Device name updated successfully', $updateResult['message']);

        // Verify name was updated
        $devices = $this->passkeyManager->getUserDevices($this->testUserId);
        $this->assertEquals($newName, $devices['devices'][0]['device_name']);
    }

    public function testUpdateDeviceNameWithInvalidName(): void
    {
        // Register a device
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'test_credential',
            'test_public_key',
            'Original Device'
        );
        $deviceId = $registerResult['device_id'];

        // Try to update with invalid name
        $result = $this->passkeyManager->updateDeviceName(
            $this->testUserId,
            $deviceId,
            'Invalid@Name'
        );
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_DEVICE_NAME', $result['code']);
    }

    public function testUpdateDeviceNameWithDuplicateName(): void
    {
        // Register two devices
        $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_1',
            'key_1',
            'Device 1'
        );
        
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_2',
            'key_2',
            'Device 2'
        );
        $deviceId = $registerResult['device_id'];

        // Try to update second device to have same name as first
        $result = $this->passkeyManager->updateDeviceName(
            $this->testUserId,
            $deviceId,
            'Device 1'
        );
        
        $this->assertFalse($result['success']);
        $this->assertEquals('DEVICE_NAME_EXISTS', $result['code']);
    }

    public function testRemoveDevice(): void
    {
        // Register two devices (so we can remove one)
        $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_1',
            'key_1',
            'Device 1'
        );
        
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'credential_2',
            'key_2',
            'Device 2'
        );
        $deviceId = $registerResult['device_id'];

        // Remove second device
        $result = $this->passkeyManager->removeDevice($this->testUserId, $deviceId);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('Device removed successfully', $result['message']);

        // Verify device was removed
        $devices = $this->passkeyManager->getUserDevices($this->testUserId);
        $this->assertEquals(1, $devices['total_count']);
        $this->assertEquals('Device 1', $devices['devices'][0]['device_name']);
    }

    public function testRemoveLastDevice(): void
    {
        // Register only one device
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'test_credential',
            'test_public_key',
            'Only Device'
        );
        $deviceId = $registerResult['device_id'];

        // Try to remove the last device (should fail due to security policy)
        $result = $this->passkeyManager->removeDevice($this->testUserId, $deviceId);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('LAST_DEVICE_PROTECTION', $result['code']);
    }

    public function testRemoveNonExistentDevice(): void
    {
        $result = $this->passkeyManager->removeDevice($this->testUserId, 99999);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('DEVICE_NOT_FOUND', $result['code']);
    }

    public function testGetDeviceSecurityStatus(): void
    {
        // Register a device
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'test_credential',
            'test_public_key',
            'Test Device',
            ['browser' => 'Chrome', 'os' => 'Windows']
        );
        $deviceId = $registerResult['device_id'];

        $result = $this->passkeyManager->getDeviceSecurityStatus($this->testUserId, $deviceId);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('security_status', $result);
        
        $status = $result['security_status'];
        $this->assertEquals($deviceId, $status['device_id']);
        $this->assertEquals('Test Device', $status['device_name']);
        $this->assertArrayHasKey('security_score', $status);
        $this->assertArrayHasKey('risk_factors', $status);
        $this->assertArrayHasKey('recommendations', $status);
        $this->assertArrayHasKey('usage_frequency', $status);
        $this->assertArrayHasKey('is_recently_used', $status);
    }

    public function testBulkRemoveDevices(): void
    {
        // Register multiple devices
        $deviceIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->passkeyManager->registerDevice(
                $this->testUserId,
                'credential_' . $i,
                'key_' . $i,
                'Device ' . $i
            );
            $deviceIds[] = $result['device_id'];
        }

        // Remove first 3 devices (leave 2 to avoid last device protection)
        $devicesToRemove = array_slice($deviceIds, 0, 3);
        $result = $this->passkeyManager->bulkRemoveDevices($this->testUserId, $devicesToRemove);
        
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['summary']['total']);
        $this->assertEquals(3, $result['summary']['success']);
        $this->assertEquals(0, $result['summary']['errors']);

        // Verify remaining devices
        $devices = $this->passkeyManager->getUserDevices($this->testUserId);
        $this->assertEquals(2, $devices['total_count']);
    }

    public function testUsageFrequencyCalculation(): void
    {
        // This test would require manipulating database records to simulate different usage patterns
        // For now, we'll test the basic functionality
        
        $registerResult = $this->passkeyManager->registerDevice(
            $this->testUserId,
            'test_credential',
            'test_public_key',
            'Test Device'
        );
        
        $devices = $this->passkeyManager->getUserDevices($this->testUserId);
        $device = $devices['devices'][0];
        
        // New device should have low usage frequency
        $this->assertEquals('low', $device['usage_frequency']);
    }

    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (email, password_hash, is_verified) VALUES (?, ?, 1)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['test@example.com', password_hash('password', PASSWORD_DEFAULT)]);
        
        return (int)$this->db->lastInsertId();
    }

    private function cleanupTestData(): void
    {
        // Clean up passkeys
        $sql = "DELETE FROM passkeys WHERE user_id = ?";
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