<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create inventory_batches table migration
 */
class CreateInventoryBatchesTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE inventory_batches (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            product_id BIGINT NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            production_date DATE NOT NULL,
            expiry_date DATE NOT NULL,
            quantity_available INT NOT NULL,
            quantity_reserved INT DEFAULT 0,
            farm_source VARCHAR(255),
            quality_grade ENUM('A', 'B', 'C') DEFAULT 'A',
            storage_temperature_min DECIMAL(5, 2),
            storage_temperature_max DECIMAL(5, 2),
            notes TEXT,
            is_recalled BOOLEAN DEFAULT FALSE,
            recall_reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_product_id (product_id),
            INDEX idx_batch_number (batch_number),
            INDEX idx_expiry_date (expiry_date),
            INDEX idx_production_date (production_date),
            INDEX idx_quality_grade (quality_grade),
            INDEX idx_is_recalled (is_recalled),
            UNIQUE KEY unique_batch (product_id, batch_number)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS inventory_batches");
    }
}