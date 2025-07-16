<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create admin_sessions table for admin panel authentication
 */
class CreateAdminSessionsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE admin_sessions (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            session_id VARCHAR(128) UNIQUE NOT NULL,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_session_id (session_id),
            INDEX idx_username (username),
            INDEX idx_expires_at (expires_at),
            INDEX idx_is_active (is_active)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS admin_sessions");
    }
}