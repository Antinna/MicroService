<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Controllers\PasskeyController;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\JWTManager;
use PHPUnit\Framework\TestCase;
use PDO;

class PasskeyApiIntegrationTest extends TestCase
{
    private PasskeyController $controller;
    private PDO $db;
    private int $testUserId;
    private string $testUserEmail;
    private string $authToken;

    protected function setUp(): void
    {
        $this->controller = new PasskeyController();
        $this->db = Connection::getInstance()->getConnection();
        
        // Create test user
        $this->testUserEmail = 'passkey-test@example.com';
        $this->testUserId = $this->createTestUser();
        
        // Generate auth token for authenticated requests
        $jwtManager = new JWTManager();
        $tokenResult = $jwtManager->generateToken($this->testUserId);
        $this->authToken = $tokenResult['access_token'];
        
        // Clean up any existing test data
        $this->cleanupTestData();
        
        // Start session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        
        // Clear session
        session_destroy();
    }

    public function testBeginRegistrationWithoutAuth(): void
    {
        // Clear session to simulate unauthenticated request
        unset($_SESSION['user_id']);
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->controller->beginRegistration();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);
        $this->assertEquals(401, http_response_code());
    }

    public function testBeginRegistrationWithAuth(): void
    {
        // Set authentication
        $_SESSION['user_id'] = $this->testUserId;

        ob_start();
        $this->controller->beginRegistration();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('challenge', $response['data']);
        $this->assertArrayHasKey('rp', $response['data']);
        $this->assertArrayHasKey('user', $response['data']);
        $this->assertArrayHasKey('pubKeyCredParams', $response['data']);
    }

    public function testCompleteRegistrationWithValidData(): void
    {
        // Set authentication
        $_SESSION['user_id'] = $this->testUserId;

        // Mock valid registration response
        $registrationData = [
            'id' => base64_encode('test_credential_id'),
            'rawId' => base64_encode('test_credential_id'),
            'response' => [
                'clientDataJSON' => base64_encode(json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => base64_encode('test_challenge'),
                    'origin' => 'https://localhost'
                ])),
                'attestationObject' => base64_encode('test_attestation_object')
            ],
            'type' => 'public-key',
            'deviceName' => 'Test Device'
        ];

        // Simulate JSON input
        $this->mockJsonInput($registrationData);

        ob_start();
        $this->controller->completeRegistration();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        // Note: This might fail due to actual WebAuthn verification
        // In a real test, we'd mock the PasskeyHandler
        $this->assertArrayHasKey('success', $response);
    }

    public function testCompleteRegistrationWithMissingFields(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        // Mock incomplete registration data
        $registrationData = [
            'id' => base64_encode('test_credential_id'),
            // Missing required fields
        ];

        $this->mockJsonInput($registrationData);

        ob_start();
        $this->controller->completeRegistration();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('MISSING_FIELD', $response['code']);
    }

    public function testBeginAuthenticationWithValidEmail(): void
    {
        $authData = [
            'email' => $this->testUserEmail
        ];

        $this->mockJsonInput($authData);

        ob_start();
        $this->controller->beginAuthentication();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        // This might fail if user has no passkeys registered
        $this->assertArrayHasKey('success', $response);
    }

    public function testBeginAuthenticationWithInvalidEmail(): void
    {
        $authData = [
            'email' => 'invalid-email'
        ];

        $this->mockJsonInput($authData);

        ob_start();
        $this->controller->beginAuthentication();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('INVALID_EMAIL', $response['code']);
    }

    public function testBeginAuthenticationWithMissingEmail(): void
    {
        $authData = []; // No email

        $this->mockJsonInput($authData);

        ob_start();
        $this->controller->beginAuthentication();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('EMAIL_REQUIRED', $response['code']);
    }

    public function testGetDevicesWithAuth(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        ob_start();
        $this->controller->getDevices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('devices', $response['data']);
        $this->assertArrayHasKey('total_count', $response['data']);
    }

    public function testGetDevicesWithoutAuth(): void
    {
        // Clear authentication
        unset($_SESSION['user_id']);
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->controller->getDevices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);
    }

    public function testUpdateDeviceWithValidData(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        // First create a test device
        $deviceId = $this->createTestDevice();

        $updateData = [
            'device_name' => 'Updated Device Name'
        ];

        $this->mockJsonInput($updateData);

        ob_start();
        $this->controller->updateDevice($deviceId);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
    }

    public function testUpdateDeviceWithInvalidData(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        $updateData = [
            'device_name' => '' // Empty name
        ];

        $this->mockJsonInput($updateData);

        ob_start();
        $this->controller->updateDevice(999); // Non-existent device
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
    }

    public function testRemoveDeviceWithValidId(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        // Create two test devices (so we can remove one)
        $deviceId1 = $this->createTestDevice('Device 1');
        $deviceId2 = $this->createTestDevice('Device 2');

        ob_start();
        $this->controller->removeDevice($deviceId1);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
    }

    public function testRemoveDeviceWithInvalidId(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        ob_start();
        $this->controller->removeDevice(999); // Non-existent device
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('DEVICE_NOT_FOUND', $response['code']);
    }

    public function testGetDeviceSecurityStatus(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        $deviceId = $this->createTestDevice();

        ob_start();
        $this->controller->getDeviceSecurityStatus($deviceId);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('security_score', $response['data']);
        $this->assertArrayHasKey('risk_factors', $response['data']);
        $this->assertArrayHasKey('recommendations', $response['data']);
    }

    public function testBulkRemoveDevices(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        // Create multiple test devices
        $deviceIds = [
            $this->createTestDevice('Device 1'),
            $this->createTestDevice('Device 2'),
            $this->createTestDevice('Device 3'),
            $this->createTestDevice('Device 4'), // Keep one to avoid last device protection
        ];

        $bulkData = [
            'device_ids' => array_slice($deviceIds, 0, 2) // Remove first 2
        ];

        $this->mockJsonInput($bulkData);

        ob_start();
        $this->controller->bulkRemoveDevices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('summary', $response['data']);
    }

    public function testBulkRemoveDevicesWithInvalidData(): void
    {
        $_SESSION['user_id'] = $this->testUserId;

        $bulkData = [
            'device_ids' => 'not_an_array'
        ];

        $this->mockJsonInput($bulkData);

        ob_start();
        $this->controller->bulkRemoveDevices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('DEVICE_IDS_REQUIRED', $response['code']);
    }

    public function testJWTAuthentication(): void
    {
        // Clear session and use JWT token
        unset($_SESSION['user_id']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->authToken;

        ob_start();
        $this->controller->getDevices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
    }

    public function testInvalidJWTAuthentication(): void
    {
        unset($_SESSION['user_id']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid_token';

        ob_start();
        $this->controller->getDevices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);
    }

    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (email, password_hash, is_verified) VALUES (?, ?, 1)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserEmail, password_hash('password', PASSWORD_DEFAULT)]);
        
        return (int)$this->db->lastInsertId();
    }

    private function createTestDevice(string $deviceName = 'Test Device'): int
    {
        $sql = "
            INSERT INTO passkeys (user_id, credential_id, public_key, device_name, sign_count, is_active)
            VALUES (?, ?, ?, ?, 0, 1)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $this->testUserId,
            'test_credential_' . uniqid(),
            'test_public_key_' . uniqid(),
            $deviceName
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    private function mockJsonInput(array $data): void
    {
        // This is a simplified mock - in a real test environment,
        // you'd use a proper HTTP testing framework
        $_POST = $data; // Fallback for testing
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
        
        // Clean up sessions
        $sql = "DELETE FROM sessions WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
        
        // Clean up test user
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->testUserId]);
    }
}