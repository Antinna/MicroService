<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\MigrationOrchestrator;
use Antinna\Multivendor\Services\Logger;

class MigrationOrchestratorTest extends TestCase
{
    private MigrationOrchestrator $orchestrator;
    private Logger $logger;
    private string $testLogPath;
    private string $testProgressFile;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_migration_logs';
        $this->testProgressFile = $this->testLogPath . '/migration_progress.json';
        
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        
        // Clear service URLs to use defaults
        putenv('AUTH_SERVICE_URL=');
        putenv('PAY_SERVICE_URL=');
        putenv('SOCIAL_SERVICE_URL=');
        putenv('DELIVERY_SERVICE_URL=');
        
        $this->logger = $this->createMock(Logger::class);
        $this->orchestrator = new MigrationOrchestrator($this->logger);
        
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
        putenv('AUTH_SERVICE_URL=');
        putenv('PAY_SERVICE_URL=');
        putenv('SOCIAL_SERVICE_URL=');
        putenv('DELIVERY_SERVICE_URL=');
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

    public function testServiceConfiguration(): void
    {
        $services = $this->orchestrator->getServices();
        
        $this->assertIsArray($services);
        $this->assertArrayHasKey('auth', $services);
        $this->assertArrayHasKey('pay', $services);
        $this->assertArrayHasKey('social', $services);
        $this->assertArrayHasKey('delivery', $services);
        $this->assertArrayHasKey('multivendor', $services);
        
        // Check service structure
        foreach ($services as $serviceKey => $serviceConfig) {
            $this->assertArrayHasKey('name', $serviceConfig);
            $this->assertArrayHasKey('url', $serviceConfig);
            $this->assertArrayHasKey('migration_endpoint', $serviceConfig);
            $this->assertArrayHasKey('health_endpoint', $serviceConfig);
        }
        
        // Check default URLs
        $this->assertEquals('http://localhost:8001', $services['auth']['url']);
        $this->assertEquals('http://localhost:8002', $services['pay']['url']);
        $this->assertEquals('http://localhost:8003', $services['social']['url']);
        $this->assertEquals('http://localhost:8004', $services['delivery']['url']);
        $this->assertEquals('local', $services['multivendor']['url']);
    }

    public function testEnvironmentServiceUrls(): void
    {
        putenv('AUTH_SERVICE_URL=http://auth.example.com');
        putenv('PAY_SERVICE_URL=http://pay.example.com');
        
        $orchestrator = new MigrationOrchestrator($this->logger);
        $services = $orchestrator->getServices();
        
        $this->assertEquals('http://auth.example.com', $services['auth']['url']);
        $this->assertEquals('http://pay.example.com', $services['pay']['url']);
    }

    public function testStartMigration(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Starting migration orchestration');

        $result = $this->orchestrator->startMigration(['parallel' => false]);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('migration_id', $result);
        $this->assertArrayHasKey('progress_url', $result);
        $this->assertEquals('Migration started successfully', $result['message']);
        
        // Check that progress was initialized
        $migrationId = $result['migration_id'];
        $progress = $this->orchestrator->getProgress($migrationId);
        
        $this->assertNotNull($progress);
        $this->assertEquals($migrationId, $progress['id']);
        $this->assertEquals('running', $progress['status']);
        $this->assertEquals(5, $progress['total_services']);
        $this->assertArrayHasKey('services', $progress);
    }

    public function testGetProgress(): void
    {
        $result = $this->orchestrator->startMigration();
        $migrationId = $result['migration_id'];
        
        $progress = $this->orchestrator->getProgress($migrationId);
        
        $this->assertNotNull($progress);
        $this->assertEquals($migrationId, $progress['id']);
        $this->assertArrayHasKey('status', $progress);
        $this->assertArrayHasKey('started_at', $progress);
        $this->assertArrayHasKey('services', $progress);
        $this->assertArrayHasKey('total_services', $progress);
        $this->assertArrayHasKey('completed_services', $progress);
        $this->assertArrayHasKey('failed_services', $progress);
    }

    public function testGetProgressNonExistent(): void
    {
        $progress = $this->orchestrator->getProgress('non-existent-id');
        $this->assertNull($progress);
    }

    public function testGetHistory(): void
    {
        // Start multiple migrations
        $result1 = $this->orchestrator->startMigration(['test' => 'migration1']);
        $result2 = $this->orchestrator->startMigration(['test' => 'migration2']);
        
        $history = $this->orchestrator->getHistory();
        
        $this->assertIsArray($history);
        $this->assertCount(2, $history);
        
        $migrationIds = array_column($history, 'id');
        $this->assertContains($result1['migration_id'], $migrationIds);
        $this->assertContains($result2['migration_id'], $migrationIds);
    }

    public function testCheckServiceHealthLocal(): void
    {
        $health = $this->orchestrator->checkServiceHealth('multivendor');
        
        $this->assertEquals('multivendor', $health['service']);
        $this->assertEquals('Multivendor Service', $health['name']);
        $this->assertEquals('healthy', $health['status']);
        $this->assertEquals('local', $health['url']);
    }

    public function testCheckServiceHealthUnknown(): void
    {
        $health = $this->orchestrator->checkServiceHealth('unknown-service');
        
        $this->assertEquals('unknown-service', $health['service']);
        $this->assertEquals('unknown', $health['status']);
        $this->assertEquals('Service not configured', $health['error']);
    }

    public function testCheckServiceHealthRemote(): void
    {
        // This will fail since we don't have actual services running
        $health = $this->orchestrator->checkServiceHealth('auth');
        
        $this->assertEquals('auth', $health['service']);
        $this->assertEquals('Authentication Service', $health['name']);
        $this->assertEquals('unhealthy', $health['status']);
        $this->assertArrayHasKey('error', $health);
        $this->assertEquals('http://localhost:8001', $health['url']);
    }

    public function testCheckAllServicesHealth(): void
    {
        $healthResults = $this->orchestrator->checkAllServicesHealth();
        
        $this->assertIsArray($healthResults);
        $this->assertCount(5, $healthResults);
        
        // Check that all services are included
        $this->assertArrayHasKey('auth', $healthResults);
        $this->assertArrayHasKey('pay', $healthResults);
        $this->assertArrayHasKey('social', $healthResults);
        $this->assertArrayHasKey('delivery', $healthResults);
        $this->assertArrayHasKey('multivendor', $healthResults);
        
        // Multivendor should be healthy (local)
        $this->assertEquals('healthy', $healthResults['multivendor']['status']);
        
        // Remote services should be unhealthy (not running in test)
        $this->assertEquals('unhealthy', $healthResults['auth']['status']);
        $this->assertEquals('unhealthy', $healthResults['pay']['status']);
    }

    public function testCancelMigration(): void
    {
        $result = $this->orchestrator->startMigration();
        $migrationId = $result['migration_id'];
        
        // Wait a moment for migration to start
        usleep(100000); // 0.1 seconds
        
        $cancelResult = $this->orchestrator->cancelMigration($migrationId);
        
        $this->assertTrue($cancelResult['success']);
        $this->assertEquals('Migration cancelled successfully', $cancelResult['message']);
        
        // Check that migration status is updated
        $progress = $this->orchestrator->getProgress($migrationId);
        $this->assertEquals('cancelled', $progress['status']);
        $this->assertArrayHasKey('completed_at', $progress);
    }

    public function testCancelNonExistentMigration(): void
    {
        $result = $this->orchestrator->cancelMigration('non-existent-id');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Migration not found', $result['error']);
    }

    public function testCancelCompletedMigration(): void
    {
        $result = $this->orchestrator->startMigration();
        $migrationId = $result['migration_id'];
        
        // Manually set migration as completed
        $progress = $this->orchestrator->getProgress($migrationId);
        $progress['status'] = 'completed';
        
        // Use reflection to update the progress (since it's private)
        $reflection = new \ReflectionClass($this->orchestrator);
        $progressProperty = $reflection->getProperty('migrationProgress');
        $progressProperty->setAccessible(true);
        $progressData = $progressProperty->getValue($this->orchestrator);
        $progressData[$migrationId] = $progress;
        $progressProperty->setValue($this->orchestrator, $progressData);
        
        $cancelResult = $this->orchestrator->cancelMigration($migrationId);
        
        $this->assertFalse($cancelResult['success']);
        $this->assertEquals('Migration is not running', $cancelResult['error']);
    }

    public function testMigrationWithOptions(): void
    {
        $options = [
            'parallel' => true,
            'continue_on_error' => true,
            'custom_option' => 'test_value'
        ];
        
        $result = $this->orchestrator->startMigration($options);
        $migrationId = $result['migration_id'];
        
        $progress = $this->orchestrator->getProgress($migrationId);
        
        $this->assertEquals($options, $progress['options']);
    }

    public function testProgressFilePersistence(): void
    {
        $result = $this->orchestrator->startMigration();
        $migrationId = $result['migration_id'];
        
        // Create new orchestrator instance to test persistence
        $newOrchestrator = new MigrationOrchestrator($this->logger);
        $progress = $newOrchestrator->getProgress($migrationId);
        
        $this->assertNotNull($progress);
        $this->assertEquals($migrationId, $progress['id']);
    }

    public function testMigrationClassNameGeneration(): void
    {
        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('getMigrationClassName');
        $method->setAccessible(true);
        
        $testCases = [
            '/path/to/001_CreateUsersTable.php' => 'Antinna\\Multivendor\\Database\\Migrations\\CreateUsersTable',
            '/path/to/002_AddIndexesToProducts.php' => 'Antinna\\Multivendor\\Database\\Migrations\\AddIndexesToProducts',
            '/path/to/invalid_filename.php' => ''
        ];
        
        foreach ($testCases as $filePath => $expectedClassName) {
            $result = $method->invoke($this->orchestrator, $filePath);
            $this->assertEquals($expectedClassName, $result);
        }
    }

    public function testServiceStatusUpdates(): void
    {
        $result = $this->orchestrator->startMigration();
        $migrationId = $result['migration_id'];
        
        $progress = $this->orchestrator->getProgress($migrationId);
        
        // Check initial service statuses
        foreach ($progress['services'] as $serviceKey => $serviceData) {
            $this->assertEquals('pending', $serviceData['status']);
            $this->assertNull($serviceData['started_at']);
            $this->assertNull($serviceData['completed_at']);
            $this->assertNull($serviceData['error']);
        }
    }

    public function testLoggingIntegration(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->orchestrator->startMigration();
        $this->orchestrator->checkServiceHealth('multivendor');
        $this->orchestrator->checkAllServicesHealth();
    }
}