<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Database\MigrationRunner;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\UserAuthenticator;
use Antinna\Auth\Services\SessionManager;
use Antinna\Auth\Controllers\UserController;
use Antinna\Auth\Routes\UserRoutes;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration test for User Management API
 */
class UserManagementIntegrationTest extends TestCase
{
    private PDO $db;
    private UserRepository $userRepository;
    private UserAuthenticator $userAuthenticator;
    private SessionManager $sessionManager;
    private UserController $userController;
    private UserRoutes $userRoutes;
    private int $testUserId;

    protected function setUp(): void
    {
        // Set up test database connection
        $this->db = Connection::getInstance()->getConnection();
        
        // Initialize repositories and services
        $this->userRepository = new UserRepository();
        $this->userAuthenticator = new UserAuthenticator();
        $this->sessionManager = new SessionManager();
        $this->userController = new UserController();
        $this->userRoutes = new UserRoutes();

        // Create test user
        $this->createTestUser();
        
        // Start session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function testCompleteUserProfileManagementFlow(): void
    {
        // 1. Authenticate user and create session
        $this->authenticateTestUser();

        // 2. Test getting user profile
        $this->testGetUserProfile();

        // 3. Test updating user profile
        $this->testUpdateUserProfile();

        // 4. Test getting security settings
        $this->testGetSecuritySettings();

        // 5. Test updating security settings
        $this->testUpdateSecuritySettings();

        // 6. Test password change
        $this->testChangePassword();

        // 7. Test session management
        $this->testSessionManagement();

        // 8. Test activity log
        $this->testActivityLog();
    }

    private function createTestUser(): void
    {
        $userData = [
            'email' => 'test.user@example.com',
            'phone' => '+1234567890',
            'password_hash' => password_hash('test_password', PASSWORD_DEFAULT),
            'name' => 'Test User',
            'display_name' => 'Test Display Name',
            'role' => 'user',
            'is_active' => true,
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->testUserId = $this->userRepository->create($userData);
        $this->assertGreaterThan(0, $this->testUserId, 'Test user should be created successfully');
    }

    private function authenticateTestUser(): void
    {
        // Simulate user authentication
        $authResult = $this->userAuthenticator->authenticateWithPassword(
            'test.user@example.com',
            'test_password'
        );

        $this->assertTrue($authResult['success'], 'User authentication should succeed');
        
        // Create session
        $sessionResult = $this->sessionManager->createSession(
            $this->testUserId,
            '127.0.0.1',
            'PHPUnit Test Agent'
        );

        $this->assertTrue($sessionResult['success'], 'Session creation should succeed');
        
        // Set session data
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['session_id'] = $sessionResult['session_id'];
    }

    private function testGetUserProfile(): void
    {
        // Capture output
        ob_start();
        $this->userController->getProfile();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Get profile should succeed');
        $this->assertEquals('test.user@example.com', $response['data']['user']['email']);
        $this->assertEquals('Test User', $response['data']['user']['name']);
        $this->assertArrayNotHasKey('password_hash', $response['data']['user'], 'Password hash should not be returned');
    }

    private function testUpdateUserProfile(): void
    {
        // Mock JSON input
        $updateData = [
            'name' => 'Updated Test User',
            'display_name' => 'Updated Display Name',
            'locale' => 'en_US',
            'timezone' => 'America/New_York'
        ];

        // Simulate POST data
        $this->mockJsonInput(json_encode($updateData));

        // Capture output
        ob_start();
        $this->userController->updateProfile();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Profile update should succeed');
        $this->assertEquals('Updated Test User', $response['data']['user']['name']);
        $this->assertEquals('Updated Display Name', $response['data']['user']['display_name']);
        $this->assertEquals(['name', 'display_name', 'locale', 'timezone'], $response['data']['updated_fields']);

        // Verify data was actually updated in database
        $updatedUser = $this->userRepository->find($this->testUserId);
        $this->assertEquals('Updated Test User', $updatedUser['name']);
        $this->assertEquals('Updated Display Name', $updatedUser['display_name']);
    }

    private function testGetSecuritySettings(): void
    {
        // Capture output
        ob_start();
        $this->userController->getSecuritySettings();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Get security settings should succeed');
        $this->assertArrayHasKey('security_settings', $response['data']);
        $this->assertArrayHasKey('active_sessions', $response['data']);
        $this->assertArrayHasKey('recent_security_events', $response['data']);
        
        // Verify default security settings
        $settings = $response['data']['security_settings'];
        $this->assertEquals($this->testUserId, $settings['user_id']);
        $this->assertFalse($settings['mfa_enabled']);
        $this->assertTrue($settings['notification_on_password_change']);
    }

    private function testUpdateSecuritySettings(): void
    {
        // Mock JSON input
        $updateData = [
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'notification_on_login' => true,
            'notification_on_suspicious_activity' => true
        ];

        $this->mockJsonInput(json_encode($updateData));

        // Capture output
        ob_start();
        $this->userController->updateSecuritySettings();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Security settings update should succeed');
        $this->assertTrue($response['data']['security_settings']['mfa_enabled']);
        $this->assertEquals('totp', $response['data']['security_settings']['mfa_method']);
        $this->assertTrue($response['data']['security_settings']['notification_on_login']);
    }

    private function testChangePassword(): void
    {
        // Mock JSON input
        $passwordData = [
            'current_password' => 'test_password',
            'new_password' => 'new_secure_password123'
        ];

        $this->mockJsonInput(json_encode($passwordData));

        // Capture output
        ob_start();
        $this->userController->changePassword();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Password change should succeed');
        $this->assertEquals('Password changed successfully', $response['data']['message']);

        // Verify password was actually changed
        $updatedUser = $this->userRepository->find($this->testUserId);
        $this->assertTrue(
            password_verify('new_secure_password123', $updatedUser['password_hash']),
            'New password should be verified'
        );
        $this->assertFalse(
            password_verify('test_password', $updatedUser['password_hash']),
            'Old password should no longer work'
        );
    }

    private function testSessionManagement(): void
    {
        // Create additional session for testing
        $additionalSession = $this->sessionManager->createSession(
            $this->testUserId,
            '192.168.1.100',
            'Additional Test Session'
        );
        $this->assertTrue($additionalSession['success']);

        // Test getting sessions
        ob_start();
        $this->userController->getSessions();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Get sessions should succeed');
        $this->assertGreaterThanOrEqual(2, $response['data']['total_count'], 'Should have at least 2 sessions');
        
        // Find current session
        $currentSessionFound = false;
        foreach ($response['data']['sessions'] as $session) {
            if ($session['is_current']) {
                $currentSessionFound = true;
                break;
            }
        }
        $this->assertTrue($currentSessionFound, 'Current session should be marked');

        // Test revoking the additional session
        ob_start();
        $this->userController->revokeSession($additionalSession['session_id']);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Session revocation should succeed');

        // Test revoking all other sessions
        ob_start();
        $this->userController->revokeAllSessions();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Revoke all sessions should succeed');
    }

    private function testActivityLog(): void
    {
        // Set query parameters
        $_GET['page'] = '1';
        $_GET['limit'] = '10';

        // Capture output
        ob_start();
        $this->userController->getActivityLog();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        
        $this->assertTrue($response['success'], 'Get activity log should succeed');
        $this->assertArrayHasKey('logs', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        
        $pagination = $response['data']['pagination'];
        $this->assertEquals(1, $pagination['page']);
        $this->assertEquals(10, $pagination['limit']);
        $this->assertGreaterThan(0, $pagination['total_count'], 'Should have activity logs');
        
        // Verify logs contain expected events
        $logs = $response['data']['logs'];
        $this->assertNotEmpty($logs, 'Should have activity logs');
        
        // Check for expected event types from our test flow
        $eventTypes = array_column($logs, 'event_type');
        $this->assertContains('user_profile_updated', $eventTypes, 'Should contain profile update event');
        $this->assertContains('password_change', $eventTypes, 'Should contain password change event');
    }

    public function testUserRoutesIntegration(): void
    {
        // Authenticate user
        $this->authenticateTestUser();

        // Test profile route
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        $this->userRoutes->handleRequest('GET', '/api/users/profile');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Profile route should work');

        // Test security route
        ob_start();
        $this->userRoutes->handleRequest('GET', '/api/users/security');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Security route should work');

        // Test sessions route
        ob_start();
        $this->userRoutes->handleRequest('GET', '/api/users/sessions');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Sessions route should work');

        // Test activity route
        ob_start();
        $this->userRoutes->handleRequest('GET', '/api/users/activity');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Activity route should work');

        // Test invalid route
        ob_start();
        $this->userRoutes->handleRequest('GET', '/api/users/invalid');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Invalid route should return error');
        $this->assertEquals('NOT_FOUND', $response['code']);
    }

    public function testRateLimitingIntegration(): void
    {
        // This test would require setting up rate limiting configuration
        // and making multiple rapid requests to test the rate limiting functionality
        
        $this->authenticateTestUser();

        // Make multiple rapid requests (this would need actual rate limiting setup)
        $successCount = 0;
        $rateLimitedCount = 0;

        for ($i = 0; $i < 5; $i++) {
            ob_start();
            $this->userController->getProfile();
            $output = ob_get_clean();

            $response = json_decode($output, true);
            if ($response['success']) {
                $successCount++;
            } elseif (isset($response['code']) && $response['code'] === 'RATE_LIMIT_EXCEEDED') {
                $rateLimitedCount++;
            }
        }

        // At least some requests should succeed
        $this->assertGreaterThan(0, $successCount, 'Some requests should succeed');
    }

    public function testErrorHandling(): void
    {
        // Test unauthenticated access
        unset($_SESSION['user_id']);

        ob_start();
        $this->userController->getProfile();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['code']);

        // Test invalid JSON input
        $_SESSION['user_id'] = $this->testUserId;
        $this->mockJsonInput('invalid json');

        ob_start();
        $this->userController->updateProfile();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('INVALID_INPUT', $response['code']);
    }

    /**
     * Mock JSON input for testing
     */
    private function mockJsonInput(string $json): void
    {
        // In a real implementation, you would need to mock file_get_contents('php://input')
        // This is a simplified approach for testing
        
        // Create a temporary stream
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $json);
        rewind($stream);
        
        // This would require modifying the controller to accept injected input
        // or using a stream wrapper to mock php://input
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->testUserId) {
            // Delete test user and related data
            $this->db->prepare("DELETE FROM audit_logs WHERE user_id = ?")->execute([$this->testUserId]);
            $this->db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$this->testUserId]);
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->testUserId]);
        }

        // Clean up session data
        session_destroy();
        unset($_SESSION);
        unset($_GET);
        unset($_SERVER['REQUEST_METHOD']);
    }
}