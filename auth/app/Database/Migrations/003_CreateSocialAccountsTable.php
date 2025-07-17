<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create social_accounts table migration
 */
class CreateSocialAccountsTable extends Migration
{
    public function getName(): string
    {
        return '003_CreateSocialAccountsTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS social_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                provider ENUM('google', 'facebook', 'apple', 'github', 'amazon', 'twitter', 'discord', 'microsoft') NOT NULL,
                provider_id VARCHAR(255) NOT NULL,
                provider_email VARCHAR(255) NULL,
                provider_data JSON NULL,
                linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_provider_account (provider, provider_id),
                INDEX idx_user_id (user_id),
                INDEX idx_provider (provider),
                INDEX idx_provider_id (provider_id),
                INDEX idx_provider_email (provider_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS social_accounts");
    }
}