<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Audit trail service for tracking changes
 */
class AuditTrailService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Log profile update
     */
    public function logProfileUpdate(int $vendorId, array $changes, ?int $updatedBy = null): void
    {
        if (empty($changes)) {
            return; // No changes to log
        }

        try {
            $sql = "INSERT INTO audit_trails (
                entity_type, entity_id, action, changes, 
                performed_by, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'vendor',
                $vendorId,
                'profile_update',
                json_encode($changes),
                $updatedBy,
                $this->getClientIP(),
                $this->getUserAgent()
            ]);

        } catch (Exception $e) {
            // Log error but don't fail the main operation
            error_log("Audit trail logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log profile suspension
     */
    public function logProfileSuspension(int $vendorId, string $reason, ?int $suspendedBy = null): void
    {
        try {
            $sql = "INSERT INTO audit_trails (
                entity_type, entity_id, action, changes, 
                performed_by, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $changes = [
                'status' => ['old' => 'active', 'new' => 'suspended'],
                'suspension_reason' => $reason
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'vendor',
                $vendorId,
                'profile_suspended',
                json_encode($changes),
                $suspendedBy,
                $this->getClientIP(),
                $this->getUserAgent()
            ]);

        } catch (Exception $e) {
            error_log("Audit trail logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log vendor registration
     */
    public function logVendorRegistration(int $vendorId, array $vendorData, ?int $registeredBy = null): void
    {
        try {
            $sql = "INSERT INTO audit_trails (
                entity_type, entity_id, action, changes, 
                performed_by, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            // Don't log sensitive data
            $logData = $vendorData;
            unset($logData['fssai_license']); // Keep license private in logs

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'vendor',
                $vendorId,
                'vendor_registered',
                json_encode(['vendor_data' => $logData]),
                $registeredBy,
                $this->getClientIP(),
                $this->getUserAgent()
            ]);

        } catch (Exception $e) {
            error_log("Audit trail logging failed: " . $e->getMessage());
        }
    }

    /**
     * Get profile history
     */
    public function getProfileHistory(int $vendorId, int $limit = 50): array
    {
        try {
            $sql = "SELECT * FROM audit_trails 
                    WHERE entity_type = 'vendor' AND entity_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $limit]);
            
            $history = $stmt->fetchAll();
            
            // Decode JSON changes
            foreach ($history as &$record) {
                $record['changes'] = json_decode($record['changes'], true);
            }

            return [
                'success' => true,
                'history' => $history
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log role assignment
     */
    public function logRoleAssignment(int $vendorId, int $userId, string $role, ?int $assignedBy = null): void
    {
        try {
            $sql = "INSERT INTO audit_trails (
                entity_type, entity_id, action, changes, 
                performed_by, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $changes = [
                'user_id' => $userId,
                'role' => $role,
                'action' => 'role_assigned'
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'vendor',
                $vendorId,
                'role_assigned',
                json_encode($changes),
                $assignedBy,
                $this->getClientIP(),
                $this->getUserAgent()
            ]);

        } catch (Exception $e) {
            error_log("Audit trail logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log role removal
     */
    public function logRoleRemoval(int $vendorId, int $userId, string $role, ?int $removedBy = null): void
    {
        try {
            $sql = "INSERT INTO audit_trails (
                entity_type, entity_id, action, changes, 
                performed_by, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

            $changes = [
                'user_id' => $userId,
                'role' => $role,
                'action' => 'role_removed'
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'vendor',
                $vendorId,
                'role_removed',
                json_encode($changes),
                $removedBy,
                $this->getClientIP(),
                $this->getUserAgent()
            ]);

        } catch (Exception $e) {
            error_log("Audit trail logging failed: " . $e->getMessage());
        }
    }

    /**
     * Get audit statistics
     */
    public function getAuditStats(int $vendorId): array
    {
        try {
            $sql = "SELECT 
                        action,
                        COUNT(*) as count,
                        MAX(created_at) as last_occurrence
                    FROM audit_trails 
                    WHERE entity_type = 'vendor' AND entity_id = ?
                    GROUP BY action
                    ORDER BY count DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            
            return [
                'success' => true,
                'stats' => $stmt->fetchAll()
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving audit stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIP(): ?string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        
        return null;
    }

    /**
     * Get user agent
     */
    private function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Create audit trails table if it doesn't exist
     */
    public function createAuditTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS audit_trails (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT NOT NULL,
            action VARCHAR(100) NOT NULL,
            changes JSON,
            performed_by BIGINT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_action (action),
            INDEX idx_performed_by (performed_by),
            INDEX idx_created_at (created_at)
        )";
        
        $this->db->exec($sql);
    }
}