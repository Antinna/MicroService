<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create traceability_logs table migration
 */
class CreateTraceabilityLogsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE traceability_logs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            batch_id BIGINT NOT NULL,
            order_id BIGINT,
            customer_id BIGINT,
            event_type ENUM('production', 'quality_check', 'storage', 'dispatch', 'delivery', 'return', 'recall') NOT NULL,
            event_description TEXT,
            location VARCHAR(255),
            temperature DECIMAL(5, 2),
            humidity DECIMAL(5, 2),
            handled_by VARCHAR(255),
            notes TEXT,
            metadata JSON,
            event_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE CASCADE,
            INDEX idx_batch_id (batch_id),
            INDEX idx_order_id (order_id),
            INDEX idx_customer_id (customer_id),
            INDEX idx_event_type (event_type),
            INDEX idx_event_timestamp (event_timestamp)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS traceability_logs");
    }
}