<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\TOTPHandler;
use Antinna\Auth\Services\SMSMFAHandler;
use Antinna\Auth\Services\AuditLogger;

/**
 * MFA Manager for policy enforcement and device management
 */
class MFAManager
{
    private App $config;
    private UserRepository $userRepository;
    private TOTPHandler $totpHandler;
    private SMSMFAHandler $smsHandler;
    private AuditLogger $auditLogger;

    // MFA policies by role
    private array $mfaPolicies = [
        'admin' => ['required' => true, 'methods' => ['totp', 'sms']],
        'management_staff' => ['required' => true, 'methods' => ['totp', 'sms']],
        'vendor' => ['required' => false, 'methods' => ['totp', 'sms']],
        'delivery_partner' => ['required' => false, 'methods' => ['sms']],
        'customer' => ['required' => false, 'methods' => ['totp', 'sms']],
        'guest' => ['required' => false, 'methods' => []]
    ];

    public function __construct()
    {
        $this->config = App::getInstance();
        $this->userRepository = new UserRepository();
        $this->totpHandler = new TOTPHandler();
        $this->smsHandler = new SMSMFAHandler();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Check if MFA is required for user based on role and policies
     */
    public function isMFARequired(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'required' => false,
                'error' => 'User not found'
            ];
        }

        $role = $user['role'];
        $policy = $this->mfaPolicies[$role] ?? $this->mfaPolicies['customer'];

        return [
            'required' => $policy['required'],
            'user_has_mfa' => (bool)$user['mfa_enabled'],
            'available_methods' => $policy['methods'],
            'role' => $role,
            'policy_enforced' => $policy['required'] && !$user['mfa_enabled']
        ];
    }

    /**
     * Enforce MFA policy for user
     */
    public function enforceMFAPolicy(int $userId): array
    {
        $mfaCheck = $this->isMFARequired($userId);
        
        if (isset($mfaCheck['error'])) {
            return [
                'success' => false,
                'error' => $mfaCheck['error']
            ];
        }

        if (!$mfaCheck['required']) {
            return [
                'success' => true,
                'message' => 'MFA not required for this user role',
                'action_required' => false
            ];
        }

        if ($mfaCheck['user_has_mfa']) {
            return [
                'success' => true,
                'message' => 'User already has MFA enabled',
                'action_required' => false
            ];
        }

        // MFA is required but not enabled
        $this->auditLogger->log('mfa_policy_violation', 'User requires MFA but does not have it enabled', $userId, 'unknown', 'warning');

        return [
            'success' => false,
            'message' => 'MFA is required for your role but not enabled',
            'action_required' => true,
            'available_methods' => $mfaCheck['available_methods'],
            'code' => 'MFA_REQUIRED_NOT_ENABLED'
        ];
    }

    /**
     * Setup MFA for user with method selection
     */
    public function setupMFA(int $userId, string $method, array $options = []): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Check if method is allowed for user's role
        $mfaCheck = $this->isMFARequired($userId);
        if (!in_array($method, $mfaCheck['available_methods'])) {
            return [
                'success' => false,
                'error' => 'MFA method not allowed for your role',
                'code' => 'MFA_METHOD_NOT_ALLOWED',
                'available_methods' => $mfaCheck['available_methods']
            ];
        }

        // Delegate to appropriate handler
        switch ($method) {
            case 'totp':
                return $this->totpHandler->setupMFA($userId, $method);
            
            case 'sms':
                return $this->smsHandler->setupMFA($userId, $method);
            
            default:
                return [
                    'success' => false,
                    'error' => 'Unsupported MFA method',
                    'code' => 'UNSUPPORTED_MFA_METHOD'
                ];
        }
    }

    /**
     * Verify MFA code
     */
    public function verifyMFA(int $userId, string $code, string $method = null): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        if (!$user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is not enabled for this user',
                'code' => 'MFA_NOT_ENABLED'
            ];
        }

        // If method not specified, try to determine from user's MFA secret
        if (!$method) {
            $method = $this->getUserMFAMethod($userId);
        }

        // Try backup code first if it looks like one
        if (strlen($code) === 8 && ctype_xdigit($code)) {
            if ($this->verifyBackupCode($userId, $code)) {
                return [
                    'success' => true,
                    'method' => 'backup_code',
                    'message' => 'Backup code verified successfully'
                ];
            }
        }

        // Try the specified method
        $verified = false;
        switch ($method) {
            case 'totp':
                $verified = $this->totpHandler->verifyMFA($userId, $code, $method);
                break;
            
            case 'sms':
                $verified = $this->smsHandler->verifyMFA($userId, $code, $method);
                break;
            
            default:
                return [
                    'success' => false,
                    'error' => 'Unknown MFA method',
                    'code' => 'UNKNOWN_MFA_METHOD'
                ];
        }

        if ($verified) {
            return [
                'success' => true,
                'method' => $method,
                'message' => 'MFA verification successful'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Invalid MFA code',
                'code' => 'INVALID_MFA_CODE'
            ];
        }
    }

    /**
     * Disable MFA for user
     */
    public function disableMFA(int $userId, array $options = []): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Check if MFA is required for user's role
        $mfaCheck = $this->isMFARequired($userId);
        if ($mfaCheck['required']) {
            return [
                'success' => false,
                'error' => 'MFA cannot be disabled for your role',
                'code' => 'MFA_REQUIRED_BY_POLICY'
            ];
        }

        if (!$user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is not enabled',
                'code' => 'MFA_NOT_ENABLED'
            ];
        }

        // Determine current MFA method and disable
        $method = $this->getUserMFAMethod($userId);
        
        $success = false;
        switch ($method) {
            case 'totp':
                $success = $this->totpHandler->disableMFA($userId);
                break;
            
            case 'sms':
                $success = $this->smsHandler->disableMFA($userId);
                break;
        }

        if ($success) {
            $this->auditLogger->log('mfa_disabled_by_user', 'MFA disabled by user', $userId);
            return [
                'success' => true,
                'message' => 'MFA has been disabled'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to disable MFA',
                'code' => 'MFA_DISABLE_FAILED'
            ];
        }
    }

    /**
     * Get user's MFA status and available options
     */
    public function getMFAStatus(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        $mfaCheck = $this->isMFARequired($userId);
        $method = $user['mfa_enabled'] ? $this->getUserMFAMethod($userId) : null;
        
        $backupCodes = json_decode($user['backup_codes'] ?? '[]', true);
        
        return [
            'success' => true,
            'mfa_enabled' => (bool)$user['mfa_enabled'],
            'mfa_method' => $method,
            'mfa_required_by_policy' => $mfaCheck['required'],
            'available_methods' => $mfaCheck['available_methods'],
            'backup_codes_remaining' => count($backupCodes),
            'role' => $user['role'],
            'can_disable' => !$mfaCheck['required']
        ];
    }

    /**
     * Update MFA policies (admin only)
     */
    public function updateMFAPolicy(string $role, array $policy): array
    {
        if (!isset($this->mfaPolicies[$role])) {
            return [
                'success' => false,
                'error' => 'Invalid role',
                'code' => 'INVALID_ROLE'
            ];
        }

        // Validate policy structure
        if (!isset($policy['required']) || !isset($policy['methods'])) {
            return [
                'success' => false,
                'error' => 'Invalid policy structure',
                'code' => 'INVALID_POLICY_STRUCTURE'
            ];
        }

        if (!is_bool($policy['required']) || !is_array($policy['methods'])) {
            return [
                'success' => false,
                'error' => 'Invalid policy data types',
                'code' => 'INVALID_POLICY_DATA'
            ];
        }

        // Validate methods
        $validMethods = ['totp', 'sms'];
        foreach ($policy['methods'] as $method) {
            if (!in_array($method, $validMethods)) {
                return [
                    'success' => false,
                    'error' => "Invalid MFA method: {$method}",
                    'code' => 'INVALID_MFA_METHOD'
                ];
            }
        }

        $this->mfaPolicies[$role] = $policy;
        
        $this->auditLogger->log('mfa_policy_updated', "MFA policy updated for role: {$role}", null, 'unknown', 'info', $policy);

        return [
            'success' => true,
            'message' => "MFA policy updated for role: {$role}"
        ];
    }

    /**
     * Get all MFA policies
     */
    public function getMFAPolicies(): array
    {
        return $this->mfaPolicies;
    }

    /**
     * Verify backup code for any MFA method
     */
    public function verifyBackupCode(int $userId, string $code): bool
    {
        $user = $this->userRepository->find($userId);
        if (!$user || !$user['mfa_enabled']) {
            return false;
        }

        $method = $this->getUserMFAMethod($userId);
        
        switch ($method) {
            case 'totp':
                return $this->totpHandler->verifyBackupCode($userId, $code);
            
            case 'sms':
                return $this->smsHandler->verifyBackupCode($userId, $code);
            
            default:
                return false;
        }
    }

    /**
     * Generate new backup codes
     */
    public function regenerateBackupCodes(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        if (!$user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is not enabled'
            ];
        }

        $method = $this->getUserMFAMethod($userId);
        
        switch ($method) {
            case 'totp':
                return $this->totpHandler->regenerateBackupCodes($userId);
            
            case 'sms':
                return $this->smsHandler->generateBackupCodes($userId);
            
            default:
                return [
                    'success' => false,
                    'error' => 'Unknown MFA method'
                ];
        }
    }

    /**
     * Get user's current MFA method
     */
    private function getUserMFAMethod(int $userId): ?string
    {
        $user = $this->userRepository->find($userId);
        if (!$user || !$user['mfa_enabled']) {
            return null;
        }

        // Check if user has TOTP secret
        if (!empty($user['mfa_secret']) && $user['mfa_secret'] !== 'sms') {
            return 'totp';
        }

        // Check if user has phone for SMS
        if (!empty($user['phone']) && $user['phone_verified']) {
            return 'sms';
        }

        return null;
    }

    /**
     * Check if user needs to complete MFA setup
     */
    public function needsMFASetup(int $userId): bool
    {
        $mfaCheck = $this->isMFARequired($userId);
        return $mfaCheck['required'] && !$mfaCheck['user_has_mfa'];
    }

    /**
     * Get MFA statistics for admin dashboard
     */
    public function getMFAStatistics(): array
    {
        try {
            $stats = [
                'total_users' => $this->userRepository->count(['is_active' => true]),
                'mfa_enabled_users' => $this->userRepository->count(['mfa_enabled' => true, 'is_active' => true]),
                'mfa_required_users' => 0,
                'mfa_compliance_rate' => 0,
                'by_role' => [],
                'by_method' => ['totp' => 0, 'sms' => 0]
            ];

            // Calculate by role
            foreach (array_keys($this->mfaPolicies) as $role) {
                $roleUsers = $this->userRepository->findByRole($role, true);
                $roleMFAUsers = array_filter($roleUsers, fn($user) => $user['mfa_enabled']);
                
                $stats['by_role'][$role] = [
                    'total' => count($roleUsers),
                    'mfa_enabled' => count($roleMFAUsers),
                    'required' => $this->mfaPolicies[$role]['required']
                ];

                if ($this->mfaPolicies[$role]['required']) {
                    $stats['mfa_required_users'] += count($roleUsers);
                }
            }

            // Calculate compliance rate
            if ($stats['mfa_required_users'] > 0) {
                $stats['mfa_compliance_rate'] = round(
                    ($stats['mfa_enabled_users'] / $stats['mfa_required_users']) * 100, 
                    2
                );
            }

            return $stats;

        } catch (\Exception $e) {
            return [
                'error' => 'Failed to generate MFA statistics',
                'message' => $e->getMessage()
            ];
        }
    }
}