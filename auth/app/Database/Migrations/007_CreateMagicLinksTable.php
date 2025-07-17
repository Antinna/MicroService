<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

class CreateMagicLinksTable extends Migration
{
    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS magic_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                metadata JSON NULL,
                
                INDEX idx_magic_links_user_id (user_id),
                INDEX idx_magic_links_token_hash (token_hash),
                INDEX idx_magic_links_expires_at (expires_at),
                INDEX idx_magic_links_created_at (created_at),
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS magic_links";
        $this->execute($sql);
    }

    public function getName(): string
    {
        return '007_CreateMagicLinksTable';
    }

    public function getDescription(): string
    {
        return 'Create magic_links table for passwordless authentication';
    }
}