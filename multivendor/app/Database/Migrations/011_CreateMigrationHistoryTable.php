<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create migration_history table for tracking cross-service migrations
 */
class CreateMigrationHistoryTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE migration_history (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            service_name ENUM('auth', 'pay', 'social', 'delivery', 'multivendor') NOT NULL,
            migration_name VARCHAR(255) NOT NULL,
            status ENUM('pending', 'running', 'completed', 'failed', 'rolled_back') DEFAULT 'pending',
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            error_message TEXT,
            executed_by VARCHAR(255),
            rollback_available BOOLEAN DEFAULT TRUE,
            execution_time_seconds INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_service_name (service_name),
            INDEX idx_status (status),
            INDEX idx_migration_name (migration_name),
            INDEX idx_started_at (started_at),
            UNIQUE KEY unique_service_migration (service_name, migration_name)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS migration_history");
    }
}