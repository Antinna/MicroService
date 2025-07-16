<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create products table migration
 */
class CreateProductsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE products (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            category ENUM('dairy', 'vegetables', 'fruits') NOT NULL,
            packaging_type ENUM('loose', 'bottle', 'sealed', 'bag') NOT NULL,
            is_organic BOOLEAN DEFAULT FALSE,
            farm_origin VARCHAR(255),
            shelf_life_hours INT NOT NULL,
            price_per_unit DECIMAL(10, 2) NOT NULL,
            unit_type ENUM('kg', 'liter', 'piece', 'gram') NOT NULL,
            minimum_order_quantity INT DEFAULT 1,
            maximum_order_quantity INT,
            requires_cold_chain BOOLEAN DEFAULT FALSE,
            status ENUM('active', 'inactive', 'out_of_stock') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_category (category),
            INDEX idx_status (status),
            INDEX idx_is_organic (is_organic),
            INDEX idx_requires_cold_chain (requires_cold_chain),
            INDEX idx_price (price_per_unit)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS products");
    }
}