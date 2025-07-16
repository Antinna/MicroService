<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Interfaces\ServiceInterface;
use Antinna\MultiVendor\Repositories\VendorRoleRepository;
use Antinna\MultiVendor\Services\AuditTrailService;
use Exception;

/**
 * Vendor role management service for owner/manager/delivery staff roles
 */
class VendorRoleManager implements ServiceInterface
{
    private VendorRoleRepository $roleRepository;
    private AuditTrailService $auditTrail;

    // Role definitions with permissions
    private array $rolePermissions = [
        'owner' => [
            'vendor.manage',
            'vendor.view',
            'products.manage',
            'products.view',
            'orders.manage',
            'orders.view',
            'analytics.view',
            'roles.manage',
            'roles.view',
            'payouts.view',
            'settings.manage'
        ],
        'manager' => [
            'vendor.view',
            'products.manage',
            'products.view',
            'orders.manage',
            'orders.view',
            'analytics.view',
            'roles.view'
        ],
        'delivery_staff' => [
            'orders.view',
            'orders.update_status',
            'delivery.manage'
        ]
    ];

    public function __construct()
    {
        $this->roleRepository = new VendorRoleRepository();
        $this->auditTrail = new AuditTrailService();
    }

    /**
     * Validate role assignment data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = ['vendor_id', 'user_id', 'role'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Vendor ID validation
        if (!empty($data['vendor_id']) && !is_numeric($data['vendor_id'])) {
            $errors['vendor_id'] = 'Vendor ID must be a valid number';
        }

        // User ID validation
        if (!empty($data['user_id']) && !is_numeric($data['user_id'])) {
            $errors['user_id'] = 'User ID must be a valid number';
        }

        // Role validation
        if (!empty($data['role'])) {
            $validRoles = array_keys($this->rolePermissions);
            if (!in_array($data['role'], $validRoles)) {
                $errors['role'] = 'Role must be one of: ' . implode(', ', $validRoles);
            }
        }

        // Custom permissions validation
        if (!empty($data['permissions'])) {
            if (!is_array($data['permissions'])) {
                $errors['permissions'] = 'Permissions must be an array';
            } else {
                $allPermissions = $this->getAllAvailablePermissions();
                foreach ($data['permissions'] as $permission) {
                    if (!in_array($permission, $allPermissions)) {
                        $errors['permissions'][] = "Invalid permission: {$permission}";
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Process role assignment
     */
    public function process(array $data): array
    {
        return $this->assignRole(
            $data['vendor_id'],
            $data['user_id'],
            $data['role'],
            $data['permissions'] ?? null,
            $data['assigned_by'] ?? null
        );
    }

    /**
     * Assign role to user for vendor
     */
    public function assignRole(int $vendorId, int $userId, string $role, ?array $customPermissions = null, ?int $assignedBy = null): array
    {
        try {
            // Validate input data
            $validation = $this->validate([
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'role' => $role,
                'permissions' => $customPermissions
            ]);

            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check if role assignment already exists
            $existingRole = $this->roleRepository->findByVendorAndUser($vendorId, $userId, $role);
            if ($existingRole) {
                return [
                    'success' => false,
                    'error' => 'User already has this role for this vendor'
                ];
            }

            // Prepare role data
            $permissions = $customPermissions ?? $this->rolePermissions[$role];
            $roleData = [
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'role' => $role,
                'permissions' => json_encode($permissions),
                'is_active' => true,
                'assigned_by' => $assignedBy
            ];

            // Create role assignment
            $roleId = $this->roleRepository->create($roleData);

            // Log audit trail
            $this->auditTrail->logRoleAssignment($vendorId, $userId, $role, $assignedBy);

            return [
                'success' => true,
                'role_id' => $roleId,
                'message' => 'Role assigned successfully',
                'permissions' => $permissions
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Role assignment failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Remove role from user
     */
    public function removeRole(int $vendorId, int $userId, string $role, ?int $removedBy = null): array
    {
        try {
            $existingRole = $this->roleRepository->findByVendorAndUser($vendorId, $userId, $role);
            
            if (!$existingRole) {
                return [
                    'success' => false,
                    'error' => 'Role assignment not found'
                ];
            }

            // Prevent removing the last owner
            if ($role === 'owner' && $this->isLastOwner($vendorId, $userId)) {
                return [
                    'success' => false,
                    'error' => 'Cannot remove the last owner of the vendor'
                ];
            }

            // Remove role
            $success = $this->roleRepository->delete($existingRole['id']);

            if ($success) {
                // Log audit trail
                $this->auditTrail->logRoleRemoval($vendorId, $userId, $role, $removedBy);

                return [
                    'success' => true,
                    'message' => 'Role removed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to remove role'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Role removal failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update role permissions
     */
    public function updatePermissions(int $roleId, array $permissions, ?int $updatedBy = null): array
    {
        try {
            $role = $this->roleRepository->find($roleId);
            
            if (!$role) {
                return [
                    'success' => false,
                    'error' => 'Role not found'
                ];
            }

            // Validate permissions
            $allPermissions = $this->getAllAvailablePermissions();
            foreach ($permissions as $permission) {
                if (!in_array($permission, $allPermissions)) {
                    return [
                        'success' => false,
                        'error' => "Invalid permission: {$permission}"
                    ];
                }
            }

            // Update permissions
            $success = $this->roleRepository->update($roleId, [
                'permissions' => json_encode($permissions)
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Permissions updated successfully',
                    'permissions' => $permissions
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update permissions'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Permission update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get user roles for vendor
     */
    public function getUserRoles(int $vendorId, int $userId): array
    {
        try {
            $roles = $this->roleRepository->findByVendorAndUser($vendorId, $userId);
            
            $userRoles = [];
            foreach ($roles as $role) {
                $userRoles[] = [
                    'id' => $role['id'],
                    'role' => $role['role'],
                    'permissions' => json_decode($role['permissions'], true),
                    'is_active' => $role['is_active'],
                    'assigned_at' => $role['assigned_at']
                ];
            }

            return [
                'success' => true,
                'roles' => $userRoles
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving user roles: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all roles for vendor
     */
    public function getVendorRoles(int $vendorId): array
    {
        try {
            $roles = $this->roleRepository->findByVendor($vendorId);
            
            $vendorRoles = [];
            foreach ($roles as $role) {
                $vendorRoles[] = [
                    'id' => $role['id'],
                    'user_id' => $role['user_id'],
                    'role' => $role['role'],
                    'permissions' => json_decode($role['permissions'], true),
                    'is_active' => $role['is_active'],
                    'assigned_at' => $role['assigned_at'],
                    'assigned_by' => $role['assigned_by']
                ];
            }

            return [
                'success' => true,
                'roles' => $vendorRoles
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving vendor roles: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if user has permission for vendor
     */
    public function hasPermission(int $vendorId, int $userId, string $permission): bool
    {
        try {
            $userRoles = $this->getUserRoles($vendorId, $userId);
            
            if (!$userRoles['success']) {
                return false;
            }

            foreach ($userRoles['roles'] as $role) {
                if ($role['is_active'] && in_array($permission, $role['permissions'])) {
                    return true;
                }
            }

            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if user has role for vendor
     */
    public function hasRole(int $vendorId, int $userId, string $role): bool
    {
        try {
            $userRoles = $this->getUserRoles($vendorId, $userId);
            
            if (!$userRoles['success']) {
                return false;
            }

            foreach ($userRoles['roles'] as $userRole) {
                if ($userRole['is_active'] && $userRole['role'] === $role) {
                    return true;
                }
            }

            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Activate/Deactivate role
     */
    public function toggleRoleStatus(int $roleId, bool $isActive, ?int $updatedBy = null): array
    {
        try {
            $role = $this->roleRepository->find($roleId);
            
            if (!$role) {
                return [
                    'success' => false,
                    'error' => 'Role not found'
                ];
            }

            // Prevent deactivating the last owner
            if (!$isActive && $role['role'] === 'owner' && $this->isLastOwner($role['vendor_id'], $role['user_id'])) {
                return [
                    'success' => false,
                    'error' => 'Cannot deactivate the last owner of the vendor'
                ];
            }

            $success = $this->roleRepository->update($roleId, ['is_active' => $isActive]);

            if ($success) {
                $status = $isActive ? 'activated' : 'deactivated';
                return [
                    'success' => true,
                    'message' => "Role {$status} successfully"
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update role status'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Role status update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get available roles and their permissions
     */
    public function getAvailableRoles(): array
    {
        return [
            'success' => true,
            'roles' => array_map(function($role, $permissions) {
                return [
                    'role' => $role,
                    'permissions' => $permissions,
                    'description' => $this->getRoleDescription($role)
                ];
            }, array_keys($this->rolePermissions), $this->rolePermissions)
        ];
    }

    /**
     * Get all available permissions
     */
    private function getAllAvailablePermissions(): array
    {
        $allPermissions = [];
        foreach ($this->rolePermissions as $permissions) {
            $allPermissions = array_merge($allPermissions, $permissions);
        }
        return array_unique($allPermissions);
    }

    /**
     * Check if user is the last owner of vendor
     */
    private function isLastOwner(int $vendorId, int $userId): bool
    {
        $owners = $this->roleRepository->findByVendorAndRole($vendorId, 'owner');
        $activeOwners = array_filter($owners, function($owner) {
            return $owner['is_active'];
        });
        
        return count($activeOwners) === 1 && $activeOwners[0]['user_id'] == $userId;
    }

    /**
     * Get role description
     */
    private function getRoleDescription(string $role): string
    {
        $descriptions = [
            'owner' => 'Full access to all vendor operations, settings, and user management',
            'manager' => 'Manage products, orders, and view analytics. Cannot manage roles or settings',
            'delivery_staff' => 'View and update delivery orders, manage delivery status'
        ];

        return $descriptions[$role] ?? 'Unknown role';
    }
}