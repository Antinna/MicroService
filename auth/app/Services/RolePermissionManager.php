<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Exception;

/**
 * Role and Permission Management Service
 */
class RolePermissionManager
{
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private array $roleHierarchy;
    private array $permissionCache = [];

    // System roles
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_USER = 'user';
    public const ROLE_GUEST = 'guest';

    // Permission categories
    public const CATEGORY_USER = 'user';
    public const CATEGORY_ADMIN = 'admin';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_API = 'api';

    // Common permissions
    public const PERMISSION_USER_READ = 'user:read';
    public const PERMISSION_USER_WRITE = 'user:write';
    public const PERMISSION_USER_DELETE = 'user:delete';
    public const PERMISSION_ADMIN_ACCESS = 'admin:access';
    public const PERMISSION_ADMIN_USERS = 'admin:users';
    public const PERMISSION_ADMIN_SYSTEM = 'admin:system';
    public const PERMISSION_CONTENT_READ = 'content:read';
    public const PERMISSION_CONTENT_WRITE = 'content:write';
    public const PERMISSION_CONTENT_DELETE = 'content:delete';
    public const PERMISSION_SYSTEM_CONFIG = 'system:config';
    public const PERMISSION_API_ACCESS = 'api:access';

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->initializeRoleHierarchy();
    }

    /**
     * Check if user has specific permission
     */
    public function hasPermission(int $userId, string $permission, array $context = []): array
    {
        try {
            $startTime = microtime(true);
            
            // Get user information
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'has_permission' => false,
                    'reason' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            if (!$user['is_active']) {
                return [
                    'has_permission' => false,
                    'reason' => 'User account is inactive',
                    'code' => 'USER_INACTIVE'
                ];
            }

            // Check role-based permissions
            $rolePermissions = $this->getRolePermissions($user['role']);
            $hasRolePermission = $this->checkPermissionMatch($permission, $rolePermissions);

            // Check user-specific permissions
            $userPermissions = $this->getUserSpecificPermissions($userId);
            $hasUserPermission = $this->checkPermissionMatch($permission, $userPermissions);

            // Check context-based permissions (resource-specific)
            $hasContextPermission = $this->checkContextPermission($userId, $permission, $context);

            $hasPermission = $hasRolePermission || $hasUserPermission || $hasContextPermission;

            // Log permission check
            $this->auditLogger->logSystemEvent(
                'permission_check',
                "Permission check: $permission for user {$user['email']}",
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'user_role' => $user['role'],
                    'has_permission' => $hasPermission,
                    'context' => $context,
                    'check_time' => microtime(true) - $startTime
                ]
            );

            return [
                'has_permission' => $hasPermission,
                'user_id' => $userId,
                'user_role' => $user['role'],
                'permission' => $permission,
                'granted_by' => $this->getPermissionSource($hasRolePermission, $hasUserPermission, $hasContextPermission),
                'context' => $context,
                'check_time' => microtime(true) - $startTime
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'permission_check_error',
                'Permission check error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId, 'permission' => $permission]
            );

            return [
                'has_permission' => false,
                'reason' => 'Permission check system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Check multiple permissions for user
     */
    public function hasPermissions(int $userId, array $permissions, bool $requireAll = true): array
    {
        $results = [];
        $grantedPermissions = [];
        $deniedPermissions = [];

        foreach ($permissions as $permission) {
            $result = $this->hasPermission($userId, $permission);
            $results[$permission] = $result;
            
            if ($result['has_permission']) {
                $grantedPermissions[] = $permission;
            } else {
                $deniedPermissions[] = $permission;
            }
        }

        $hasAllPermissions = empty($deniedPermissions);
        $hasAnyPermission = !empty($grantedPermissions);

        return [
            'has_permissions' => $requireAll ? $hasAllPermissions : $hasAnyPermission,
            'require_all' => $requireAll,
            'granted_permissions' => $grantedPermissions,
            'denied_permissions' => $deniedPermissions,
            'detailed_results' => $results,
            'summary' => [
                'total_checked' => count($permissions),
                'granted_count' => count($grantedPermissions),
                'denied_count' => count($deniedPermissions)
            ]
        ];
    }

    /**
     * Get all permissions for a user
     */
    public function getUserPermissions(int $userId): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Get role-based permissions
            $rolePermissions = $this->getRolePermissions($user['role']);
            
            // Get user-specific permissions
            $userPermissions = $this->getUserSpecificPermissions($userId);
            
            // Get inherited permissions from role hierarchy
            $inheritedPermissions = $this->getInheritedPermissions($user['role']);
            
            // Combine all permissions
            $allPermissions = array_unique(array_merge(
                $rolePermissions,
                $userPermissions,
                $inheritedPermissions
            ));

            return [
                'success' => true,
                'user_id' => $userId,
                'user_role' => $user['role'],
                'permissions' => [
                    'all' => $allPermissions,
                    'role_based' => $rolePermissions,
                    'user_specific' => $userPermissions,
                    'inherited' => $inheritedPermissions
                ],
                'permission_count' => count($allPermissions),
                'grouped_permissions' => $this->groupPermissionsByCategory($allPermissions)
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'get_user_permissions_error',
                'Get user permissions error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'success' => false,
                'message' => 'Failed to retrieve user permissions',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Validate role hierarchy and permissions
     */
    public function validateRole(string $role): array
    {
        $validRoles = array_keys($this->roleHierarchy);
        
        if (!in_array($role, $validRoles)) {
            return [
                'valid' => false,
                'message' => 'Invalid role',
                'code' => 'INVALID_ROLE',
                'valid_roles' => $validRoles
            ];
        }

        return [
            'valid' => true,
            'role' => $role,
            'level' => $this->roleHierarchy[$role]['level'],
            'permissions' => $this->roleHierarchy[$role]['permissions'],
            'inherits_from' => $this->roleHierarchy[$role]['inherits_from'] ?? null
        ];
    }

    /**
     * Check if role can perform action on target role
     */
    public function canManageRole(string $managerRole, string $targetRole): array
    {
        $managerValidation = $this->validateRole($managerRole);
        $targetValidation = $this->validateRole($targetRole);

        if (!$managerValidation['valid'] || !$targetValidation['valid']) {
            return [
                'can_manage' => false,
                'reason' => 'Invalid role(s)',
                'code' => 'INVALID_ROLE'
            ];
        }

        $managerLevel = $this->roleHierarchy[$managerRole]['level'];
        $targetLevel = $this->roleHierarchy[$targetRole]['level'];

        // Higher level roles can manage lower level roles
        $canManage = $managerLevel > $targetLevel;

        return [
            'can_manage' => $canManage,
            'manager_role' => $managerRole,
            'manager_level' => $managerLevel,
            'target_role' => $targetRole,
            'target_level' => $targetLevel,
            'reason' => $canManage ? 'Sufficient role level' : 'Insufficient role level'
        ];
    }

    /**
     * Grant permission to user
     */
    public function grantPermission(int $userId, string $permission, int $grantedBy, array $context = []): array
    {
        try {
            // Validate permission format
            if (!$this->isValidPermission($permission)) {
                return [
                    'success' => false,
                    'message' => 'Invalid permission format',
                    'code' => 'INVALID_PERMISSION'
                ];
            }

            // Check if granter has permission to grant this permission
            $granterCheck = $this->hasPermission($grantedBy, 'admin:permissions');
            if (!$granterCheck['has_permission']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient privileges to grant permissions',
                    'code' => 'INSUFFICIENT_PRIVILEGES'
                ];
            }

            // Grant the permission (this would typically update database)
            $success = $this->addUserPermission($userId, $permission, $grantedBy, $context);

            if ($success) {
                // Clear permission cache for user
                $this->clearUserPermissionCache($userId);

                // Log permission grant
                $this->auditLogger->logAdminAction(
                    'permission_granted',
                    "Permission '$permission' granted to user",
                    $grantedBy,
                    $userId,
                    [
                        'permission' => $permission,
                        'context' => $context
                    ]
                );

                return [
                    'success' => true,
                    'message' => 'Permission granted successfully',
                    'user_id' => $userId,
                    'permission' => $permission,
                    'granted_by' => $grantedBy,
                    'granted_at' => date('Y-m-d H:i:s')
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to grant permission',
                    'code' => 'GRANT_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'grant_permission_error',
                'Grant permission error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId, 'permission' => $permission]
            );

            return [
                'success' => false,
                'message' => 'Permission grant system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Revoke permission from user
     */
    public function revokePermission(int $userId, string $permission, int $revokedBy, string $reason = ''): array
    {
        try {
            // Check if revoker has permission to revoke permissions
            $revokerCheck = $this->hasPermission($revokedBy, 'admin:permissions');
            if (!$revokerCheck['has_permission']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient privileges to revoke permissions',
                    'code' => 'INSUFFICIENT_PRIVILEGES'
                ];
            }

            // Revoke the permission
            $success = $this->removeUserPermission($userId, $permission, $revokedBy, $reason);

            if ($success) {
                // Clear permission cache for user
                $this->clearUserPermissionCache($userId);

                // Log permission revocation
                $this->auditLogger->logAdminAction(
                    'permission_revoked',
                    "Permission '$permission' revoked from user",
                    $revokedBy,
                    $userId,
                    [
                        'permission' => $permission,
                        'reason' => $reason
                    ]
                );

                return [
                    'success' => true,
                    'message' => 'Permission revoked successfully',
                    'user_id' => $userId,
                    'permission' => $permission,
                    'revoked_by' => $revokedBy,
                    'reason' => $reason,
                    'revoked_at' => date('Y-m-d H:i:s')
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to revoke permission',
                    'code' => 'REVOKE_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'revoke_permission_error',
                'Revoke permission error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId, 'permission' => $permission]
            );

            return [
                'success' => false,
                'message' => 'Permission revoke system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Get role hierarchy information
     */
    public function getRoleHierarchy(): array
    {
        return [
            'hierarchy' => $this->roleHierarchy,
            'roles_by_level' => $this->getRolesByLevel(),
            'permission_matrix' => $this->getPermissionMatrix()
        ];
    }

    // Private helper methods

    /**
     * Initialize role hierarchy
     */
    private function initializeRoleHierarchy(): void
    {
        $this->roleHierarchy = [
            self::ROLE_SUPER_ADMIN => [
                'level' => 100,
                'permissions' => [
                    self::PERMISSION_ADMIN_SYSTEM,
                    self::PERMISSION_ADMIN_USERS,
                    self::PERMISSION_ADMIN_ACCESS,
                    self::PERMISSION_SYSTEM_CONFIG,
                    'admin:*',
                    'system:*',
                    'user:*',
                    'content:*',
                    'api:*'
                ],
                'inherits_from' => null
            ],
            self::ROLE_ADMIN => [
                'level' => 80,
                'permissions' => [
                    self::PERMISSION_ADMIN_USERS,
                    self::PERMISSION_ADMIN_ACCESS,
                    self::PERMISSION_USER_READ,
                    self::PERMISSION_USER_WRITE,
                    self::PERMISSION_USER_DELETE,
                    self::PERMISSION_CONTENT_READ,
                    self::PERMISSION_CONTENT_WRITE,
                    self::PERMISSION_CONTENT_DELETE,
                    self::PERMISSION_API_ACCESS
                ],
                'inherits_from' => self::ROLE_MODERATOR
            ],
            self::ROLE_MODERATOR => [
                'level' => 60,
                'permissions' => [
                    self::PERMISSION_USER_READ,
                    self::PERMISSION_USER_WRITE,
                    self::PERMISSION_CONTENT_READ,
                    self::PERMISSION_CONTENT_WRITE,
                    self::PERMISSION_API_ACCESS
                ],
                'inherits_from' => self::ROLE_USER
            ],
            self::ROLE_USER => [
                'level' => 40,
                'permissions' => [
                    self::PERMISSION_USER_READ,
                    self::PERMISSION_CONTENT_READ,
                    self::PERMISSION_API_ACCESS
                ],
                'inherits_from' => self::ROLE_GUEST
            ],
            self::ROLE_GUEST => [
                'level' => 20,
                'permissions' => [
                    'content:read:public'
                ],
                'inherits_from' => null
            ]
        ];
    }

    /**
     * Get permissions for a role
     */
    private function getRolePermissions(string $role): array
    {
        return $this->roleHierarchy[$role]['permissions'] ?? [];
    }

    /**
     * Get user-specific permissions
     */
    private function getUserSpecificPermissions(int $userId): array
    {
        // This would query user_permissions table
        // For now, return empty array
        return [];
    }

    /**
     * Get inherited permissions from role hierarchy
     */
    private function getInheritedPermissions(string $role): array
    {
        $inherited = [];
        $currentRole = $role;

        while ($currentRole && isset($this->roleHierarchy[$currentRole]['inherits_from'])) {
            $parentRole = $this->roleHierarchy[$currentRole]['inherits_from'];
            if ($parentRole && isset($this->roleHierarchy[$parentRole])) {
                $inherited = array_merge($inherited, $this->roleHierarchy[$parentRole]['permissions']);
                $currentRole = $parentRole;
            } else {
                break;
            }
        }

        return array_unique($inherited);
    }

    /**
     * Check if permission matches any in the list (supports wildcards)
     */
    private function checkPermissionMatch(string $permission, array $permissionList): bool
    {
        foreach ($permissionList as $allowedPermission) {
            if ($this->permissionMatches($permission, $allowedPermission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if permission matches pattern (supports wildcards)
     */
    private function permissionMatches(string $permission, string $pattern): bool
    {
        // Exact match
        if ($permission === $pattern) {
            return true;
        }

        // Wildcard match
        if (str_ends_with($pattern, ':*')) {
            $prefix = substr($pattern, 0, -2);
            return str_starts_with($permission, $prefix . ':');
        }

        return false;
    }

    /**
     * Check context-based permissions
     */
    private function checkContextPermission(int $userId, string $permission, array $context): bool
    {
        // This would implement resource-specific permission checking
        // For example, checking if user owns a resource or has specific access
        return false;
    }

    /**
     * Get permission source
     */
    private function getPermissionSource(bool $rolePermission, bool $userPermission, bool $contextPermission): array
    {
        $sources = [];
        if ($rolePermission) $sources[] = 'role';
        if ($userPermission) $sources[] = 'user_specific';
        if ($contextPermission) $sources[] = 'context';
        return $sources;
    }

    /**
     * Group permissions by category
     */
    private function groupPermissionsByCategory(array $permissions): array
    {
        $grouped = [];
        foreach ($permissions as $permission) {
            $category = explode(':', $permission)[0] ?? 'other';
            $grouped[$category][] = $permission;
        }
        return $grouped;
    }

    /**
     * Validate permission format
     */
    private function isValidPermission(string $permission): bool
    {
        // Permission format: category:action or category:action:resource
        return preg_match('/^[a-z_]+:[a-z_*]+(?::[a-z_]+)?$/', $permission);
    }

    /**
     * Add user permission (placeholder - would update database)
     */
    private function addUserPermission(int $userId, string $permission, int $grantedBy, array $context): bool
    {
        // This would insert into user_permissions table
        return true;
    }

    /**
     * Remove user permission (placeholder - would update database)
     */
    private function removeUserPermission(int $userId, string $permission, int $revokedBy, string $reason): bool
    {
        // This would delete from user_permissions table
        return true;
    }

    /**
     * Clear user permission cache
     */
    private function clearUserPermissionCache(int $userId): void
    {
        unset($this->permissionCache[$userId]);
    }

    /**
     * Get roles by level
     */
    private function getRolesByLevel(): array
    {
        $rolesByLevel = [];
        foreach ($this->roleHierarchy as $role => $config) {
            $rolesByLevel[$config['level']] = $role;
        }
        krsort($rolesByLevel); // Sort by level descending
        return $rolesByLevel;
    }

    /**
     * Get permission matrix
     */
    private function getPermissionMatrix(): array
    {
        $matrix = [];
        foreach ($this->roleHierarchy as $role => $config) {
            $matrix[$role] = [
                'level' => $config['level'],
                'direct_permissions' => $config['permissions'],
                'inherited_permissions' => $this->getInheritedPermissions($role),
                'all_permissions' => array_unique(array_merge(
                    $config['permissions'],
                    $this->getInheritedPermissions($role)
                ))
            ];
        }
        return $matrix;
    }
}