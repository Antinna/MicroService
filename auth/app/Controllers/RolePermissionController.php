<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\RolePermissionManager;
use Antinna\Auth\Services\TokenValidator;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Exception;

/**
 * Role and Permission API Controller
 */
class RolePermissionController
{
    private RolePermissionManager $rolePermissionManager;
    private TokenValidator $tokenValidator;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->rolePermissionManager = new RolePermissionManager();
        $this->tokenValidator = new TokenValidator();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * Check if user has specific permission
     * POST /api/permissions/check
     */
    public function checkPermission(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['user_id']) || !isset($input['permission'])) {
                $this->sendError('User ID and permission are required', 400, 'MISSING_REQUIRED_FIELDS');
                return;
            }

            $userId = (int)$input['user_id'];
            $permission = $input['permission'];
            $context = $input['context'] ?? [];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'permission_check'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Check permission
            $result = $this->rolePermissionManager->hasPermission($userId, $permission, $context);

            if (isset($result['code']) && in_array($result['code'], ['USER_NOT_FOUND', 'USER_INACTIVE', 'SYSTEM_ERROR'])) {
                $this->sendError($result['reason'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'has_permission' => $result['has_permission'],
                'user_id' => $result['user_id'],
                'user_role' => $result['user_role'],
                'permission' => $result['permission'],
                'granted_by' => $result['granted_by'] ?? [],
                'context' => $result['context'],
                'check_time' => $result['check_time']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'permission_check_api_error',
                'Permission check API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Permission check system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Check multiple permissions for user
     * POST /api/permissions/check-multiple
     */
    public function checkMultiplePermissions(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['user_id']) || !isset($input['permissions'])) {
                $this->sendError('User ID and permissions array are required', 400, 'MISSING_REQUIRED_FIELDS');
                return;
            }

            $userId = (int)$input['user_id'];
            $permissions = $input['permissions'];
            $requireAll = $input['require_all'] ?? true;

            if (!is_array($permissions) || empty($permissions)) {
                $this->sendError('Permissions must be a non-empty array', 400, 'INVALID_PERMISSIONS');
                return;
            }

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'permission_check'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Check multiple permissions
            $result = $this->rolePermissionManager->hasPermissions($userId, $permissions, $requireAll);

            $this->sendSuccess([
                'has_permissions' => $result['has_permissions'],
                'require_all' => $result['require_all'],
                'granted_permissions' => $result['granted_permissions'],
                'denied_permissions' => $result['denied_permissions'],
                'summary' => $result['summary'],
                'detailed_results' => $result['detailed_results']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'multiple_permission_check_api_error',
                'Multiple permission check API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Multiple permission check system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get all permissions for a user
     * GET /api/permissions/user/{userId}
     */
    public function getUserPermissions(int $userId): void
    {
        try {
            // Validate authentication and authorization
            $authResult = $this->validateAuthentication(['admin:users', 'user:read']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['user']['id'],
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get user permissions
            $result = $this->rolePermissionManager->getUserPermissions($userId);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'user_id' => $result['user_id'],
                'user_role' => $result['user_role'],
                'permissions' => $result['permissions'],
                'permission_count' => $result['permission_count'],
                'grouped_permissions' => $result['grouped_permissions']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'get_user_permissions_api_error',
                'Get user permissions API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Get user permissions system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Validate role
     * POST /api/roles/validate
     */
    public function validateRole(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['role'])) {
                $this->sendError('Role is required', 400, 'MISSING_ROLE');
                return;
            }

            $role = $input['role'];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'role_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate role
            $result = $this->rolePermissionManager->validateRole($role);

            if (!$result['valid']) {
                $this->sendError($result['message'], 400, $result['code'], [
                    'valid_roles' => $result['valid_roles']
                ]);
                return;
            }

            $this->sendSuccess([
                'valid' => $result['valid'],
                'role' => $result['role'],
                'level' => $result['level'],
                'permissions' => $result['permissions'],
                'inherits_from' => $result['inherits_from']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'role_validation_api_error',
                'Role validation API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Role validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Check if role can manage another role
     * POST /api/roles/can-manage
     */
    public function canManageRole(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['manager_role']) || !isset($input['target_role'])) {
                $this->sendError('Manager role and target role are required', 400, 'MISSING_REQUIRED_FIELDS');
                return;
            }

            $managerRole = $input['manager_role'];
            $targetRole = $input['target_role'];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'role_management_check'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Check role management capability
            $result = $this->rolePermissionManager->canManageRole($managerRole, $targetRole);

            if (isset($result['code']) && $result['code'] === 'INVALID_ROLE') {
                $this->sendError($result['reason'], 400, $result['code']);
                return;
            }

            $this->sendSuccess([
                'can_manage' => $result['can_manage'],
                'manager_role' => $result['manager_role'],
                'manager_level' => $result['manager_level'],
                'target_role' => $result['target_role'],
                'target_level' => $result['target_level'],
                'reason' => $result['reason']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'role_management_check_api_error',
                'Role management check API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Role management check system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Grant permission to user
     * POST /api/permissions/grant
     */
    public function grantPermission(): void
    {
        try {
            // Validate authentication and authorization
            $authResult = $this->validateAuthentication(['admin:permissions']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['user_id']) || !isset($input['permission'])) {
                $this->sendError('User ID and permission are required', 400, 'MISSING_REQUIRED_FIELDS');
                return;
            }

            $userId = (int)$input['user_id'];
            $permission = $input['permission'];
            $context = $input['context'] ?? [];
            $grantedBy = $authResult['user']['id'];

            // Rate limiting for sensitive operations
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $grantedBy,
                RateLimiter::LIMIT_TYPE_USER,
                'permission_management'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Grant permission
            $result = $this->rolePermissionManager->grantPermission($userId, $permission, $grantedBy, $context);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'message' => $result['message'],
                'user_id' => $result['user_id'],
                'permission' => $result['permission'],
                'granted_by' => $result['granted_by'],
                'granted_at' => $result['granted_at']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'grant_permission_api_error',
                'Grant permission API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Grant permission system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Revoke permission from user
     * POST /api/permissions/revoke
     */
    public function revokePermission(): void
    {
        try {
            // Validate authentication and authorization
            $authResult = $this->validateAuthentication(['admin:permissions']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['user_id']) || !isset($input['permission'])) {
                $this->sendError('User ID and permission are required', 400, 'MISSING_REQUIRED_FIELDS');
                return;
            }

            $userId = (int)$input['user_id'];
            $permission = $input['permission'];
            $reason = $input['reason'] ?? '';
            $revokedBy = $authResult['user']['id'];

            // Rate limiting for sensitive operations
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $revokedBy,
                RateLimiter::LIMIT_TYPE_USER,
                'permission_management'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Revoke permission
            $result = $this->rolePermissionManager->revokePermission($userId, $permission, $revokedBy, $reason);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'message' => $result['message'],
                'user_id' => $result['user_id'],
                'permission' => $result['permission'],
                'revoked_by' => $result['revoked_by'],
                'reason' => $result['reason'],
                'revoked_at' => $result['revoked_at']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'revoke_permission_api_error',
                'Revoke permission API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Revoke permission system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get role hierarchy information
     * GET /api/roles/hierarchy
     */
    public function getRoleHierarchy(): void
    {
        try {
            // Validate authentication and authorization
            $authResult = $this->validateAuthentication(['admin:access', 'user:read']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['user']['id'],
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get role hierarchy
            $hierarchy = $this->rolePermissionManager->getRoleHierarchy();

            $this->sendSuccess([
                'hierarchy' => $hierarchy['hierarchy'],
                'roles_by_level' => $hierarchy['roles_by_level'],
                'permission_matrix' => $hierarchy['permission_matrix']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'role_hierarchy_api_error',
                'Role hierarchy API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Role hierarchy system error', 500, 'INTERNAL_ERROR');
        }
    }

    // Private helper methods

    /**
     * Validate authentication and authorization
     */
    private function validateAuthentication(array $requiredScopes = []): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($authHeader)) {
            return [
                'authenticated' => false,
                'message' => 'Missing Authorization header',
                'code' => 'MISSING_AUTH_HEADER',
                'status_code' => 401
            ];
        }

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return [
                'authenticated' => false,
                'message' => 'Invalid Authorization header format',
                'code' => 'INVALID_AUTH_HEADER',
                'status_code' => 401
            ];
        }

        $token = $matches[1];
        $validation = $this->tokenValidator->validateAndGetUser($token, $requiredScopes);

        if (!$validation['valid']) {
            return [
                'authenticated' => false,
                'message' => $validation['message'] ?? 'Token validation failed',
                'code' => $validation['code'] ?? 'INVALID_TOKEN',
                'status_code' => 401
            ];
        }

        return [
            'authenticated' => true,
            'user' => $validation['user'],
            'token_info' => $validation['token_info'],
            'scopes' => $validation['scopes']
        ];
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }

        $decoded = json_decode($input, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Get real IP address
     */
    private function getRealIpAddress(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get HTTP status code from error code
     */
    private function getStatusCodeFromError(string $code): int
    {
        switch ($code) {
            case 'USER_NOT_FOUND':
                return 404;
            case 'USER_INACTIVE':
            case 'INSUFFICIENT_PRIVILEGES':
                return 403;
            case 'INVALID_ROLE':
            case 'INVALID_PERMISSION':
                return 400;
            case 'SYSTEM_ERROR':
            default:
                return 500;
        }
    }

    /**
     * Send success response
     */
    private function sendSuccess(array $data, string $message = 'Success'): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Send error response
     */
    private function sendError(string $message, int $statusCode = 400, string $code = 'ERROR', array $details = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ];
        
        if (!empty($details)) {
            $response['details'] = $details;
        }
        
        echo json_encode($response);
    }
}