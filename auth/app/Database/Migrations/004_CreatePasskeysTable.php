<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create passkeys table migration
 */
class CreatePasskeysTable extends Migration
{
    public function getName(): string
    {
        return '004_CreatePasskeysTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS passkeys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                credential_id VARCHAR(255) NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                device_name VARCHAR(255) NOT NULL,
                sign_count INT NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                last_used TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_credential_id (credential_id),
                INDEX idx_is_active (is_active),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS passkeys");
    }
}