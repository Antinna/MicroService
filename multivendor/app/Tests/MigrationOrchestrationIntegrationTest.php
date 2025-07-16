<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\MigrationOrchestrator;
use Antinna\Multivendor\Services\WebSocketHandler;
use Antinna\Multivendor\Services\Logger;

class MigrationOrchestrationIntegrationTest extends TestCase
{
    private MigrationOrchestrator $orchestrator;
    private WebSocketHandler $webSocketHandler;
    private Logger $logger;
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_integration_logs';
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        
        $this->logger = new Logger();
        $this->orchestrator = new MigrationOrchestrator($this->logger);
        $this->webSocketHandler = new WebSocketHandler($this->logger);
        
        // Clean up any existing test files
        if (is_dir($this->testLogPath)) {
            $this->cleanupTestFiles();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        putenv('LOG_PATH=');
        putenv('LOG_LEVEL=');
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

    public function testCompleteMigrationOrchestrationFlow(): void
    {
        // Step 1: Check initial service health
        $healthBefore = $this->orchestrator->checkAllServicesHealth();
        $this->assertIsArray($healthBefore);
        $this->assertCount(5, $healthBefore);
        
        // Multivendor should be healthy (local)
        $this->assertEquals('healthy', $healthBefore['multivendor']['status']);
        
        // Step 2: Start migration
        $migrationResult = $this->orchestrator->startMigration([
            'parallel' => false,
            'continue_on_error' => true,
            'test_mode' => true
        ]);
        
        $this->assertTrue($migrationResult['success']);
        $this->assertArrayHasKey('migration_id', $migrationResult);
        
        $migrationId = $migrationResult['migration_id'];
        
        // Step 3: Check initial progress
        $initialProgress = $this->orchestrator->getProgress($migrationId);
        $this->assertNotNull($initialProgress);
        $this->assertEquals($migrationId, $initialProgress['id']);
        $this->assertEquals('running', $initialProgress['status']);
        $this->assertEquals(5, $initialProgress['total_services']);
        
        // Step 4: Verify all services are initialized
        $expectedServices = ['auth', 'pay', 'social', 'delivery', 'multivendor'];
        foreach ($expectedServices as $service) {
            $this->assertArrayHasKey($service, $initialProgress['services']);
            $this->assertEquals('pending', $initialProgress['services'][$service]['status']);
        }
        
        // Step 5: Wait for migration to process (simulate)
        sleep(1);
        
        // Step 6: Check updated progress
        $updatedProgress = $this->orchestrator->getProgress($migrationId);
        $this->assertNotNull($updatedProgress);
        
        // Step 7: Test WebSocket progress data generation
        $reflection = new \ReflectionClass($this->webSocketHandler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $wsProgress = $method->invoke($this->webSocketHandler, $migrationId);
        
        if ($wsProgress) {
            $this->assertArrayHasKey('overall_progress', $wsProgress);
            $this->assertIsFloat($wsProgress['overall_progress']);
            $this->assertGreaterThanOrEqual(0, $wsProgress['overall_progress']);
            $this->assertLessThanOrEqual(100, $wsProgress['overall_progress']);
        }
        
        // Step 8: Test migration history
        $history = $this->orchestrator->getHistory();
        $this->assertIsArray($history);
        $this->assertGreaterThan(0, count($history));
        
        $migrationIds = array_column($history, 'id');
        $this->assertContains($migrationId, $migrationIds);
        
        // Step 9: Test service health after migration
        $healthAfter = $this->orchestrator->checkAllServicesHealth();
        $this->assertIsArray($healthAfter);
        $this->assertEquals('healthy', $healthAfter['multivendor']['status']);
    }

    public function testMigrationCancellation(): void
    {
        // Start migration
        $result = $this->orchestrator->startMigration(['test_mode' => true]);
        $migrationId = $result['migration_id'];
        
        // Verify migration is running
        $progress = $this->orchestrator->getProgress($migrationId);
        $this->assertEquals('running', $progress['status']);
        
        // Cancel migration
        $cancelResult = $this->orchestrator->cancelMigration($migrationId);
        $this->assertTrue($cancelResult['success']);
        
        // Verify migration is cancelled
        $finalProgress = $this->orchestrator->getProgress($migrationId);
        $this->assertEquals('cancelled', $finalProgress['status']);
        $this->assertArrayHasKey('completed_at', $finalProgress);
    }

    public function testParallelMigrationExecution(): void
    {
        $result = $this->orchestrator->startMigration([
            'parallel' => true,
            'test_mode' => true
        ]);
        
        $this->assertTrue($result['success']);
        $migrationId = $result['migration_id'];
        
        $progress = $this->orchestrator->getProgress($migrationId);
        $this->assertNotNull($progress);
        $this->assertTrue($progress['options']['parallel']);
    }

    public function testMigrationWithContinueOnError(): void
    {
        $result = $this->orchestrator->startMigration([
            'continue_on_error' => true,
            'test_mode' => true
        ]);
        
        $this->assertTrue($result['success']);
        $migrationId = $result['migration_id'];
        
        $progress = $this->orchestrator->getProgress($migrationId);
        $this->assertTrue($progress['options']['continue_on_error']);
    }

    public function testWebSocketClientScriptGeneration(): void
    {
        $result = $this->orchestrator->startMigration(['test_mode' => true]);
        $migrationId = $result['migration_id'];
        
        // Generate client script
        $clientScript = $this->webSocketHandler->generateClientScript($migrationId);
        
        $this->assertIsString($clientScript);
        $this->assertStringContains($migrationId, $clientScript);
        $this->assertStringContains('MigrationProgressClient', $clientScript);
        
        // Verify script contains all required functionality
        $requiredFeatures = [
            'connect()',
            'disconnect()',
            'on(',
            'trigger(',
            'EventSource',
            'progress',
            'finished',
            'heartbeat'
        ];
        
        foreach ($requiredFeatures as $feature) {
            $this->assertStringContains($feature, $clientScript);
        }
    }

    public function testHealthMonitoringIntegration(): void
    {
        // Generate health monitoring script
        $healthScript = $this->webSocketHandler->generateHealthClientScript();
        
        $this->assertIsString($healthScript);
        $this->assertStringContains('HealthMonitoringClient', $healthScript);
        $this->assertStringContains('health_update', $healthScript);
        
        // Test health monitoring data
        $healthData = $this->orchestrator->checkAllServicesHealth();
        
        $healthyCount = count(array_filter($healthData, fn($s) => $s['status'] === 'healthy'));
        $totalCount = count($healthData);
        
        $this->assertGreaterThan(0, $healthyCount); // At least multivendor should be healthy
        $this->assertEquals(5, $totalCount);
    }

    public function testMigrationProgressPersistence(): void
    {
        // Start migration
        $result = $this->orchestrator->startMigration(['test_mode' => true]);
        $migrationId = $result['migration_id'];
        
        // Get initial progress
        $initialProgress = $this->orchestrator->getProgress($migrationId);
        $this->assertNotNull($initialProgress);
        
        // Create new orchestrator instance to test persistence
        $newOrchestrator = new MigrationOrchestrator($this->logger);
        $persistedProgress = $newOrchestrator->getProgress($migrationId);
        
        $this->assertNotNull($persistedProgress);
        $this->assertEquals($migrationId, $persistedProgress['id']);
        $this->assertEquals($initialProgress['status'], $persistedProgress['status']);
        $this->assertEquals($initialProgress['total_services'], $persistedProgress['total_services']);
    }

    public function testMultipleConcurrentMigrations(): void
    {
        // Start multiple migrations
        $migration1 = $this->orchestrator->startMigration(['name' => 'migration1']);
        $migration2 = $this->orchestrator->startMigration(['name' => 'migration2']);
        $migration3 = $this->orchestrator->startMigration(['name' => 'migration3']);
        
        $this->assertTrue($migration1['success']);
        $this->assertTrue($migration2['success']);
        $this->assertTrue($migration3['success']);
        
        // Verify all migrations are tracked
        $history = $this->orchestrator->getHistory();
        $this->assertGreaterThanOrEqual(3, count($history));
        
        $migrationIds = array_column($history, 'id');
        $this->assertContains($migration1['migration_id'], $migrationIds);
        $this->assertContains($migration2['migration_id'], $migrationIds);
        $this->assertContains($migration3['migration_id'], $migrationIds);
        
        // Verify each migration has unique ID and proper structure
        foreach ([$migration1, $migration2, $migration3] as $migration) {
            $progress = $this->orchestrator->getProgress($migration['migration_id']);
            $this->assertNotNull($progress);
            $this->assertEquals($migration['migration_id'], $progress['id']);
            $this->assertArrayHasKey('services', $progress);
            $this->assertEquals(5, $progress['total_services']);
        }
    }

    public function testServiceConfigurationWithEnvironmentVariables(): void
    {
        // Set custom service URLs
        putenv('AUTH_SERVICE_URL=http://custom-auth.example.com');
        putenv('PAY_SERVICE_URL=http://custom-pay.example.com');
        
        $customOrchestrator = new MigrationOrchestrator($this->logger);
        $services = $customOrchestrator->getServices();
        
        $this->assertEquals('http://custom-auth.example.com', $services['auth']['url']);
        $this->assertEquals('http://custom-pay.example.com', $services['pay']['url']);
        
        // Clean up
        putenv('AUTH_SERVICE_URL=');
        putenv('PAY_SERVICE_URL=');
    }

    public function testErrorHandlingInMigrationFlow(): void
    {
        // Test with invalid migration ID
        $progress = $this->orchestrator->getProgress('invalid-migration-id');
        $this->assertNull($progress);
        
        // Test cancelling non-existent migration
        $cancelResult = $this->orchestrator->cancelMigration('non-existent-id');
        $this->assertFalse($cancelResult['success']);
        $this->assertArrayHasKey('error', $cancelResult);
        
        // Test health check for unknown service
        $health = $this->orchestrator->checkServiceHealth('unknown-service');
        $this->assertEquals('unknown', $health['status']);
        $this->assertArrayHasKey('error', $health);
    }

    public function testLoggingIntegrationThroughoutFlow(): void
    {
        // Start migration (should generate logs)
        $result = $this->orchestrator->startMigration(['test_mode' => true]);
        $migrationId = $result['migration_id'];
        
        // Check service health (should generate logs)
        $this->orchestrator->checkAllServicesHealth();
        
        // Cancel migration (should generate logs)
        $this->orchestrator->cancelMigration($migrationId);
        
        // Verify log files were created
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $this->assertTrue(file_exists($logFile));
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContains('Starting migration orchestration', $logContent);
        $this->assertStringContains('Migration cancelled', $logContent);
    }
}