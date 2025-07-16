<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create vendor_analytics table migration
 */
class CreateVendorAnalyticsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE vendor_analytics (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            date DATE NOT NULL,
            total_orders INT DEFAULT 0,
            total_revenue DECIMAL(12, 2) DEFAULT 0.00,
            total_items_sold INT DEFAULT 0,
            average_order_value DECIMAL(10, 2) DEFAULT 0.00,
            successful_deliveries INT DEFAULT 0,
            failed_deliveries INT DEFAULT 0,
            customer_ratings_avg DECIMAL(3, 2) DEFAULT 0.00,
            customer_ratings_count INT DEFAULT 0,
            stock_alerts_sent INT DEFAULT 0,
            products_expired INT DEFAULT 0,
            subscription_orders INT DEFAULT 0,
            on_demand_orders INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_date (date),
            INDEX idx_vendor_date (vendor_id, date),
            UNIQUE KEY unique_vendor_date (vendor_id, date)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS vendor_analytics");
    }
}