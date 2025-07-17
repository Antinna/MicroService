<?php

namespace Antinna\Auth\Database\Migrations;

use Antinna\Auth\Database\Migration;

/**
 * Create security_alerts table migration
 */
class CreateSecurityAlertsTable extends Migration
{
    public function getName(): string
    {
        return '008_CreateSecurityAlertsTable';
    }

    public function up(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS security_alerts (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                alert_id VARCHAR(64) NOT NULL UNIQUE,
                alert_type VARCHAR(100) NOT NULL,
                severity ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') NOT NULL DEFAULT 'warning',
                threat_level ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                alert_data JSON NULL,
                status ENUM('active', 'acknowledged', 'resolved', 'false_positive') NOT NULL DEFAULT 'active',
                acknowledged_by INT NULL,
                acknowledged_at TIMESTAMP NULL,
                resolved_by INT NULL,
                resolved_at TIMESTAMP NULL,
                resolution_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_alert_type (alert_type),
                INDEX idx_severity (severity),
                INDEX idx_threat_level (threat_level),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at),
                INDEX idx_alert_id (alert_id),
                INDEX idx_composite_search (alert_type, severity, status, created_at),
                INDEX idx_active_alerts (status, severity, created_at),
                
                FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS security_alerts");
    }

    public function getDescription(): string
    {
        return 'Create security_alerts table for threat detection and monitoring';
    }
}