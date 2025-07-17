<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\EmailService;
use Antinna\Auth\Services\SecurityMonitor;
use Exception;
use PDO;

/**
 * User Profile Manager for account settings and preferences
 */
class UserProfileManager
{
    private PDO $db;
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private EmailService $emailService;
    private SecurityMonitor $securityMonitor;
    
    // Profile field types
    public const FIELD_TYPE_STRING = 'string';
    public const FIELD_TYPE_EMAIL = 'email';
    public const FIELD_TYPE_PHONE = 'phone';
    public const FIELD_TYPE_DATE = 'date';
    public const FIELD_TYPE_BOOLEAN = 'boolean';
    public const FIELD_TYPE_JSON = 'json';
    
    // Privacy levels
    public const PRIVACY_PUBLIC = 'public';
    public const PRIVACY_PRIVATE = 'private';
    public const PRIVACY_FRIENDS = 'friends';
    
    // Account status
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->emailService = new EmailService();
        $this->securityMonitor = new SecurityMonitor();
    }

    /**
     * Get user profile information
     */
    public function getProfile(int $userId, ?int $requestingUserId = null): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Get profile data
            $profileData = $this->getProfileData($userId);
            
            // Get user preferences
            $preferences = $this->getUserPreferences($userId);
            
            // Get security settings
            $securitySettings = $this->getSecuritySettings($userId);
            
            // Filter sensitive data based on privacy settings and requesting user
            $filteredProfile = $this->filterProfileData($profileData, $requestingUserId, $userId);
            
            $profile = [
                'user_id' => $userId,
                'email' => $user['email'],
                'is_verified' => (bool)$user['is_verified'],
                'account_status' => $user['status'] ?? self::STATUS_ACTIVE,
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login_at'] ?? null,
                'profile_data' => $filteredProfile,
                'preferences' => $preferences,
                'security_settings' => $securitySettings
            ];

            $this->auditLogger->logDataAccess(
                'user_profile',
                'read',
                $requestingUserId,
                true,
                ['target_user_id' => $userId]
            );

            return [
                'success' => true,
                'profile' => $profile
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'profile_get_error',
                'Profile retrieval error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'success' => false,
                'error' => 'Failed to retrieve profile',
                'code' => 'PROFILE_ERROR'
            ];
        }
    }

    /**
     * Update user profile information
     */
    public function updateProfile(int $userId, array $profileData, ?int $requestingUserId = null): array
    {
        try {
            // Verify user exists
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Check permissions
            if ($requestingUserId && $requestingUserId !== $userId && !$this->isAdmin($requestingUserId)) {
                return [
                    'success' => false,
                    'error' => 'Insufficient permissions',
                    'code' => 'INSUFFICIENT_PERMISSIONS'
                ];
            }

            // Validate profile data
            $validationResult = $this->validateProfileData($profileData);
            if (!$validationResult['valid']) {
                return [
                    'success' => false,
                    'error' => 'Invalid profile data',
                    'code' => 'VALIDATION_ERROR',
                    'validation_errors' => $validationResult['errors']
                ];
            }

            // Get current profile for comparison
            $currentProfile = $this->getProfileData($userId);
            
            // Update profile data
            $updateResult = $this->updateProfileData($userId, $profileData);
            if (!$updateResult) {
                return [
                    'success' => false,
                    'error' => 'Failed to update profile',
                    'code' => 'UPDATE_FAILED'
                ];
            }

            // Log the changes
            $changes = $this->getProfileChanges($currentProfile, $profileData);
            $this->auditLogger->log(
                'profile_updated',
                'User profile updated',
                $userId,
                'auto',
                AuditLogger::SEVERITY_INFO,
                [
                    'changes' => $changes,
                    'updated_by' => $requestingUserId
                ]
            );

            // Send notification if email was changed
            if (isset($changes['email'])) {
                $this->handleEmailChange($userId, $changes['email']['old'], $changes['email']['new']);
            }

            return [
                'success' => true,
                'message' => 'Profile updated successfully',
                'changes' => $changes
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'profile_update_error',
                'Profile update error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'success' => false,
                'error' => 'Failed to update profile',
                'code' => 'UPDATE_ERROR'
            ];
        }
    }

    /**
     * Change user password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Verify current password
            if (!password_verify($currentPassword, $user['password_hash'])) {
                $this->auditLogger->log(
                    AuditLogger::EVENT_PASSWORD_CHANGE,
                    'Failed password change attempt - incorrect current password',
                    $userId,
                    'auto',
                    AuditLogger::SEVERITY_WARNING
                );

                return [
                    'success' => false,
                    'error' => 'Current password is incorrect',
                    'code' => 'INVALID_PASSWORD'
                ];
            }

            // Validate new password
            $passwordValidation = $this->validatePassword($newPassword);
            if (!$passwordValidation['valid']) {
                return [
                    'success' => false,
                    'error' => 'New password does not meet requirements',
                    'code' => 'WEAK_PASSWORD',
                    'requirements' => $passwordValidation['requirements']
                ];
            }

            // Check if new password is different from current
            if (password_verify($newPassword, $user['password_hash'])) {
                return [
                    'success' => false,
                    'error' => 'New password must be different from current password',
                    'code' => 'SAME_PASSWORD'
                ];
            }

            // Update password
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateResult = $this->userRepository->updatePassword($userId, $newPasswordHash);

            if (!$updateResult) {
                return [
                    'success' => false,
                    'error' => 'Failed to update password',
                    'code' => 'UPDATE_FAILED'
                ];
            }

            // Log successful password change
            $this->auditLogger->log(
                AuditLogger::EVENT_PASSWORD_CHANGE,
                'Password changed successfully',
                $userId,
                'auto',
                AuditLogger::SEVERITY_INFO
            );

            // Send security notification
            $this->emailService->sendSecurityAlertEmail(
                $user['email'],
                'password_change',
                [
                    'user_name' => $user['email'],
                    'change_time' => date('Y-m-d H:i:s'),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
                ]
            );

            // Invalidate all existing sessions except current
            $this->invalidateOtherSessions($userId);

            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'password_change_error',
                'Password change error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'success' => false,
                'error' => 'Failed to change password',
                'code' => 'CHANGE_ERROR'
            ];
        }
    }

    /**
     * Update user preferences
     */
    public function updatePreferences(int $userId, array $preferences): array
    {
        try {
            // Validate preferences
            $validationResult = $this->validatePreferences($preferences);
            if (!$validationResult['valid']) {
                return [
                    'success' => false,
                    'error' => 'Invalid preferences',
                    'code' => 'VALIDATION_ERROR',
                    'validation_errors' => $validationResult['errors']
                ];
            }

            // Update preferences
            $updateResult = $this->updateUserPreferences($userId, $preferences);
            if (!$updateResult) {
                return [
                    'success' => false,
                    'error' => 'Failed to update preferences',
                    'code' => 'UPDATE_FAILED'
                ];
            }

            $this->auditLogger->log(
                'preferences_updated',
                'User preferences updated',
                $userId,
                'auto',
                AuditLogger::SEVERITY_INFO,
                ['preferences' => $preferences]
            );

            return [
                'success' => true,
                'message' => 'Preferences updated successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to update preferences',
                'code' => 'UPDATE_ERROR'
            ];
        }
    }

    /**
     * Deactivate user account
     */
    public function deactivateAccount(int $userId, string $reason = '', ?string $password = null): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Verify password if provided
            if ($password && !password_verify($password, $user['password_hash'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid password',
                    'code' => 'INVALID_PASSWORD'
                ];
            }

            // Update account status
            $updateResult = $this->updateAccountStatus($userId, self::STATUS_DEACTIVATED, $reason);
            if (!$updateResult) {
                return [
                    'success' => false,
                    'error' => 'Failed to deactivate account',
                    'code' => 'DEACTIVATION_FAILED'
                ];
            }

            // Invalidate all sessions
            $this->invalidateAllSessions($userId);

            // Log deactivation
            $this->auditLogger->log(
                'account_deactivated',
                'User account deactivated',
                $userId,
                'auto',
                AuditLogger::SEVERITY_NOTICE,
                ['reason' => $reason]
            );

            // Send confirmation email
            $this->emailService->sendSecurityAlertEmail(
                $user['email'],
                'account_deactivated',
                [
                    'user_name' => $user['email'],
                    'deactivation_time' => date('Y-m-d H:i:s'),
                    'reason' => $reason
                ]
            );

            return [
                'success' => true,
                'message' => 'Account deactivated successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to deactivate account',
                'code' => 'DEACTIVATION_ERROR'
            ];
        }
    }

    /**
     * Reactivate user account
     */
    public function reactivateAccount(int $userId, ?int $adminUserId = null): array
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            if ($user['status'] !== self::STATUS_DEACTIVATED) {
                return [
                    'success' => false,
                    'error' => 'Account is not deactivated',
                    'code' => 'INVALID_STATUS'
                ];
            }

            // Update account status
            $updateResult = $this->updateAccountStatus($userId, self::STATUS_ACTIVE, 'Account reactivated');
            if (!$updateResult) {
                return [
                    'success' => false,
                    'error' => 'Failed to reactivate account',
                    'code' => 'REACTIVATION_FAILED'
                ];
            }

            // Log reactivation
            $this->auditLogger->logAdminAction(
                'account_reactivated',
                'User account reactivated',
                $adminUserId ?? $userId,
                $userId,
                ['reactivated_by' => $adminUserId ? 'admin' : 'user']
            );

            return [
                'success' => true,
                'message' => 'Account reactivated successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to reactivate account',
                'code' => 'REACTIVATION_ERROR'
            ];
        }
    }

    /**
     * Get user's security settings
     */
    public function getSecuritySettings(int $userId): array
    {
        try {
            $sql = "
                SELECT 
                    two_factor_enabled,
                    login_notifications,
                    security_alerts,
                    session_timeout,
                    allowed_ips,
                    last_password_change,
                    failed_login_count,
                    account_locked_until
                FROM user_security_settings 
                WHERE user_id = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$settings) {
                // Create default settings
                $this->createDefaultSecuritySettings($userId);
                return $this->getDefaultSecuritySettings();
            }
            
            return [
                'two_factor_enabled' => (bool)$settings['two_factor_enabled'],
                'login_notifications' => (bool)$settings['login_notifications'],
                'security_alerts' => (bool)$settings['security_alerts'],
                'session_timeout' => (int)$settings['session_timeout'],
                'allowed_ips' => $settings['allowed_ips'] ? json_decode($settings['allowed_ips'], true) : [],
                'last_password_change' => $settings['last_password_change'],
                'failed_login_count' => (int)$settings['failed_login_count'],
                'account_locked' => $settings['account_locked_until'] && strtotime($settings['account_locked_until']) > time()
            ];
            
        } catch (Exception $e) {
            return $this->getDefaultSecuritySettings();
        }
    }

    /**
     * Update security settings
     */
    public function updateSecuritySettings(int $userId, array $settings): array
    {
        try {
            $allowedSettings = [
                'login_notifications',
                'security_alerts',
                'session_timeout',
                'allowed_ips'
            ];
            
            $updateData = [];
            foreach ($allowedSettings as $setting) {
                if (isset($settings[$setting])) {
                    $updateData[$setting] = $settings[$setting];
                }
            }
            
            if (empty($updateData)) {
                return [
                    'success' => false,
                    'error' => 'No valid settings provided',
                    'code' => 'NO_SETTINGS'
                ];
            }
            
            // Update settings
            $result = $this->updateUserSecuritySettings($userId, $updateData);
            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'Failed to update security settings',
                    'code' => 'UPDATE_FAILED'
                ];
            }
            
            $this->auditLogger->log(
                'security_settings_updated',
                'Security settings updated',
                $userId,
                'auto',
                AuditLogger::SEVERITY_INFO,
                ['settings' => $updateData]
            );
            
            return [
                'success' => true,
                'message' => 'Security settings updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to update security settings',
                'code' => 'UPDATE_ERROR'
            ];
        }
    }

    // Private helper methods

    private function getProfileData(int $userId): array
    {
        try {
            $sql = "SELECT * FROM user_profiles WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $profile ? json_decode($profile['profile_data'], true) : [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function getUserPreferences(int $userId): array
    {
        try {
            $sql = "SELECT preferences FROM user_preferences WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $preferences = $stmt->fetchColumn();
            
            return $preferences ? json_decode($preferences, true) : $this->getDefaultPreferences();
        } catch (Exception $e) {
            return $this->getDefaultPreferences();
        }
    }

    private function filterProfileData(array $profileData, ?int $requestingUserId, int $targetUserId): array
    {
        // If requesting own profile or admin, return all data
        if (!$requestingUserId || $requestingUserId === $targetUserId || $this->isAdmin($requestingUserId)) {
            return $profileData;
        }
        
        // Filter based on privacy settings
        $filtered = [];
        foreach ($profileData as $key => $value) {
            $privacy = $value['privacy'] ?? self::PRIVACY_PUBLIC;
            if ($privacy === self::PRIVACY_PUBLIC) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }

    private function validateProfileData(array $profileData): array
    {
        $errors = [];
        $allowedFields = ['first_name', 'last_name', 'phone', 'date_of_birth', 'bio', 'avatar_url'];
        
        foreach ($profileData as $field => $value) {
            if (!in_array($field, $allowedFields)) {
                $errors[] = "Field '$field' is not allowed";
                continue;
            }
            
            // Validate specific fields
            switch ($field) {
                case 'phone':
                    if (!empty($value) && !preg_match('/^\+?[1-9]\d{1,14}$/', $value)) {
                        $errors[] = 'Invalid phone number format';
                    }
                    break;
                case 'date_of_birth':
                    if (!empty($value) && !strtotime($value)) {
                        $errors[] = 'Invalid date format for date_of_birth';
                    }
                    break;
                case 'bio':
                    if (strlen($value) > 500) {
                        $errors[] = 'Bio must be 500 characters or less';
                    }
                    break;
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function validatePassword(string $password): array
    {
        $requirements = [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_special' => true
        ];
        
        $errors = [];
        
        if (strlen($password) < $requirements['min_length']) {
            $errors[] = "Password must be at least {$requirements['min_length']} characters long";
        }
        
        if ($requirements['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if ($requirements['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if ($requirements['require_numbers'] && !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if ($requirements['require_special'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }
        
        return [
            'valid' => empty($errors),
            'requirements' => $requirements,
            'errors' => $errors
        ];
    }

    private function validatePreferences(array $preferences): array
    {
        $allowedPreferences = [
            'language', 'timezone', 'theme', 'notifications',
            'email_frequency', 'privacy_level'
        ];
        
        $errors = [];
        foreach ($preferences as $key => $value) {
            if (!in_array($key, $allowedPreferences)) {
                $errors[] = "Preference '$key' is not allowed";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function updateProfileData(int $userId, array $profileData): bool
    {
        try {
            $sql = "
                INSERT INTO user_profiles (user_id, profile_data, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE profile_data = VALUES(profile_data), updated_at = NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$userId, json_encode($profileData)]);
        } catch (Exception $e) {
            return false;
        }
    }

    private function updateUserPreferences(int $userId, array $preferences): bool
    {
        try {
            $sql = "
                INSERT INTO user_preferences (user_id, preferences, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE preferences = VALUES(preferences), updated_at = NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$userId, json_encode($preferences)]);
        } catch (Exception $e) {
            return false;
        }
    }

    private function updateAccountStatus(int $userId, string $status, string $reason = ''): bool
    {
        try {
            $sql = "UPDATE users SET status = ?, status_reason = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$status, $reason, $userId]);
        } catch (Exception $e) {
            return false;
        }
    }

    private function getProfileChanges(array $oldProfile, array $newProfile): array
    {
        $changes = [];
        
        foreach ($newProfile as $key => $newValue) {
            $oldValue = $oldProfile[$key] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }
        
        return $changes;
    }

    private function handleEmailChange(int $userId, string $oldEmail, string $newEmail): void
    {
        // Send notification to both old and new email addresses
        $this->emailService->sendSecurityAlertEmail($oldEmail, 'email_change', [
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
            'change_time' => date('Y-m-d H:i:s')
        ]);
        
        $this->emailService->sendSecurityAlertEmail($newEmail, 'email_change', [
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
            'change_time' => date('Y-m-d H:i:s')
        ]);
    }

    private function invalidateOtherSessions(int $userId): void
    {
        try {
            $currentSessionId = session_id();
            $sql = "DELETE FROM sessions WHERE user_id = ? AND session_id != ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $currentSessionId]);
        } catch (Exception $e) {
            // Log error but don't fail the operation
        }
    }

    private function invalidateAllSessions(int $userId): void
    {
        try {
            $sql = "DELETE FROM sessions WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            // Log error but don't fail the operation
        }
    }

    private function isAdmin(int $userId): bool
    {
        // Implementation would check user roles/permissions
        return false; // Placeholder
    }

    private function getDefaultPreferences(): array
    {
        return [
            'language' => 'en',
            'timezone' => 'UTC',
            'theme' => 'light',
            'notifications' => true,
            'email_frequency' => 'daily',
            'privacy_level' => self::PRIVACY_PRIVATE
        ];
    }

    private function getDefaultSecuritySettings(): array
    {
        return [
            'two_factor_enabled' => false,
            'login_notifications' => true,
            'security_alerts' => true,
            'session_timeout' => 3600,
            'allowed_ips' => [],
            'last_password_change' => null,
            'failed_login_count' => 0,
            'account_locked' => false
        ];
    }

    private function createDefaultSecuritySettings(int $userId): void
    {
        try {
            $sql = "
                INSERT INTO user_security_settings (
                    user_id, two_factor_enabled, login_notifications, 
                    security_alerts, session_timeout, created_at
                ) VALUES (?, 0, 1, 1, 3600, NOW())
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            // Ignore errors for default settings creation
        }
    }

    private function updateUserSecuritySettings(int $userId, array $settings): bool
    {
        try {
            $setParts = [];
            $params = [];
            
            foreach ($settings as $key => $value) {
                if ($key === 'allowed_ips') {
                    $setParts[] = "$key = ?";
                    $params[] = json_encode($value);
                } else {
                    $setParts[] = "$key = ?";
                    $params[] = $value;
                }
            }
            
            $params[] = $userId;
            
            $sql = "UPDATE user_security_settings SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (Exception $e) {
            return false;
        }
    }
}