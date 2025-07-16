<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create vendors table migration
 */
class CreateVendorsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE vendors (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            business_name VARCHAR(255) NOT NULL,
            business_type ENUM('dairy', 'vegetables', 'mixed') NOT NULL,
            fssai_license VARCHAR(50) UNIQUE NOT NULL,
            owner_name VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(255) NOT NULL,
            address TEXT NOT NULL,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            cold_chain_capable BOOLEAN DEFAULT FALSE,
            status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_business_type (business_type),
            INDEX idx_status (status),
            INDEX idx_location (latitude, longitude),
            INDEX idx_fssai (fssai_license)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS vendors");
    }
}