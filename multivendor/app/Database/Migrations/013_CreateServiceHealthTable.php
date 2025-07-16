<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create service_health table for monitoring microservice availability
 */
class CreateServiceHealthTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE service_health (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            service_name ENUM('auth', 'pay', 'social', 'delivery', 'multivendor') NOT NULL,
            service_url VARCHAR(255) NOT NULL,
            status ENUM('healthy', 'unhealthy', 'unknown') DEFAULT 'unknown',
            response_time_ms INT,
            last_check_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            error_message TEXT,
            consecutive_failures INT DEFAULT 0,
            last_success_at TIMESTAMP NULL,
            metadata JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_service_name (service_name),
            INDEX idx_status (status),
            INDEX idx_last_check_at (last_check_at),
            UNIQUE KEY unique_service (service_name)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS service_health");
    }
}