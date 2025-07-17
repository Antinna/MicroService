<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Exception;

/**
 * Admin Authentication Service
 */
class AdminAuthenticator
{
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    // Admin roles hierarchy
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SECURITY_ADMIN = 'security_admin';
    public const ROLE_USER_ADMIN = 'user_admin';

    // Admin permissions
    public const PERMISSION_USER_MANAGEMENT = 'user_management';
    public const PERMISSION_SECURITY_POLICIES = 'security_policies';
    public const PERMISSION_SYSTEM_CONFIG = 'system_config';
    public const PERMISSION_AUDIT_LOGS = 'audit_logs';
    public const PERMISSION_SECURITY_METRICS = 'security_metrics';

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * Authenticate admin user
     */
    public function authenticateAdmin(string $email, string $password, string $ipAddress = 'unknown'): array
    {
        try {
            // Rate limiting for admin login attempts
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $email,
                RateLimiter::LIMIT_TYPE_IP,
                'admin_login'
            );

            if (!$rateLimitResult['allowed']) {
                $this->auditLogger->logSecurityIncident(
                    'admin_login_rate_limited',
                    'Admin login rate limit exceeded',
                    null,
                    AuditLogger::SEVERITY_WARNING,
                    ['email' => $email, 'ip_address' => $ipAddress]
                );

                return [
                    'success' => false,
                    'message' => 'Too many login attempts. Please try again later.',
                    'code' => 'RATE_LIMIT_EXCEEDED'
                ];
            }

            // Find user by email
            $user = $this->userRepository->findByEmail($email);
            if (!$user) {
                $this->logFailedAdminLogin($email, 'User not found', $ipAddress);
                return [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'code' => 'INVALID_CREDENTIALS'
                ];
            }

            // Check if user is active
            if (!$user['is_active']) {
                $this->logFailedAdminLogin($email, 'Account inactive', $ipAddress, $user['id']);
                return [
                    'success' => false,
                    'message' => 'Account is inactive',
                    'code' => 'ACCOUNT_INACTIVE'
                ];
            }

            // Check if user has admin role
            if (!$this->isAdminRole($user['role'])) {
                $this->logFailedAdminLogin($email, 'Insufficient privileges', $ipAddress, $user['id']);
                return [
                    'success' => false,
                    'message' => 'Insufficient privileges',
                    'code' => 'INSUFFICIENT_PRIVILEGES'
                ];
            }

            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                $this->logFailedAdminLogin($email, 'Invalid password', $ipAddress, $user['id']);
                return [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'code' => 'INVALID_CREDENTIALS'
                ];
            }

            // Check if MFA is required for admin
            if ($this->isMFARequired($user)) {
                return [
                    'success' => false,
                    'message' => 'MFA verification required',
                    'code' => 'MFA_REQUIRED',
                    'user_id' => $user['id'],
                    'mfa_methods' => $this->getAvailableMFAMethods($user)
                ];
            }

            // Successful admin authentication
            $this->logSuccessfulAdminLogin($user, $ipAddress);
            
            // Update last login
            $this->userRepository->updateLastLogin($user['id']);

            return [
                'success' => true,
                'message' => 'Admin authentication successful',
                'user' => $this->sanitizeAdminUser($user),
                'permissions' => $this->getAdminPermissions($user['role']),
                'session_data' => [
                    'user_id' => $user['id'],
                    'role' => $user['role'],
                    'permissions' => $this->getAdminPermissions($user['role'])
                ]
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'admin_auth_error',
                'Admin authentication error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['email' => $email, 'ip_address' => $ipAddress]
            );

            return [
                'success' => false,
                'message' => 'Authentication system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Verify admin session and permissions
     */
    public function verifyAdminSession(int $userId, string $requiredPermission = null): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'valid' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Check if user is active
            if (!$user['is_active']) {
                return [
                    'valid' => false,
                    'message' => 'Account is inactive',
                    'code' => 'ACCOUNT_INACTIVE'
                ];
            }

            // Check if user has admin role
            if (!$this->isAdminRole($user['role'])) {
                return [
                    'valid' => false,
                    'message' => 'Insufficient privileges',
                    'code' => 'INSUFFICIENT_PRIVILEGES'
                ];
            }

            // Check specific permission if required
            if ($requiredPermission && !$this->hasPermission($user['role'], $requiredPermission)) {
                return [
                    'valid' => false,
                    'message' => 'Permission denied',
                    'code' => 'PERMISSION_DENIED',
                    'required_permission' => $requiredPermission
                ];
            }

            return [
                'valid' => true,
                'user' => $this->sanitizeAdminUser($user),
                'role' => $user['role'],
                'permissions' => $this->getAdminPermissions($user['role'])
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'admin_session_verify_error',
                'Admin session verification error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'valid' => false,
                'message' => 'Session verification error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Check if role is an admin role
     */
    public function isAdminRole(string $role): bool
    {
        return in_array($role, [
            self::ROLE_ADMIN,
            self::ROLE_SUPER_ADMIN,
            self::ROLE_SECURITY_ADMIN,
            self::ROLE_USER_ADMIN
        ]);
    }

    /**
     * Get admin permissions for role
     */
    public function getAdminPermissions(string $role): array
    {
        $permissions = [];

        switch ($role) {
            case self::ROLE_SUPER_ADMIN:
                $permissions = [
                    self::PERMISSION_USER_MANAGEMENT,
                    self::PERMISSION_SECURITY_POLICIES,
                    self::PERMISSION_SYSTEM_CONFIG,
                    self::PERMISSION_AUDIT_LOGS,
                    self::PERMISSION_SECURITY_METRICS
                ];
                break;

            case self::ROLE_ADMIN:
                $permissions = [
                    self::PERMISSION_USER_MANAGEMENT,
                    self::PERMISSION_SECURITY_POLICIES,
                    self::PERMISSION_AUDIT_LOGS,
                    self::PERMISSION_SECURITY_METRICS
                ];
                break;

            case self::ROLE_SECURITY_ADMIN:
                $permissions = [
                    self::PERMISSION_SECURITY_POLICIES,
                    self::PERMISSION_AUDIT_LOGS,
                    self::PERMISSION_SECURITY_METRICS
                ];
                break;

            case self::ROLE_USER_ADMIN:
                $permissions = [
                    self::PERMISSION_USER_MANAGEMENT,
                    self::PERMISSION_AUDIT_LOGS
                ];
                break;
        }

        return $permissions;
    }

    /**
     * Check if role has specific permission
     */
    public function hasPermission(string $role, string $permission): bool
    {
        $permissions = $this->getAdminPermissions($role);
        return in_array($permission, $permissions);
    }

    /**
     * Create admin session with enhanced security
     */
    public function createAdminSession(int $userId, string $ipAddress, string $userAgent): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user || !$this->isAdminRole($user['role'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid admin user',
                    'code' => 'INVALID_ADMIN_USER'
                ];
            }

            // Generate secure session ID
            $sessionId = bin2hex(random_bytes(32));
            $sessionToken = bin2hex(random_bytes(64));
            
            // Set shorter session duration for admin users
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            // Create session record
            $sessionData = [
                'id' => $sessionId,
                'user_id' => $userId,
                'session_token' => hash('sha256', $sessionToken),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'is_admin_session' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'last_activity' => date('Y-m-d H:i:s'),
                'expires_at' => $expiresAt,
                'is_active' => true
            ];

            // Insert session (this would need actual database implementation)
            // For now, we'll simulate success
            $sessionCreated = true;

            if (!$sessionCreated) {
                return [
                    'success' => false,
                    'message' => 'Failed to create admin session',
                    'code' => 'SESSION_CREATE_FAILED'
                ];
            }

            // Log admin session creation
            $this->auditLogger->logAdminAction(
                'admin_session_created',
                'Admin session created',
                $userId,
                null,
                [
                    'session_id' => $sessionId,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'expires_at' => $expiresAt
                ]
            );

            return [
                'success' => true,
                'session_id' => $sessionId,
                'session_token' => $sessionToken,
                'expires_at' => $expiresAt,
                'user' => $this->sanitizeAdminUser($user),
                'permissions' => $this->getAdminPermissions($user['role'])
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'admin_session_create_error',
                'Admin session creation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'success' => false,
                'message' => 'Session creation error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Validate admin session token
     */
    public function validateAdminSession(string $sessionId, string $sessionToken): array
    {
        try {
            // This would query the sessions table for admin sessions
            // For now, we'll simulate the validation
            
            // Check if session exists and is active
            $session = $this->getAdminSession($sessionId);
            if (!$session) {
                return [
                    'valid' => false,
                    'message' => 'Session not found',
                    'code' => 'SESSION_NOT_FOUND'
                ];
            }

            // Verify session token
            if (!hash_equals($session['session_token'], hash('sha256', $sessionToken))) {
                return [
                    'valid' => false,
                    'message' => 'Invalid session token',
                    'code' => 'INVALID_SESSION_TOKEN'
                ];
            }

            // Check if session is expired
            if (strtotime($session['expires_at']) < time()) {
                return [
                    'valid' => false,
                    'message' => 'Session expired',
                    'code' => 'SESSION_EXPIRED'
                ];
            }

            // Update last activity
            $this->updateSessionActivity($sessionId);

            // Get user information
            $user = $this->userRepository->find($session['user_id']);
            if (!$user || !$this->isAdminRole($user['role'])) {
                return [
                    'valid' => false,
                    'message' => 'Invalid admin user',
                    'code' => 'INVALID_ADMIN_USER'
                ];
            }

            return [
                'valid' => true,
                'session' => $session,
                'user' => $this->sanitizeAdminUser($user),
                'permissions' => $this->getAdminPermissions($user['role'])
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'admin_session_validate_error',
                'Admin session validation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['session_id' => $sessionId]
            );

            return [
                'valid' => false,
                'message' => 'Session validation error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    // Private helper methods

    /**
     * Log failed admin login attempt
     */
    private function logFailedAdminLogin(string $email, string $reason, string $ipAddress, ?int $userId = null): void
    {
        $this->auditLogger->logSecurityIncident(
            'admin_login_failed',
            "Failed admin login attempt: $reason",
            $userId,
            AuditLogger::SEVERITY_WARNING,
            [
                'email' => $email,
                'reason' => $reason,
                'ip_address' => $ipAddress,
                'attempt_time' => date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Log successful admin login
     */
    private function logSuccessfulAdminLogin(array $user, string $ipAddress): void
    {
        $this->auditLogger->logAuthentication(
            AuditLogger::EVENT_LOGIN_SUCCESS,
            'Admin login successful',
            $user['id'],
            'admin_password',
            true,
            [
                'admin_role' => $user['role'],
                'ip_address' => $ipAddress,
                'login_time' => date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Check if MFA is required for admin user
     */
    private function isMFARequired(array $user): bool
    {
        // Admin users should always have MFA enabled
        $securitySettings = $this->userRepository->getSecuritySettings($user['id']);
        return !($securitySettings['mfa_enabled'] ?? false);
    }

    /**
     * Get available MFA methods for user
     */
    private function getAvailableMFAMethods(array $user): array
    {
        // This would check what MFA methods are available for the user
        return ['totp', 'sms']; // Placeholder
    }

    /**
     * Sanitize admin user data
     */
    private function sanitizeAdminUser(array $user): array
    {
        unset($user['password_hash']);
        unset($user['mfa_secret']);
        unset($user['backup_codes']);
        return $user;
    }

    /**
     * Get admin session (placeholder - would query database)
     */
    private function getAdminSession(string $sessionId): ?array
    {
        // This would query the sessions table
        return null; // Placeholder
    }

    /**
     * Update session activity (placeholder - would update database)
     */
    private function updateSessionActivity(string $sessionId): bool
    {
        // This would update the last_activity timestamp
        return true; // Placeholder
    }
}