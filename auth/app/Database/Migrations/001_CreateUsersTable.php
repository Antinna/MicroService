<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create users table migration
 */
class CreateUsersTable extends Migration
{
    public function getName(): string
    {
        return '001_CreateUsersTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                phone VARCHAR(20) NULL,
                password_hash VARCHAR(255) NULL,
                role ENUM('admin', 'vendor', 'customer', 'guest', 'delivery_partner', 'management_staff') NOT NULL DEFAULT 'customer',
                permissions JSON NULL,
                email_verified BOOLEAN NOT NULL DEFAULT FALSE,
                phone_verified BOOLEAN NOT NULL DEFAULT FALSE,
                mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                mfa_secret VARCHAR(255) NULL,
                backup_codes JSON NULL,
                security_settings JSON NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                last_login TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_email (email),
                INDEX idx_phone (phone),
                INDEX idx_role (role),
                INDEX idx_is_active (is_active),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS users");
    }
}