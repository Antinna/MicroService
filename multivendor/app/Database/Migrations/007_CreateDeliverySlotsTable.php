<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create delivery_slots table migration
 */
class CreateDeliverySlotsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE delivery_slots (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            slot_name VARCHAR(100) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            max_capacity INT NOT NULL,
            current_bookings INT DEFAULT 0,
            freshness_window_hours INT DEFAULT 24 COMMENT 'Hours within which delivery must happen for freshness',
            requires_cold_chain BOOLEAN DEFAULT FALSE,
            delivery_fee DECIMAL(8, 2) DEFAULT 0.00,
            is_active BOOLEAN DEFAULT TRUE,
            days_of_week JSON NOT NULL COMMENT 'Array of days: [\"monday\", \"tuesday\"]',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_time_range (start_time, end_time),
            INDEX idx_is_active (is_active),
            INDEX idx_requires_cold_chain (requires_cold_chain)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS delivery_slots");
    }
}