<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create audit_logs table migration
 */
class CreateAuditLogsTable extends Migration
{
    public function getName(): string
    {
        return '005_CreateAuditLogsTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                event_type VARCHAR(100) NOT NULL,
                event_category VARCHAR(50) NOT NULL DEFAULT 'system',
                event_description TEXT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT NULL,
                metadata JSON NULL,
                severity ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') NOT NULL DEFAULT 'info',
                session_id VARCHAR(128) NULL,
                request_id VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_event_type (event_type),
                INDEX idx_event_category (event_category),
                INDEX idx_severity (severity),
                INDEX idx_created_at (created_at),
                INDEX idx_ip_address (ip_address),
                INDEX idx_session_id (session_id),
                INDEX idx_request_id (request_id),
                INDEX idx_composite_search (event_type, severity, created_at),
                INDEX idx_security_events (event_category, severity, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS audit_logs");
    }
}