<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create token_blacklist table migration
 */
class CreateTokenBlacklistTable extends Migration
{
    public function getName(): string
    {
        return '006_CreateTokenBlacklistTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS token_blacklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token_hash VARCHAR(255) NOT NULL UNIQUE,
                user_id INT NULL,
                reason VARCHAR(255) NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_token_hash (token_hash),
                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS token_blacklist");
    }
}