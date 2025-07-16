<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\MigrationHistoryTracker;
use Antinna\Multivendor\Services\Logger;

class MigrationHistoryTrackerTest extends TestCase
{
    private MigrationHistoryTracker $tracker;
    private Logger $logger;
    private \PDO $testDb;

    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->testDb = new \PDO('sqlite::memory:');
        $this->testDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        $this->logger = $this->createMock(Logger::class);
        
        // Mock the database connection
        $mockConnection = $this->createMock(\Antinna\Multivendor\Database\Connection::class);
        $mockConnection->method('getConnection')->willReturn($this->testDb);
        
        $this->tracker = new MigrationHistoryTracker($this->logger);
        
        // Use reflection to replace the database connection
        $reflection = new \ReflectionClass($this->tracker);
        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($this->tracker, $mockConnection);
        
        // Initialize the test table
        $this->initializeTestTable();
    }

    private function initializeTestTable(): void
    {
        $sql = "CREATE TABLE migration_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_id VARCHAR(255) NOT NULL,
            service_name VARCHAR(100) NOT NULL,
            migration_name VARCHAR(255),
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            execution_time_seconds INTEGER DEFAULT 0,
            error_message TEXT NULL,
            rollback_reason TEXT NULL,
            rollback_at TIMESTAMP NULL,
            metadata TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $this->testDb->exec($sql);
    }

    public function testRecordMigrationStart(): void
    {
        $migrationId = 'test-migration-123';
        $serviceName = 'auth';
        $migrationName = 'CreateUsersTable';
        $metadata = ['version' => '1.0', 'author' => 'test'];

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Migration start recorded');

        $result = $this->tracker->recordMigrationStart($migrationId, $serviceName, $migrationName, $metadata);
        
        $this->assertTrue($result);
        
        // Verify record was created
        $stmt = $this->testDb->prepare("SELECT * FROM migration_history WHERE migration_id = ? AND service_name = ?");
        $stmt->execute([$migrationId, $serviceName]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($record);
        $this->assertEquals($migrationId, $record['migration_id']);
        $this->assertEquals($serviceName, $record['service_name']);
        $this->assertEquals($migrationName, $record['migration_name']);
        $this->assertEquals('running', $record['status']);
        $this->assertNotNull($record['started_at']);
        $this->assertEquals(json_encode($metadata), $record['metadata']);
    }

    public function testRecordMigrationCompletion(): void
    {
        $migrationId = 'test-migration-456';
        $serviceName = 'pay';
        
        // First record the start
        $this->tracker->recordMigrationStart($migrationId, $serviceName);
        
        $this->logger->expects($this->exactly(2))
            ->method('info');

        // Then record completion
        $result = $this->tracker->recordMigrationCompletion($migrationId, $serviceName, true);
        
        $this->assertTrue($result);
        
        // Verify record was updated
        $stmt = $this->testDb->prepare("SELECT * FROM migration_history WHERE migration_id = ? AND service_name = ?");
        $stmt->execute([$migrationId, $serviceName]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertEquals('completed', $record['status']);
        $this->assertNotNull($record['completed_at']);
        $this->assertGreaterThan(0, $record['execution_time_seconds']);
        $this->assertNull($record['error_message']);
    }

    public function testRecordMigrationFailure(): void
    {
        $migrationId = 'test-migration-789';
        $serviceName = 'social';
        $errorMessage = 'Database connection failed';
        
        // First record the start
        $this->tracker->recordMigrationStart($migrationId, $serviceName);
        
        $this->logger->expects($this->exactly(2))
            ->method('info');

        // Then record failure
        $result = $this->tracker->recordMigrationCompletion($migrationId, $serviceName, false, $errorMessage);
        
        $this->assertTrue($result);
        
        // Verify record was updated
        $stmt = $this->testDb->prepare("SELECT * FROM migration_history WHERE migration_id = ? AND service_name = ?");
        $stmt->execute([$migrationId, $serviceName]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertEquals('failed', $record['status']);
        $this->assertEquals($errorMessage, $record['error_message']);
    }

    public function testRecordMigrationRollback(): void
    {
        $migrationId = 'test-migration-rollback';
        $serviceName = 'delivery';
        $reason = 'Data corruption detected';
        
        // First record a completed migration
        $this->tracker->recordMigrationStart($migrationId, $serviceName);
        $this->tracker->recordMigrationCompletion($migrationId, $serviceName, true);
        
        $this->logger->expects($this->exactly(3))
            ->method('info');

        // Then record rollback
        $result = $this->tracker->recordMigrationRollback($migrationId, $serviceName, $reason);
        
        $this->assertTrue($result);
        
        // Verify record was updated
        $stmt = $this->testDb->prepare("SELECT * FROM migration_history WHERE migration_id = ? AND service_name = ?");
        $stmt->execute([$migrationId, $serviceName]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertEquals('rolled_back', $record['status']);
        $this->assertEquals($reason, $record['rollback_reason']);
        $this->assertNotNull($record['rollback_at']);
    }

    public function testGetMigrationHistory(): void
    {
        $migrationId = 'test-migration-history';
        
        // Create multiple records for the same migration
        $this->tracker->recordMigrationStart($migrationId, 'auth', 'Migration1');
        $this->tracker->recordMigrationStart($migrationId, 'pay', 'Migration2');
        $this->tracker->recordMigrationCompletion($migrationId, 'auth', true);
        
        $this->logger->expects($this->exactly(3))
            ->method('info');

        $history = $this->tracker->getMigrationHistory($migrationId);
        
        $this->assertCount(2, $history);
        $this->assertEquals($migrationId, $history[0]['migration_id']);
        $this->assertEquals($migrationId, $history[1]['migration_id']);
        
        // Check that services are different
        $services = array_column($history, 'service_name');
        $this->assertContains('auth', $services);
        $this->assertContains('pay', $services);
    }

    public function testGetServiceMigrationHistory(): void
    {
        $serviceName = 'multivendor';
        
        // Create multiple migrations for the same service
        $this->tracker->recordMigrationStart('migration-1', $serviceName, 'CreateTable1');
        $this->tracker->recordMigrationStart('migration-2', $serviceName, 'CreateTable2');
        $this->tracker->recordMigrationStart('migration-3', $serviceName, 'CreateTable3');
        
        $this->logger->expects($this->exactly(3))
            ->method('info');

        $history = $this->tracker->getServiceMigrationHistory($serviceName, 2);
        
        $this->assertCount(2, $history); // Limited to 2
        
        foreach ($history as $record) {
            $this->assertEquals($serviceName, $record['service_name']);
        }
    }

    public function testGetAllMigrationHistory(): void
    {
        // Create multiple migrations
        $this->tracker->recordMigrationStart('migration-1', 'auth');
        $this->tracker->recordMigrationStart('migration-2', 'pay');
        $this->tracker->recordMigrationStart('migration-3', 'social');
        
        $this->logger->expects($this->exactly(3))
            ->method('info');

        $result = $this->tracker->getAllMigrationHistory(1, 2);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertCount(2, $result['data']); // Limited to 2 per page
        $this->assertEquals(1, $result['pagination']['current_page']);
        $this->assertEquals(2, $result['pagination']['per_page']);
        $this->assertEquals(3, $result['pagination']['total_count']);
        $this->assertEquals(2, $result['pagination']['total_pages']);
    }

    public function testGetMigrationStatistics(): void
    {
        // Create test data with different statuses
        $this->tracker->recordMigrationStart('migration-1', 'auth');
        $this->tracker->recordMigrationCompletion('migration-1', 'auth', true);
        
        $this->tracker->recordMigrationStart('migration-2', 'auth');
        $this->tracker->recordMigrationCompletion('migration-2', 'auth', false, 'Error');
        
        $this->tracker->recordMigrationStart('migration-3', 'pay');
        $this->tracker->recordMigrationCompletion('migration-3', 'pay', true);
        
        $this->logger->expects($this->exactly(6))
            ->method('info');

        $statistics = $this->tracker->getMigrationStatistics();
        
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
    }

    public function testGetFailedMigrations(): void
    {
        // Create some failed migrations
        $this->tracker->recordMigrationStart('failed-1', 'auth');
        $this->tracker->recordMigrationCompletion('failed-1', 'auth', false, 'Connection error');
        
        $this->tracker->recordMigrationStart('failed-2', 'pay');
        $this->tracker->recordMigrationCompletion('failed-2', 'pay', false, 'Timeout error');
        
        $this->tracker->recordMigrationStart('success-1', 'social');
        $this->tracker->recordMigrationCompletion('success-1', 'social', true);
        
        $this->logger->expects($this->exactly(6))
            ->method('info');

        $failedMigrations = $this->tracker->getFailedMigrations(5);
        
        $this->assertCount(2, $failedMigrations);
        
        foreach ($failedMigrations as $migration) {
            $this->assertEquals('failed', $migration['status']);
            $this->assertNotNull($migration['error_message']);
        }
    }

    public function testMigrationExists(): void
    {
        $migrationId = 'test-exists';
        $serviceName = 'auth';
        
        // Initially should not exist
        $exists = $this->tracker->migrationExists($migrationId, $serviceName);
        $this->assertFalse($exists);
        
        // Create migration
        $this->tracker->recordMigrationStart($migrationId, $serviceName);
        
        $this->logger->expects($this->once())
            ->method('info');

        // Now should exist
        $exists = $this->tracker->migrationExists($migrationId, $serviceName);
        $this->assertTrue($exists);
    }

    public function testGetMigrationStatus(): void
    {
        $migrationId = 'test-status';
        $serviceName = 'pay';
        
        // Initially should return null
        $status = $this->tracker->getMigrationStatus($migrationId, $serviceName);
        $this->assertNull($status);
        
        // Create migration
        $this->tracker->recordMigrationStart($migrationId, $serviceName);
        
        $this->logger->expects($this->once())
            ->method('info');

        // Should return 'running'
        $status = $this->tracker->getMigrationStatus($migrationId, $serviceName);
        $this->assertEquals('running', $status);
        
        // Complete migration
        $this->tracker->recordMigrationCompletion($migrationId, $serviceName, true);
        
        $this->logger->expects($this->exactly(2))
            ->method('info');

        // Should return 'completed'
        $status = $this->tracker->getMigrationStatus($migrationId, $serviceName);
        $this->assertEquals('completed', $status);
    }

    public function testCleanupOldHistory(): void
    {
        // Create old migration (simulate by directly inserting with old date)
        $oldDate = date('Y-m-d H:i:s', strtotime('-100 days'));
        $recentDate = date('Y-m-d H:i:s', strtotime('-10 days'));
        
        $this->testDb->exec("INSERT INTO migration_history 
            (migration_id, service_name, status, created_at) VALUES 
            ('old-migration', 'auth', 'completed', '{$oldDate}'),
            ('recent-migration', 'pay', 'completed', '{$recentDate}')");
        
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Migration history cleanup completed');

        $deletedCount = $this->tracker->cleanupOldHistory(90);
        
        $this->assertEquals(1, $deletedCount);
        
        // Verify only old migration was deleted
        $stmt = $this->testDb->query("SELECT COUNT(*) as count FROM migration_history");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(1, $result['count']);
        
        // Verify the remaining migration is the recent one
        $stmt = $this->testDb->query("SELECT migration_id FROM migration_history");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('recent-migration', $result['migration_id']);
    }

    public function testMetadataHandling(): void
    {
        $migrationId = 'test-metadata';
        $serviceName = 'multivendor';
        $metadata = [
            'version' => '2.0',
            'author' => 'test-user',
            'tags' => ['critical', 'schema-change'],
            'estimated_time' => 300
        ];

        $this->logger->expects($this->once())
            ->method('info');

        $this->tracker->recordMigrationStart($migrationId, $serviceName, 'TestMigration', $metadata);
        
        $history = $this->tracker->getMigrationHistory($migrationId);
        
        $this->assertCount(1, $history);
        $this->assertEquals($metadata, $history[0]['metadata']);
    }
}