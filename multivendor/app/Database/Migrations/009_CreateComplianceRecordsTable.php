<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create compliance_records table migration
 */
class CreateComplianceRecordsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE compliance_records (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            compliance_type ENUM('fssai', 'organic', 'halal', 'kosher', 'other') NOT NULL,
            certificate_number VARCHAR(100),
            issuing_authority VARCHAR(255),
            issue_date DATE,
            expiry_date DATE,
            status ENUM('valid', 'expired', 'suspended', 'revoked') DEFAULT 'valid',
            document_path VARCHAR(500),
            verification_notes TEXT,
            last_verified_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_compliance_type (compliance_type),
            INDEX idx_status (status),
            INDEX idx_expiry_date (expiry_date),
            INDEX idx_certificate_number (certificate_number)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS compliance_records");
    }
}