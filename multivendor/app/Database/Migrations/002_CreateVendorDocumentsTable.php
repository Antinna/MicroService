<?php

namespace Antinna\MultiVendor\Database\Migrations;

use Antinna\MultiVendor\Database\BaseMigration;

/**
 * Create vendor_documents table migration
 */
class CreateVendorDocumentsTable extends BaseMigration
{
    public function up(): void
    {
        $sql = "CREATE TABLE vendor_documents (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            document_type ENUM('kyc', 'fssai', 'business_license', 'tax_certificate') NOT NULL,
            document_number VARCHAR(100),
            document_path VARCHAR(500),
            verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
            verified_at TIMESTAMP NULL,
            verified_by VARCHAR(255),
            rejection_reason TEXT,
            expires_at DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_document_type (document_type),
            INDEX idx_verification_status (verification_status),
            INDEX idx_expires_at (expires_at)
        )";
        
        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS vendor_documents");
    }
}