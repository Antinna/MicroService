<?php

namespace Antinna\Multivendor\Services;

use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Database\Connection;

class MigrationHistoryTracker
{
    private Logger $logger;
    private Connection $db;

    public function __construct(Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->db = new Connection();
        $this->initializeHistoryTable();
    }

    /**
     * Initialize migration history table
     */
    private function initializeHistoryTable(): void
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS migration_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_id VARCHAR(255) NOT NULL UNIQUE,
                service_name VARCHAR(100) NOT NULL,
                migration_name VARCHAR(255),
                status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                execution_time_seconds INT DEFAULT 0,
                error_message TEXT NULL,
                rollback_reason TEXT NULL,
                rollback_at TIMESTAMP NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_migration_id (migration_id),
                INDEX idx_service_name (service_name),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->db->getConnection()->exec($sql);

        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize migration history table', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Record migration start
     */
    public function recordMigrationStart(string $migrationId, string $serviceName, ?string $migrationName = null, array $metadata = []): bool
    {
        try {
            $sql = "INSERT INTO migration_history 
                    (migration_id, service_name, migration_name, status, started_at, metadata) 
                    VALUES (?, ?, ?, 'running', NOW(), ?)
                    ON DUPLICATE KEY UPDATE 
                    status = 'running', 
                    started_at = NOW(), 
                    metadata = VALUES(metadata),
                    updated_at = NOW()";

            $stmt = $this->db->getConnection()->prepare($sql);
            $result = $stmt->execute([
                $migrationId,
                $serviceName,
                $migrationName,
                json_encode($metadata)
            ]);

            $this->logger->info('Migration start recorded', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'migration_name' => $migrationName
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to record migration start', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Record migration completion
     */
    public function recordMigrationCompletion(string $migrationId, string $serviceName, bool $success = true, ?string $errorMessage = null): bool
    {
        try {
            $status = $success ? 'completed' : 'failed';
            
            $sql = "UPDATE migration_history 
                    SET status = ?, 
                        completed_at = NOW(), 
                        execution_time_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                        error_message = ?,
                        updated_at = NOW()
                    WHERE migration_id = ? AND service_name = ?";

            $stmt = $this->db->getConnection()->prepare($sql);
            $result = $stmt->execute([
                $status,
                $errorMessage,
                $migrationId,
                $serviceName
            ]);

            $this->logger->info('Migration completion recorded', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'status' => $status,
                'error_message' => $errorMessage
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to record migration completion', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Record migration rollback
     */
    public function recordMigrationRollback(string $migrationId, string $serviceName, string $reason): bool
    {
        try {
            $sql = "UPDATE migration_history 
                    SET status = 'rolled_back', 
                        rollback_reason = ?,
                        rollback_at = NOW(),
                        updated_at = NOW()
                    WHERE migration_id = ? AND service_name = ?";

            $stmt = $this->db->getConnection()->prepare($sql);
            $result = $stmt->execute([
                $reason,
                $migrationId,
                $serviceName
            ]);

            $this->logger->info('Migration rollback recorded', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'reason' => $reason
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to record migration rollback', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get migration history for a specific migration ID
     */
    public function getMigrationHistory(string $migrationId): array
    {
        try {
            $sql = "SELECT * FROM migration_history 
                    WHERE migration_id = ? 
                    ORDER BY created_at ASC";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$migrationId]);
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Decode JSON metadata
            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get migration history', [
                'migration_id' => $migrationId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get migration history for a specific service
     */
    public function getServiceMigrationHistory(string $serviceName, int $limit = 50): array
    {
        try {
            $sql = "SELECT * FROM migration_history 
                    WHERE service_name = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$serviceName, $limit]);
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Decode JSON metadata
            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get service migration history', [
                'service_name' => $serviceName,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get all migration history with pagination
     */
    public function getAllMigrationHistory(int $page = 1, int $perPage = 20): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM migration_history";
            $countStmt = $this->db->getConnection()->prepare($countSql);
            $countStmt->execute();
            $totalCount = $countStmt->fetch(\PDO::FETCH_ASSOC)['total'];
            
            // Get paginated results
            $sql = "SELECT * FROM migration_history 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$perPage, $offset]);
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Decode JSON metadata
            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return [
                'data' => $results,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_count' => (int)$totalCount,
                    'total_pages' => ceil($totalCount / $perPage)
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get all migration history', [
                'error' => $e->getMessage()
            ]);
            return [
                'data' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_count' => 0,
                    'total_pages' => 0
                ]
            ];
        }
    }

    /**
     * Get migration statistics
     */
    public function getMigrationStatistics(): array
    {
        try {
            $sql = "SELECT 
                        service_name,
                        status,
                        COUNT(*) as count,
                        AVG(execution_time_seconds) as avg_execution_time,
                        MAX(execution_time_seconds) as max_execution_time,
                        MIN(execution_time_seconds) as min_execution_time
                    FROM migration_history 
                    WHERE status IN ('completed', 'failed')
                    GROUP BY service_name, status";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute();
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Organize results by service
            $statistics = [];
            foreach ($results as $result) {
                $serviceName = $result['service_name'];
                if (!isset($statistics[$serviceName])) {
                    $statistics[$serviceName] = [
                        'service_name' => $serviceName,
                        'total_migrations' => 0,
                        'successful_migrations' => 0,
                        'failed_migrations' => 0,
                        'success_rate' => 0,
                        'avg_execution_time' => 0,
                        'max_execution_time' => 0,
                        'min_execution_time' => 0
                    ];
                }
                
                $statistics[$serviceName]['total_migrations'] += $result['count'];
                
                if ($result['status'] === 'completed') {
                    $statistics[$serviceName]['successful_migrations'] = $result['count'];
                    $statistics[$serviceName]['avg_execution_time'] = round($result['avg_execution_time'], 2);
                    $statistics[$serviceName]['max_execution_time'] = $result['max_execution_time'];
                    $statistics[$serviceName]['min_execution_time'] = $result['min_execution_time'];
                } else {
                    $statistics[$serviceName]['failed_migrations'] = $result['count'];
                }
            }
            
            // Calculate success rates
            foreach ($statistics as &$stat) {
                if ($stat['total_migrations'] > 0) {
                    $stat['success_rate'] = round(($stat['successful_migrations'] / $stat['total_migrations']) * 100, 2);
                }
            }

            return array_values($statistics);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get migration statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get failed migrations that need attention
     */
    public function getFailedMigrations(int $limit = 10): array
    {
        try {
            $sql = "SELECT * FROM migration_history 
                    WHERE status = 'failed' 
                    ORDER BY created_at DESC 
                    LIMIT ?";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$limit]);
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Decode JSON metadata
            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get failed migrations', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Clean up old migration history
     */
    public function cleanupOldHistory(int $daysToKeep = 90): int
    {
        try {
            $sql = "DELETE FROM migration_history 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status IN ('completed', 'failed', 'rolled_back')";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$daysToKeep]);
            
            $deletedCount = $stmt->rowCount();
            
            $this->logger->info('Migration history cleanup completed', [
                'days_to_keep' => $daysToKeep,
                'deleted_records' => $deletedCount
            ]);

            return $deletedCount;

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup migration history', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Check if migration exists in history
     */
    public function migrationExists(string $migrationId, string $serviceName): bool
    {
        try {
            $sql = "SELECT COUNT(*) as count FROM migration_history 
                    WHERE migration_id = ? AND service_name = ?";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$migrationId, $serviceName]);
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result['count'] > 0;

        } catch (\Exception $e) {
            $this->logger->error('Failed to check migration existence', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get migration status
     */
    public function getMigrationStatus(string $migrationId, string $serviceName): ?string
    {
        try {
            $sql = "SELECT status FROM migration_history 
                    WHERE migration_id = ? AND service_name = ? 
                    ORDER BY created_at DESC 
                    LIMIT 1";

            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$migrationId, $serviceName]);
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ? $result['status'] : null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get migration status', [
                'migration_id' => $migrationId,
                'service_name' => $serviceName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}