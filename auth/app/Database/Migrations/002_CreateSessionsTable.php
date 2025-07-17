<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create sessions table migration
 */
class CreateSessionsTable extends Migration
{
    public function getName(): string
    {
        return '002_CreateSessionsTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(255) PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                device_info TEXT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT NULL,
                session_type ENUM('web', 'mobile', 'api') NOT NULL DEFAULT 'web',
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                last_activity TIMESTAMP NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_token_hash (token_hash),
                INDEX idx_is_active (is_active),
                INDEX idx_expires_at (expires_at),
                INDEX idx_created_at (created_at),
                INDEX idx_session_type (session_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS sessions");
    }
}