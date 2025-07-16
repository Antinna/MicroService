<?php

namespace Antinna\MultiVendor\Repositories;

/**
 * Vendor role repository for database operations
 */
class VendorRoleRepository extends BaseRepository
{
    protected string $table = 'vendor_roles';

    /**
     * Find roles by vendor
     */
    public function findByVendor(int $vendorId): array
    {
        return $this->findAll(['vendor_id' => $vendorId]);
    }

    /**
     * Find roles by vendor and user
     */
    public function findByVendorAndUser(int $vendorId, int $userId, ?string $role = null): array
    {
        $conditions = [
            'vendor_id' => $vendorId,
            'user_id' => $userId
        ];

        if ($role) {
            $conditions['role'] = $role;
            // Return single record for specific role
            $results = $this->findAll($conditions);
            return !empty($results) ? $results[0] : null;
        }

        return $this->findAll($conditions);
    }

    /**
     * Find roles by vendor and role type
     */
    public function findByVendorAndRole(int $vendorId, string $role): array
    {
        return $this->findAll(['vendor_id' => $vendorId, 'role' => $role]);
    }

    /**
     * Find active roles by vendor
     */
    public function findActiveByVendor(int $vendorId): array
    {
        return $this->findAll(['vendor_id' => $vendorId, 'is_active' => true]);
    }

    /**
     * Find roles by user across all vendors
     */
    public function findByUser(int $userId): array
    {
        $sql = "SELECT vr.*, v.business_name as vendor_name
                FROM {$this->table} vr
                JOIN vendors v ON vr.vendor_id = v.id
                WHERE vr.user_id = ?
                ORDER BY vr.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Check if user has any role for vendor
     */
    public function hasAnyRole(int $vendorId, int $userId): bool
    {
        $roles = $this->findByVendorAndUser($vendorId, $userId);
        return !empty($roles);
    }

    /**
     * Check if user has specific role for vendor
     */
    public function hasRole(int $vendorId, int $userId, string $role): bool
    {
        $roleRecord = $this->findByVendorAndUser($vendorId, $userId, $role);
        return $roleRecord && $roleRecord['is_active'];
    }

    /**
     * Get role statistics for vendor
     */
    public function getRoleStats(int $vendorId): array
    {
        $sql = "SELECT 
                    role,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                FROM {$this->table}
                WHERE vendor_id = ?
                GROUP BY role
                ORDER BY total_count DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$vendorId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get users with specific role for vendor
     */
    public function getUsersWithRole(int $vendorId, string $role): array
    {
        $sql = "SELECT vr.*, vr.user_id, vr.permissions, vr.is_active, vr.assigned_at
                FROM {$this->table} vr
                WHERE vr.vendor_id = ? AND vr.role = ?
                ORDER BY vr.assigned_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$vendorId, $role]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get role history for user and vendor
     */
    public function getRoleHistory(int $vendorId, int $userId): array
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE vendor_id = ? AND user_id = ?
                ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$vendorId, $userId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Bulk update role status
     */
    public function bulkUpdateStatus(array $roleIds, bool $isActive): bool
    {
        if (empty($roleIds)) {
            return true;
        }

        $placeholders = str_repeat('?,', count($roleIds) - 1) . '?';
        $sql = "UPDATE {$this->table} 
                SET is_active = ? 
                WHERE id IN ({$placeholders})";
        
        $params = array_merge([$isActive], $roleIds);
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Remove all roles for user from vendor
     */
    public function removeAllUserRoles(int $vendorId, int $userId): bool
    {
        $sql = "DELETE FROM {$this->table} 
                WHERE vendor_id = ? AND user_id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$vendorId, $userId]);
    }

    /**
     * Get vendors where user has specific role
     */
    public function getVendorsWithUserRole(int $userId, string $role): array
    {
        $sql = "SELECT v.*, vr.permissions, vr.is_active, vr.assigned_at
                FROM vendors v
                JOIN {$this->table} vr ON v.id = vr.vendor_id
                WHERE vr.user_id = ? AND vr.role = ?
                ORDER BY v.business_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $role]);
        
        return $stmt->fetchAll();
    }
}