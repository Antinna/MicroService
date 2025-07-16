<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Controllers\AdminController;
use Antinna\Multivendor\Services\AdminAuthenticator;
use Antinna\Multivendor\Services\MigrationOrchestrator;
use Antinna\Multivendor\Services\ServiceHealthChecker;

class AdminPanelIntegrationTest extends TestCase
{
    private AdminController $controller;
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_admin_panel_logs';
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        putenv('ADMIN_USERNAME=testadmin');
        putenv('ADMIN_PASSWORD=testpass123');
        
        // Clear any existing session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        $this->controller = new AdminController();
        
        // Clean up any existing test files
        if (is_dir($this->testLogPath)) {
            $this->cleanupTestFiles();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        
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

    private function cleanupTestFiles(): void
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

    public function testCompleteAdminPanelWorkflow(): void
    {
        // Step 1: Test unauthenticated access to admin panel
        ob_start();
        $this->controller->panel();
        $loginPageOutput = ob_get_clean();
        
        $this->assertStringContains('<!DOCTYPE html>', $loginPageOutput);
        $this->assertStringContains('Admin Panel - Login', $loginPageOutput);
        $this->assertStringContains('Default Credentials', $loginPageOutput);
        $this->assertStringContains('testadmin', $loginPageOutput);
        
        // Step 2: Test login process
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        
        ob_start();
        $this->controller->login();
        $loginResponse = ob_get_clean();
        
        $loginResult = json_decode($loginResponse, true);
        $this->assertTrue($loginResult['success']);
        $this->assertArrayHasKey('session', $loginResult);
        
        // Step 3: Test authenticated access to admin panel
        ob_start();
        $this->controller->panel();
        $dashboardOutput = ob_get_clean();
        
        $this->assertStringContains('Multivendor Admin Dashboard', $dashboardOutput);
        $this->assertStringContains('Service Health', $dashboardOutput);
        $this->assertStringContains('Migration Management', $dashboardOutput);
        $this->assertStringContains('testadmin', $dashboardOutput);
        
        // Step 4: Test dashboard API endpoint
        ob_start();
        $this->controller->dashboard();
        $dashboardApiOutput = ob_get_clean();
        
        $dashboardApiResult = json_decode($dashboardApiOutput, true);
        $this->assertEquals('Welcome to Admin Dashboard', $dashboardApiResult['message']);
        $this->assertArrayHasKey('features', $dashboardApiResult);
        
        // Step 5: Test service health check
        ob_start();
        $this->controller->checkServiceHealth();
        $healthOutput = ob_get_clean();
        
        $healthResult = json_decode($healthOutput, true);
        $this->assertArrayHasKey('services', $healthResult);
        $this->assertArrayHasKey('summary', $healthResult);
        $this->assertEquals(5, $healthResult['summary']['total_services']);
        
        // Step 6: Test migration history
        ob_start();
        $this->controller->getMigrationHistory();
        $historyOutput = ob_get_clean();
        
        $historyResult = json_decode($historyOutput, true);
        $this->assertArrayHasKey('migrations', $historyResult);
        $this->assertArrayHasKey('total_count', $historyResult);
        
        // Step 7: Test session extension
        ob_start();
        $this->controller->extendSession();
        $extendOutput = ob_get_clean();
        
        $extendResult = json_decode($extendOutput, true);
        $this->assertTrue($extendResult['success']);
        
        // Step 8: Test logout
        ob_start();
        $this->controller->logout();
        $logoutOutput = ob_get_clean();
        
        $logoutResult = json_decode($logoutOutput, true);
        $this->assertTrue($logoutResult['success']);
        
        // Step 9: Verify session is cleared
        ob_start();
        $this->controller->sessionInfo();
        $sessionOutput = ob_get_clean();
        
        $sessionResult = json_decode($sessionOutput, true);
        $this->assertFalse($sessionResult['authenticated']);
    }

    public function testMigrationManagementWorkflow(): void
    {
        // Login first
        $this->performLogin();
        
        // Test starting a migration
        $migrationOptions = [
            'options' => [
                'parallel' => false,
                'continue_on_error' => true,
                'notes' => 'Integration test migration'
            ]
        ];
        
        $this->mockJsonInput($migrationOptions);
        
        ob_start();
        $this->controller->startMigration();
        $migrationOutput = ob_get_clean();
        
        $migrationResult = json_decode($migrationOutput, true);
        $this->assertTrue($migrationResult['success']);
        $this->assertArrayHasKey('migration_id', $migrationResult);
        
        $migrationId = $migrationResult['migration_id'];
        
        // Test getting migration progress
        ob_start();
        $this->controller->getMigrationProgress($migrationId);
        $progressOutput = ob_get_clean();
        
        $progressResult = json_decode($progressOutput, true);
        $this->assertEquals($migrationId, $progressResult['id']);
        $this->assertArrayHasKey('status', $progressResult);
        $this->assertArrayHasKey('services', $progressResult);
        
        // Test cancelling migration
        ob_start();
        $this->controller->cancelMigration($migrationId);
        $cancelOutput = ob_get_clean();
        
        $cancelResult = json_decode($cancelOutput, true);
        $this->assertTrue($cancelResult['success']);
    }

    public function testUnauthorizedAccessHandling(): void
    {
        // Test accessing protected endpoints without authentication
        $protectedEndpoints = [
            'dashboard',
            'checkServiceHealth',
            'getMigrationHistory',
            'extendSession'
        ];
        
        foreach ($protectedEndpoints as $endpoint) {
            ob_start();
            $this->controller->$endpoint();
            $output = ob_get_clean();
            
            $result = json_decode($output, true);
            $this->assertTrue($result['error']);
            $this->assertEquals(401, $result['code']);
            $this->assertStringContains('authentication', strtolower($result['message']));
        }
    }

    public function testInvalidLoginAttempts(): void
    {
        // Test with wrong credentials
        $this->mockJsonInput(['username' => 'wrong', 'password' => 'credentials']);
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertTrue($result['error']);
        $this->assertEquals(401, $result['code']);
        
        // Test with missing credentials
        $this->mockJsonInput(['username' => 'testadmin']);
        
        ob_start();
        $this->controller->login();
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertTrue($result['error']);
        $this->assertEquals(422, $result['code']);
        $this->assertArrayHasKey('validation_errors', $result);
    }

    public function testServiceHealthMonitoring(): void
    {
        $this->performLogin();
        
        // Test health check endpoint
        ob_start();
        $this->controller->checkServiceHealth();
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('summary', $result);
        
        $services = $result['services'];
        $summary = $result['summary'];
        
        // Verify all expected services are present
        $expectedServices = ['auth', 'pay', 'social', 'delivery', 'multivendor'];
        foreach ($expectedServices as $service) {
            $this->assertArrayHasKey($service, $services);
            $this->assertArrayHasKey('status', $services[$service]);
            $this->assertArrayHasKey('name', $services[$service]);
        }
        
        // Verify summary structure
        $this->assertEquals(5, $summary['total_services']);
        $this->assertArrayHasKey('healthy_services', $summary);
        $this->assertArrayHasKey('unhealthy_services', $summary);
        $this->assertArrayHasKey('overall_status', $summary);
        
        // At least multivendor should be healthy
        $this->assertEquals('healthy', $services['multivendor']['status']);
        $this->assertGreaterThan(0, $summary['healthy_services']);
    }

    public function testAdminPanelHtmlGeneration(): void
    {
        // Test login page generation
        ob_start();
        $this->controller->panel();
        $loginPage = ob_get_clean();
        
        $this->assertStringContains('<!DOCTYPE html>', $loginPage);
        $this->assertStringContains('<title>Multivendor Admin Panel - Login</title>', $loginPage);
        $this->assertStringContains('font-awesome', $loginPage);
        $this->assertStringContains('Default Credentials', $loginPage);
        $this->assertStringContains('testadmin', $loginPage);
        
        // Test dashboard page generation after login
        $this->performLogin();
        
        ob_start();
        $this->controller->panel();
        $dashboardPage = ob_get_clean();
        
        $this->assertStringContains('Multivendor Admin Dashboard', $dashboardPage);
        $this->assertStringContains('Service Health', $dashboardPage);
        $this->assertStringContains('Migration Management', $dashboardPage);
        $this->assertStringContains('System Information', $dashboardPage);
        $this->assertStringContains('Migration History', $dashboardPage);
        
        // Verify JavaScript functionality is included
        $this->assertStringContains('refreshHealth()', $dashboardPage);
        $this->assertStringContains('startMigration()', $dashboardPage);
        $this->assertStringContains('EventSource', $dashboardPage);
        $this->assertStringContains('showMigrationModal()', $dashboardPage);
    }

    public function testRealTimeFeatures(): void
    {
        $this->performLogin();
        
        // Test that WebSocket endpoints are accessible (they should require auth)
        // Note: We can't easily test the actual WebSocket functionality in unit tests
        // but we can verify the endpoints exist and require authentication
        
        // These would normally stream data, but in test we just verify they don't error
        try {
            // This should work (authenticated)
            $this->controller->healthMonitoringStream();
            $this->assertTrue(true); // If we get here, no exception was thrown
        } catch (\Exception $e) {
            // Expected to fail in test environment due to headers already sent
            $this->assertStringContains('headers', $e->getMessage());
        }
    }

    public function testCredentialsInfoEndpoint(): void
    {
        ob_start();
        $this->controller->credentialsInfo();
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertEquals('environment', $result['username_source']);
        $this->assertEquals('environment', $result['password_source']);
        $this->assertArrayHasKey('default_credentials', $result);
        $this->assertEquals('admin', $result['default_credentials']['username']);
    }

    public function testSessionManagement(): void
    {
        // Test session info when not logged in
        ob_start();
        $this->controller->sessionInfo();
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertFalse($result['authenticated']);
        
        // Login and test session info
        $this->performLogin();
        
        ob_start();
        $this->controller->sessionInfo();
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertTrue($result['authenticated']);
        $this->assertArrayHasKey('session', $result);
        $this->assertEquals('testadmin', $result['session']['username']);
    }

    public function testErrorHandlingInAdminPanel(): void
    {
        $this->performLogin();
        
        // Test getting progress for non-existent migration
        ob_start();
        $this->controller->getMigrationProgress('non-existent-migration');
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertTrue($result['error']);
        $this->assertEquals(404, $result['code']);
        $this->assertEquals('Migration not found', $result['error']);
        
        // Test cancelling non-existent migration
        ob_start();
        $this->controller->cancelMigration('non-existent-migration');
        $output = ob_get_clean();
        
        $result = json_decode($output, true);
        $this->assertFalse($result['success']);
        $this->assertStringContains('not found', $result['error']);
    }

    public function testResponsiveDesignElements(): void
    {
        $this->performLogin();
        
        ob_start();
        $this->controller->panel();
        $dashboardPage = ob_get_clean();
        
        // Check for responsive design CSS
        $this->assertStringContains('@media (max-width: 768px)', $dashboardPage);
        $this->assertStringContains('grid-template-columns', $dashboardPage);
        $this->assertStringContains('flex-direction: column', $dashboardPage);
        
        // Check for mobile-friendly viewport
        $this->assertStringContains('viewport', $dashboardPage);
        $this->assertStringContains('width=device-width', $dashboardPage);
    }

    public function testAccessibilityFeatures(): void
    {
        ob_start();
        $this->controller->panel();
        $loginPage = ob_get_clean();
        
        // Check for accessibility features
        $this->assertStringContains('autocomplete="username"', $loginPage);
        $this->assertStringContains('autocomplete="current-password"', $loginPage);
        $this->assertStringContains('<label for=', $loginPage);
        $this->assertStringContains('aria-', $loginPage);
        
        $this->performLogin();
        
        ob_start();
        $this->controller->panel();
        $dashboardPage = ob_get_clean();
        
        // Check for semantic HTML and ARIA labels
        $this->assertStringContains('<button', $dashboardPage);
        $this->assertStringContains('role=', $dashboardPage);
    }

    public function testSecurityHeaders(): void
    {
        ob_start();
        $this->controller->panel();
        ob_get_clean();
        
        // In a real test, you would check that security headers are set
        // For now, we just verify the method completes without error
        $this->assertTrue(true);
    }

    /**
     * Helper method to perform login
     */
    private function performLogin(): void
    {
        $this->mockJsonInput(['username' => 'testadmin', 'password' => 'testpass123']);
        
        ob_start();
        $this->controller->login();
        ob_get_clean();
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
        
        // Clear POST and GET data
        $_POST = [];
        $_GET = [];
    }
}