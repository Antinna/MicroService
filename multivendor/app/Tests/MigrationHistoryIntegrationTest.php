<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\MigrationHistoryTracker;
use Antinna\Multivendor\Services\ServiceHealthChecker;
use Antinna\Multivendor\Services\MigrationRollbackService;
use Antinna\Multivendor\Services\Logger;

class MigrationHistoryIntegrationTest extends TestCase
{
    private MigrationHistoryTracker $historyTracker;
    private ServiceHealthChecker $healthChecker;
    private MigrationRollbackService $rollbackService;
    private Logger $logger;
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_history_logs';
        putenv('LOG_PATH=' . $this->testLogPath);
        putenv('LOG_LEVEL=DEBUG');
        
        $this->logger = new Logger();
        $this->historyTracker = new MigrationHistoryTracker($this->logger);
        $this->healthChecker = new ServiceHealthChecker($this->logger);
        $this->rollbackService = new MigrationRollbackService($this->logger);
        
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

    public function testCompleteMigrationLifecycle(): void
    {
        $migrationId = 'integration-test-migration';
        $services = ['auth', 'pay', 'social'];
        
        // Step 1: Record migration starts
        foreach ($services as $service) {
            $result = $this->historyTracker->recordMigrationStart(
                $migrationId, 
                $service, 
                "Create{$service}Tables",
                ['version' => '1.0', 'type' => 'schema']
            );
            $this->assertTrue($result);
        }
        
        // Step 2: Verify all migrations are recorded as running
        $history = $this->historyTracker->getMigrationHistory($migrationId);
        $this->assertCount(3, $history);
        
        foreach ($history as $record) {
            $this->assertEquals('running', $record['status']);
            $this->assertEquals($migrationId, $record['migration_id']);
            $this->assertNotNull($record['started_at']);
        }
        
        // Step 3: Complete some migrations successfully, fail others
        $this->historyTracker->recordMigrationCompletion($migrationId, 'auth', true);
        $this->historyTracker->recordMigrationCompletion($migrationId, 'pay', true);
        $this->historyTracker->recordMigrationCompletion($migrationId, 'social', false, 'Connection timeout');
        
        // Step 4: Verify completion status
        $updatedHistory = $this->historyTracker->getMigrationHistory($migrationId);
        $statusMap = [];
        foreach ($updatedHistory as $record) {
            $statusMap[$record['service_name']] = $record['status'];
        }
        
        $this->assertEquals('completed', $statusMap['auth']);
        $this->assertEquals('completed', $statusMap['pay']);
        $this->assertEquals('failed', $statusMap['social']);
        
        // Step 5: Check rollback candidates
        $rollbackCandidates = $this->rollbackService->getRollbackCandidates($migrationId);
        $this->assertCount(2, $rollbackCandidates); // Only completed migrations can be rolled back
        
        $candidateServices = array_column($rollbackCandidates, 'service_name');
        $this->assertContains('auth', $candidateServices);
        $this->assertContains('pay', $candidateServices);
        $this->assertNotContains('social', $candidateServices);
        
        // Step 6: Check if rollback is possible
        $canRollback = $this->rollbackService->canRollback($migrationId);
        $this->assertArrayHasKey('can_rollback', $canRollback);
        $this->assertArrayHasKey('candidates', $canRollback);
        $this->assertEquals(2, count($canRollback['candidates']));
        
        // Step 7: Perform rollback
        if ($canRollback['can_rollback']) {
            $rollbackResult = $this->rollbackService->rollbackMigration(
                $migrationId, 
                'Integration test rollback'
            );
            
            // Note: This will likely fail in test environment due to no actual services
            // but we can verify the structure
            $this->assertArrayHasKey('success', $rollbackResult);
            $this->assertArrayHasKey('rollback_id', $rollbackResult);
            $this->assertArrayHasKey('results', $rollbackResult);
        }
    }

    public function testServiceHealthIntegration(): void
    {
        // Check health of all services
        $healthResults = $this->healthChecker->checkAllServicesHealth();
        
        $this->assertArrayHasKey('services', $healthResults);
        $this->assertArrayHasKey('summary', $healthResults);
        
        $services = $healthResults['services'];
        $summary = $healthResults['summary'];
        
        // Verify structure
        $this->assertEquals(5, $summary['total_services']);
        $this->assertGreaterThan(0, $summary['healthy_services']); // At least multivendor should be healthy
        
        // Check individual service health
        $this->assertArrayHasKey('multivendor', $services);
        $multivendorHealth = $services['multivendor'];
        
        $this->assertEquals('healthy', $multivendorHealth['status']);
        $this->assertArrayHasKey('checks', $multivendorHealth);
        $this->assertArrayHasKey('response_time', $multivendorHealth);
        
        // Verify detailed health checks
        $checks = $multivendorHealth['checks'];
        $this->assertArrayHasKey('php', $checks);
        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('filesystem', $checks);
        $this->assertArrayHasKey('memory', $checks);
        
        // PHP should always be healthy
        $this->assertEquals('healthy', $checks['php']['status']);
    }

    public function testMigrationStatisticsGeneration(): void
    {
        // Create test data for statistics
        $migrations = [
            ['migration-stats-1', 'auth', true, null],
            ['migration-stats-2', 'auth', false, 'Error 1'],
            ['migration-stats-3', 'pay', true, null],
            ['migration-stats-4', 'pay', true, null],
            ['migration-stats-5', 'social', false, 'Error 2'],
        ];
        
        foreach ($migrations as [$migrationId, $service, $success, $error]) {
            $this->historyTracker->recordMigrationStart($migrationId, $service);
            $this->historyTracker->recordMigrationCompletion($migrationId, $service, $success, $error);
        }
        
        // Get statistics
        $statistics = $this->historyTracker->getMigrationStatistics();
        
        $this->assertIsArray($statistics);
        $this->assertGreaterThan(0, count($statistics));
        
        // Find auth service statistics
        $authStats = null;
        foreach ($statistics as $stat) {
            if ($stat['service_name'] === 'auth') {
                $authStats = $stat;
                break;
            }
        }
        
        $this->assertNotNull($authStats);
        $this->assertEquals(2, $authStats['total_migrations']);
        $this->assertEquals(1, $authStats['successful_migrations']);
        $this->assertEquals(1, $authStats['failed_migrations']);
        $this->assertEquals(50.0, $authStats['success_rate']);
        
        // Find pay service statistics
        $payStats = null;
        foreach ($statistics as $stat) {
            if ($stat['service_name'] === 'pay') {
                $payStats = $stat;
                break;
            }
        }
        
        $this->assertNotNull($payStats);
        $this->assertEquals(2, $payStats['total_migrations']);
        $this->assertEquals(2, $payStats['successful_migrations']);
        $this->assertEquals(0, $payStats['failed_migrations']);
        $this->assertEquals(100.0, $payStats['success_rate']);
    }

    public function testFailedMigrationTracking(): void
    {
        // Create some failed migrations
        $failedMigrations = [
            ['failed-integration-1', 'auth', 'Database connection failed'],
            ['failed-integration-2', 'pay', 'Timeout during schema update'],
            ['failed-integration-3', 'social', 'Permission denied'],
        ];
        
        foreach ($failedMigrations as [$migrationId, $service, $error]) {
            $this->historyTracker->recordMigrationStart($migrationId, $service);
            $this->historyTracker->recordMigrationCompletion($migrationId, $service, false, $error);
        }
        
        // Get failed migrations
        $failed = $this->historyTracker->getFailedMigrations(10);
        
        $this->assertCount(3, $failed);
        
        foreach ($failed as $migration) {
            $this->assertEquals('failed', $migration['status']);
            $this->assertNotNull($migration['error_message']);
            $this->assertContains($migration['migration_id'], ['failed-integration-1', 'failed-integration-2', 'failed-integration-3']);
        }
    }

    public function testPaginatedHistoryRetrieval(): void
    {
        // Create multiple migrations for pagination testing
        for ($i = 1; $i <= 25; $i++) {
            $migrationId = "pagination-test-{$i}";
            $service = ['auth', 'pay', 'social'][$i % 3];
            
            $this->historyTracker->recordMigrationStart($migrationId, $service);
            $this->historyTracker->recordMigrationCompletion($migrationId, $service, true);
        }
        
        // Test first page
        $page1 = $this->historyTracker->getAllMigrationHistory(1, 10);
        
        $this->assertArrayHasKey('data', $page1);
        $this->assertArrayHasKey('pagination', $page1);
        $this->assertCount(10, $page1['data']);
        $this->assertEquals(1, $page1['pagination']['current_page']);
        $this->assertEquals(10, $page1['pagination']['per_page']);
        $this->assertEquals(25, $page1['pagination']['total_count']);
        $this->assertEquals(3, $page1['pagination']['total_pages']);
        
        // Test second page
        $page2 = $this->historyTracker->getAllMigrationHistory(2, 10);
        
        $this->assertCount(10, $page2['data']);
        $this->assertEquals(2, $page2['pagination']['current_page']);
        
        // Test last page
        $page3 = $this->historyTracker->getAllMigrationHistory(3, 10);
        
        $this->assertCount(5, $page3['data']); // Remaining 5 records
        $this->assertEquals(3, $page3['pagination']['current_page']);
    }

    public function testServiceSpecificHistory(): void
    {
        $targetService = 'multivendor';
        
        // Create migrations for different services
        $migrations = [
            ['service-test-1', 'auth'],
            ['service-test-2', 'multivendor'],
            ['service-test-3', 'pay'],
            ['service-test-4', 'multivendor'],
            ['service-test-5', 'multivendor'],
        ];
        
        foreach ($migrations as [$migrationId, $service]) {
            $this->historyTracker->recordMigrationStart($migrationId, $service);
            $this->historyTracker->recordMigrationCompletion($migrationId, $service, true);
        }
        
        // Get history for specific service
        $serviceHistory = $this->historyTracker->getServiceMigrationHistory($targetService, 10);
        
        $this->assertCount(3, $serviceHistory); // Only multivendor migrations
        
        foreach ($serviceHistory as $record) {
            $this->assertEquals($targetService, $record['service_name']);
            $this->assertEquals('completed', $record['status']);
        }
    }

    public function testSystemInfoRetrieval(): void
    {
        $systemInfo = $this->healthChecker->getSystemInfo();
        
        $this->assertArrayHasKey('php', $systemInfo);
        $this->assertArrayHasKey('server', $systemInfo);
        $this->assertArrayHasKey('memory', $systemInfo);
        $this->assertArrayHasKey('extensions', $systemInfo);
        
        // Verify PHP info
        $phpInfo = $systemInfo['php'];
        $this->assertEquals(PHP_VERSION, $phpInfo['version']);
        $this->assertEquals(PHP_SAPI, $phpInfo['sapi']);
        
        // Verify required extensions
        $extensions = $systemInfo['extensions'];
        $this->assertTrue($extensions['pdo']);
        $this->assertTrue($extensions['json']);
        
        // Memory info should be present
        $memoryInfo = $systemInfo['memory'];
        $this->assertArrayHasKey('current_usage', $memoryInfo);
        $this->assertArrayHasKey('peak_usage', $memoryInfo);
        $this->assertArrayHasKey('limit', $memoryInfo);
    }

    public function testHealthCachePerformance(): void
    {
        // First call (no cache)
        $start1 = microtime(true);
        $health1 = $this->healthChecker->checkServiceHealth('multivendor', false);
        $time1 = microtime(true) - $start1;
        
        // Second call (with cache)
        $start2 = microtime(true);
        $health2 = $this->healthChecker->checkServiceHealth('multivendor', true);
        $time2 = microtime(true) - $start2;
        
        // Cached call should be faster
        $this->assertLessThan($time1, $time2);
        
        // Results should be similar (timestamps might differ slightly)
        $this->assertEquals($health1['service'], $health2['service']);
        $this->assertEquals($health1['status'], $health2['status']);
    }

    public function testLoggingIntegrationThroughoutWorkflow(): void
    {
        $migrationId = 'logging-integration-test';
        
        // Perform various operations that should generate logs
        $this->historyTracker->recordMigrationStart($migrationId, 'auth');
        $this->historyTracker->recordMigrationCompletion($migrationId, 'auth', true);
        $this->healthChecker->checkServiceHealth('multivendor');
        $this->rollbackService->getRollbackCandidates($migrationId);
        
        // Verify log files were created
        $logFile = $this->testLogPath . '/multivendor-' . date('Y-m-d') . '.log';
        $this->assertTrue(file_exists($logFile));
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContains('Migration start recorded', $logContent);
        $this->assertStringContains('Migration completion recorded', $logContent);
    }

    public function testErrorHandlingInIntegration(): void
    {
        // Test with invalid migration ID
        $history = $this->historyTracker->getMigrationHistory('non-existent-migration');
        $this->assertEmpty($history);
        
        // Test rollback of non-existent migration
        $rollbackResult = $this->rollbackService->rollbackMigration('non-existent', 'test');
        $this->assertFalse($rollbackResult['success']);
        $this->assertStringContains('not found', $rollbackResult['error']);
        
        // Test health check of unknown service
        $health = $this->healthChecker->checkServiceHealth('unknown-service');
        $this->assertEquals('unknown', $health['status']);
    }
}