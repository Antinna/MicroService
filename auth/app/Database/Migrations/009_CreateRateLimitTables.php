<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create rate limiting tables migration
 */
class CreateRateLimitTables extends Migration
{
    public function getName(): string
    {
        return '009_CreateRateLimitTables';
    }

    public function up(): void
    {
        // Create rate_limit_requests table
        $sql1 = "
            CREATE TABLE IF NOT EXISTS rate_limit_requests (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                limit_type ENUM('ip', 'user', 'endpoint', 'global') NOT NULL,
                action VARCHAR(100) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_identifier_type_action (identifier, limit_type, action),
                INDEX idx_created_at (created_at),
                INDEX idx_cleanup (created_at, limit_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        // Create rate_limit_whitelist table
        $sql2 = "
            CREATE TABLE IF NOT EXISTS rate_limit_whitelist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                limit_type ENUM('ip', 'user', 'endpoint', 'global') NOT NULL,
                reason TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_identifier_type (identifier, limit_type),
                INDEX idx_identifier (identifier),
                INDEX idx_limit_type (limit_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        // Create rate_limit_bans table
        $sql3 = "
            CREATE TABLE IF NOT EXISTS rate_limit_bans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                limit_type ENUM('ip', 'user', 'endpoint', 'global') NOT NULL,
                reason TEXT NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_identifier_type (identifier, limit_type),
                INDEX idx_identifier (identifier),
                INDEX idx_limit_type (limit_type),
                INDEX idx_expires_at (expires_at),
                INDEX idx_active_bans (expires_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql1);
        $this->execute($sql2);
        $this->execute($sql3);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS rate_limit_requests");
        $this->execute("DROP TABLE IF EXISTS rate_limit_whitelist");
        $this->execute("DROP TABLE IF EXISTS rate_limit_bans");
    }

    public function getDescription(): string
    {
        return 'Create rate limiting tables for request tracking, whitelist, and bans';
    }
}