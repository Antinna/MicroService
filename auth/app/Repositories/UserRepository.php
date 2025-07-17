<?php

namespace Antinna\Auth\Repositories;

/**
 * User repository for database operations
 */
class UserRepository extends BaseRepository
{
    protected function getTableName(): string
    {
        return 'users';
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy(['email' => $email]);
    }

    /**
     * Find user by phone
     */
    public function findByPhone(string $phone): ?array
    {
        return $this->findBy(['phone' => $phone]);
    }

    /**
     * Find active users by role
     */
    public function findByRole(string $role, bool $activeOnly = true): array
    {
        $criteria = ['role' => $role];
        if ($activeOnly) {
            $criteria['is_active'] = 1;
        }
        return $this->findAll($criteria);
    }

    /**
     * Update user's last login timestamp
     */
    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, ['last_login' => date('Y-m-d H:i:s')]);
    }

    /**
     * Enable/disable MFA for user
     */
    public function updateMFAStatus(int $userId, bool $enabled, ?string $secret = null): bool
    {
        $data = ['mfa_enabled' => $enabled];
        if ($secret !== null) {
            $data['mfa_secret'] = $secret;
        }
        return $this->update($userId, $data);
    }

    /**
     * Update user's backup codes
     */
    public function updateBackupCodes(int $userId, array $codes): bool
    {
        return $this->update($userId, ['backup_codes' => json_encode($codes)]);
    }

    /**
     * Verify user's email
     */
    public function verifyEmail(int $userId): bool
    {
        return $this->update($userId, ['email_verified' => true]);
    }

    /**
     * Verify user's phone
     */
    public function verifyPhone(int $userId): bool
    {
        return $this->update($userId, ['phone_verified' => true]);
    }

    /**
     * Activate/deactivate user account
     */
    public function setActiveStatus(int $userId, bool $active): bool
    {
        return $this->update($userId, ['is_active' => $active]);
    }

    /**
     * Get user security settings
     */
    public function getSecuritySettings(int $userId): array
    {
        try {
            $user = $this->find($userId);
            if (!$user || empty($user['security_settings'])) {
                // Return default settings if none exist
                return [
                    'user_id' => $userId,
                    'mfa_enabled' => false,
                    'mfa_method' => null,
                    'notification_on_login' => false,
                    'notification_on_password_change' => true,
                    'notification_on_suspicious_activity' => true,
                    'last_updated' => null
                ];
            }
            
            $settings = json_decode($user['security_settings'], true);
            if (!$settings) {
                return [
                    'user_id' => $userId,
                    'mfa_enabled' => false,
                    'mfa_method' => null,
                    'notification_on_login' => false,
                    'notification_on_password_change' => true,
                    'notification_on_suspicious_activity' => true,
                    'last_updated' => null
                ];
            }
            
            // Ensure all required fields exist
            $defaultSettings = [
                'user_id' => $userId,
                'mfa_enabled' => false,
                'mfa_method' => null,
                'notification_on_login' => false,
                'notification_on_password_change' => true,
                'notification_on_suspicious_activity' => true,
                'last_updated' => null
            ];
            
            return array_merge($defaultSettings, $settings);
            
        } catch (Exception $e) {
            // Return default settings on error
            return [
                'user_id' => $userId,
                'mfa_enabled' => false,
                'mfa_method' => null,
                'notification_on_login' => false,
                'notification_on_password_change' => true,
                'notification_on_suspicious_activity' => true,
                'last_updated' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update user's security settings
     */
    public function updateSecuritySettings(int $userId, array $settings): bool
    {
        // Get current settings
        $currentSettings = $this->getSecuritySettings($userId);
        
        // Merge with new settings
        $updatedSettings = array_merge($currentSettings, $settings);
        $updatedSettings['last_updated'] = date('Y-m-d H:i:s');
        
        return $this->update($userId, ['security_settings' => json_encode($updatedSettings)]);
    }

    /**
     * Get users with MFA enabled
     */
    public function getUsersWithMFA(): array
    {
        return $this->findAll(['mfa_enabled' => true, 'is_active' => true]);
    }

    /**
     * Search users by email pattern
     */
    public function searchByEmail(string $pattern, int $limit = 10): array
    {
        $sql = "SELECT * FROM {$this->getTableName()} WHERE email LIKE ? AND is_active = 1 LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(["%{$pattern}%", $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get active sessions for a user
     */
    public function getActiveSessions(int $userId): array
    {
        try {
            $sql = "SELECT id, user_id, session_token, ip_address, user_agent, 
                           created_at, last_activity, expires_at 
                    FROM sessions 
                    WHERE user_id = ? AND expires_at > NOW() AND is_active = 1 
                    ORDER BY last_activity DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get session by ID
     */
    public function getSessionById(string $sessionId): ?array
    {
        try {
            $sql = "SELECT * FROM sessions WHERE id = ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sessionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Revoke a specific session
     */
    public function revokeSession(string $sessionId): bool
    {
        try {
            $sql = "UPDATE sessions SET is_active = 0, revoked_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$sessionId]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Revoke all sessions except the specified one
     */
    public function revokeAllSessionsExcept(int $userId, string $currentSessionId): int
    {
        try {
            $sql = "UPDATE sessions 
                    SET is_active = 0, revoked_at = NOW() 
                    WHERE user_id = ? AND id != ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $currentSessionId]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }}
