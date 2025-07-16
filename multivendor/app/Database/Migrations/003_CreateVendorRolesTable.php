<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create vendor_roles table migration
 */
class CreateVendorRolesTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE vendor_roles (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            role ENUM('owner', 'manager', 'delivery_staff') NOT NULL,
            permissions JSON,
            is_active BOOLEAN DEFAULT TRUE,
            assigned_by BIGINT,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_user_id (user_id),
            INDEX idx_role (role),
            INDEX idx_is_active (is_active),
            UNIQUE KEY unique_vendor_user_role (vendor_id, user_id, role)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS vendor_roles");
    }
}