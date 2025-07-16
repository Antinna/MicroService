<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create customer_subscriptions table migration
 */
class CreateCustomerSubscriptionsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE customer_subscriptions (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            customer_id BIGINT NOT NULL,
            vendor_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            quantity INT NOT NULL,
            delivery_days JSON NOT NULL COMMENT 'Array of days: [\"monday\", \"wednesday\", \"friday\"]',
            preferred_time_slot VARCHAR(20) NOT NULL COMMENT 'Format: \"06:00-08:00\"',
            delivery_address TEXT NOT NULL,
            delivery_latitude DECIMAL(10, 8),
            delivery_longitude DECIMAL(11, 8),
            special_instructions TEXT,
            status ENUM('active', 'paused', 'cancelled') DEFAULT 'active',
            pause_start_date DATE NULL,
            pause_end_date DATE NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            next_delivery_date DATE,
            total_orders_generated INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_customer_id (customer_id),
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_product_id (product_id),
            INDEX idx_status (status),
            INDEX idx_next_delivery_date (next_delivery_date),
            INDEX idx_delivery_location (delivery_latitude, delivery_longitude)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS customer_subscriptions");
    }
}