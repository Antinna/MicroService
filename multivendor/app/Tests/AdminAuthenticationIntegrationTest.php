<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Controllers\AdminController;
use Antinna\Multivendor\Services\AdminAuthenticator;

class AdminAuthenticationIntegrationTest extends TestCase
{
    private AdminController $controller;
    private string $testLogPath;

    protected function setUp(): void
    {
        // Set up test environment
        $this->testLogPath = sys_get_temp_dir() . '/test_admin_logs';
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        putenv('ADMIN_USERNAME=testadmin');
        putenv('ADMIN_PASSWORD=testpass123');
        
        // Clear any existing session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        $this->controller = new AdminController();
        
        // Clean up any existing test logs
        if (is_dir($this->testLogPath)) {
            $this->cleanupTestLogs();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestLogs();
        
        // Clean up session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        // Clear environment variables
        putenv('LOG_PATH=');
        putenv('LOG_LEVEL=');
        putenv('ADMIN_USERNAME=');
        putenv('ADMIN_PASSWORD=');
    }

    private function cleanupTestLogs(): void
    {
        $files = glob($this->testLogPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testLogPath)) {
            rmdir($this->testLogPath);
        }
    }

    public function testCompleteAuthenticationFlow(): void
    {
        // Test login
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        
        ob_start();
        $this->controller->login();
        $loginOutput = ob_get_clean();
        
        $loginResponse = json_decode($loginOutput, true);
        $this->assertTrue($loginResponse['success']);
        $this->assertEquals('Authentication successful', $loginResponse['message']);
        $this->assertArrayHasKey('session', $loginResponse);
        
        // Test session info
        ob_start();
        $this->controller->sessionInfo();
        $sessionOutput = ob_get_clean();
        
        $sessionResponse = json_decode($sessionOutput, true);
        $this->assertTrue($sessionResponse['authenticated']);
        $this->assertEquals('testadmin', $sessionResponse['session']['username']);
        
        // Test dashboard access
        ob_start();
        $this->controller->dashboard();
        $dashboardOutput = ob_get_clean();
        
        $dashboardResponse = json_decode($dashboardOutput, true);
        $this->assertEquals('Welcome to Admin Dashboard', $dashboardResponse['message']);
        $this->assertArrayHasKey('features', $dashboardResponse);
        
        // Test session extension
        ob_start();
        $this->controller->extendSession();
        $extendOutput = ob_get_clean();
        
        $extendResponse = json_decode($extendOutput, true);
        $this->assertTrue($extendResponse['success']);
        $this->assertEquals('Session extended', $extendResponse['message']);
        
        // Test logout
        ob_start();
        $this->controller->logout();
        $logoutOutput = ob_get_clean();
        
        $logoutResponse = json_decode($logoutOutput, true);
        $this->assertTrue($logoutResponse['success']);
        $this->assertEquals('Logout successful', $logoutResponse['message']);
        
        // Verify session is cleared
        ob_start();
        $this->controller->sessionInfo();
        $finalSessionOutput = ob_get_clean();
        
        $finalSessionResponse = json_decode($finalSessionOutput, true);
        $this->assertFalse($finalSessionResponse['authenticated']);
    }

    public function testInvalidLoginAttempt(): void
    {
        $this->mockJsonInput(['username' => 'wrong', 'password' => 'credentials']);
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(401, $response['code']);
        $this->assertStringContains('Invalid admin credentials', $response['message']);
    }

    public function testValidationErrors(): void
    {
        // Test missing username
        $this->mockJsonInput(['password' => 'testpass123']);
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(422, $response['code']);
        $this->assertArrayHasKey('validation_errors', $response);
        $this->assertArrayHasKey('username', $response['validation_errors']);
        
        // Test missing password
        $this->mockJsonInput(['username' => 'testadmin']);
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertArrayHasKey('password', $response['validation_errors']);
    }

    public function testInvalidJsonInput(): void
    {
        // Mock invalid JSON
        $this->mockRawInput('invalid json');
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(422, $response['code']);
        $this->assertStringContains('Invalid JSON format', $response['message']);
    }

    public function testEmptyRequestBody(): void
    {
        $this->mockRawInput('');
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(422, $response['code']);
        $this->assertStringContains('Request body is required', $response['message']);
    }

    public function testUnauthorizedDashboardAccess(): void
    {
        ob_start();
        $this->controller->dashboard();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(401, $response['code']);
        $this->assertStringContains('Admin authentication required', $response['message']);
    }

    public function testUnauthorizedSessionExtension(): void
    {
        ob_start();
        $this->controller->extendSession();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(401, $response['code']);
        $this->assertStringContains('No active admin session', $response['message']);
    }

    public function testCredentialsInfo(): void
    {
        ob_start();
        $this->controller->credentialsInfo();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertEquals('environment', $response['username_source']);
        $this->assertEquals('environment', $response['password_source']);
        $this->assertArrayHasKey('default_credentials', $response);
    }

    public function testAuthenticationValidation(): void
    {
        // Test without authentication
        ob_start();
        $this->controller->validateAuth();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['error']);
        $this->assertEquals(401, $response['code']);
        
        // Login first
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        ob_start();
        $this->controller->login();
        ob_get_clean();
        
        // Test with authentication
        ob_start();
        $this->controller->validateAuth();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        $this->assertTrue($response['authenticated']);
        $this->assertEquals('Authentication valid', $response['message']);
    }

    public function testLoggingIntegration(): void
    {
        // Perform login to generate logs
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        
        ob_start();
        $this->controller->login();
        ob_get_clean();
        
        // Check if logs were created
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $this->assertTrue(file_exists($logFile));
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContains('Admin authentication attempt', $logContent);
        $this->assertStringContains('Admin authentication successful', $logContent);
        $this->assertStringContains('testadmin', $logContent);
    }

    public function testSessionSecurityFeatures(): void
    {
        // Mock HTTPS environment
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        
        ob_start();
        $this->controller->login();
        $loginOutput = ob_get_clean();
        
        $loginResponse = json_decode($loginOutput, true);
        $this->assertTrue($loginResponse['success']);
        
        // Verify session is active
        ob_start();
        $this->controller->sessionInfo();
        $sessionOutput = ob_get_clean();
        
        $sessionResponse = json_decode($sessionOutput, true);
        $this->assertTrue($sessionResponse['authenticated']);
        
        // Change IP to test security
        $_SERVER['REMOTE_ADDR'] = '192.168.1.200';
        
        // Session should be invalidated due to IP change
        ob_start();
        $this->controller->sessionInfo();
        $newSessionOutput = ob_get_clean();
        
        $newSessionResponse = json_decode($newSessionOutput, true);
        $this->assertFalse($newSessionResponse['authenticated']);
        
        // Clean up
        unset($_SERVER['HTTPS'], $_SERVER['REMOTE_ADDR']);
    }

    public function testAdminPanelHtmlGeneration(): void
    {
        // Test panel without authentication
        ob_start();
        $this->controller->panel();
        $output = ob_get_clean();
        
        $this->assertStringContains('<!DOCTYPE html>', $output);
        $this->assertStringContains('Multivendor Admin Panel', $output);
        $this->assertStringContains('Admin Login', $output);
        $this->assertStringContains('Default Credentials', $output);
        
        // Login first
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        ob_start();
        $this->controller->login();
        ob_get_clean();
        
        // Test panel with authentication
        ob_start();
        $this->controller->panel();
        $authenticatedOutput = ob_get_clean();
        
        $this->assertStringContains('Admin Dashboard', $output);
        $this->assertStringContains('Session Information', $authenticatedOutput);
        $this->assertStringContains('testadmin', $authenticatedOutput);
        $this->assertStringContains('Migration Management', $authenticatedOutput);
    }

    /**
     * Mock JSON input for testing
     */
    private function mockJsonInput(array $data): void
    {
        $this->mockRawInput(json_encode($data));
    }

    /**
     * Mock raw input for testing
     */
    private function mockRawInput(string $input): void
    {
        // Create a temporary file with the input data
        $tempFile = tmpfile();
        fwrite($tempFile, $input);
        rewind($tempFile);
        
        // Override the input stream (this is a simplified mock)
        // In real integration tests, you might use a more sophisticated approach
        $_POST = []; // Clear POST data
        $_GET = [];  // Clear GET data
    }
}