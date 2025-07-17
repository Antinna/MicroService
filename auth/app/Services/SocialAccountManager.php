<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\OAuth2Handler;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Database\Connection;
use PDO;
use Exception;

/**
 * Social Account Manager for linking and managing social accounts
 */
class SocialAccountManager
{
    private PDO $db;
    private UserRepository $userRepository;
    private OAuth2Handler $oauth2Handler;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->userRepository = new UserRepository();
        $this->oauth2Handler = new OAuth2Handler();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Link social account to user
     */
    public function linkAccount(int $userId, string $provider, array $providerData): array
    {
        // Verify user exists
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Verify provider is supported
        if (!in_array($provider, $this->oauth2Handler->getSupportedProviders())) {
            return [
                'success' => false,
                'error' => 'Unsupported social provider',
                'code' => 'UNSUPPORTED_PROVIDER'
            ];
        }

        // Validate provider data
        if (empty($providerData['provider_id'])) {
            return [
                'success' => false,
                'error' => 'Provider ID is required',
                'code' => 'PROVIDER_ID_REQUIRED'
            ];
        }

        try {
            // Check if this social account is already linked to another user
            $existingAccount = $this->findSocialAccount($provider, $providerData['provider_id']);
            if ($existingAccount && $existingAccount['user_id'] !== $userId) {
                return [
                    'success' => false,
                    'error' => 'This social account is already linked to another user',
                    'code' => 'ACCOUNT_ALREADY_LINKED'
                ];
            }

            // Check if user already has this provider linked
            $userSocialAccount = $this->getUserSocialAccount($userId, $provider);
            if ($userSocialAccount) {
                return [
                    'success' => false,
                    'error' => 'User already has this social provider linked',
                    'code' => 'PROVIDER_ALREADY_LINKED'
                ];
            }

            // Create social account link
            $socialAccountData = [
                'user_id' => $userId,
                'provider' => $provider,
                'provider_id' => $providerData['provider_id'],
                'provider_email' => $providerData['email'] ?? null,
                'provider_data' => json_encode($providerData)
            ];

            $socialAccountId = $this->createSocialAccount($socialAccountData);

            if ($socialAccountId) {
                $this->auditLogger->log('social_account_linked', "Social account linked: {$provider}", $userId);
                
                return [
                    'success' => true,
                    'social_account_id' => $socialAccountId,
                    'message' => 'Social account linked successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to link social account',
                    'code' => 'LINK_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('social_account_link_error', "Social account link error: " . $e->getMessage(), $userId, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Failed to link social account',
                'code' => 'LINK_ERROR'
            ];
        }
    }

    /**
     * Unlink social account from user
     */
    public function unlinkAccount(int $userId, string $provider): array
    {
        // Verify user exists
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        try {
            // Find user's social account for this provider
            $socialAccount = $this->getUserSocialAccount($userId, $provider);
            if (!$socialAccount) {
                return [
                    'success' => false,
                    'error' => 'Social account not found',
                    'code' => 'SOCIAL_ACCOUNT_NOT_FOUND'
                ];
            }

            // Check if user has other authentication methods
            $hasPassword = !empty($user['password_hash']);
            $otherSocialAccounts = $this->getUserSocialAccounts($userId);
            $hasOtherSocialAccounts = count($otherSocialAccounts) > 1;

            if (!$hasPassword && !$hasOtherSocialAccounts) {
                return [
                    'success' => false,
                    'error' => 'Cannot unlink the only authentication method. Please set a password first.',
                    'code' => 'LAST_AUTH_METHOD'
                ];
            }

            // Delete social account
            $success = $this->deleteSocialAccount($socialAccount['id']);

            if ($success) {
                $this->auditLogger->log('social_account_unlinked', "Social account unlinked: {$provider}", $userId);
                
                return [
                    'success' => true,
                    'message' => 'Social account unlinked successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to unlink social account',
                    'code' => 'UNLINK_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('social_account_unlink_error', "Social account unlink error: " . $e->getMessage(), $userId, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Failed to unlink social account',
                'code' => 'UNLINK_ERROR'
            ];
        }
    }

    /**
     * Authenticate user with social account
     */
    public function authenticateWithSocial(string $provider, array $providerData): array
    {
        if (empty($providerData['provider_id'])) {
            return [
                'success' => false,
                'error' => 'Provider ID is required',
                'code' => 'PROVIDER_ID_REQUIRED'
            ];
        }

        try {
            // Find existing social account
            $socialAccount = $this->findSocialAccount($provider, $providerData['provider_id']);
            
            if ($socialAccount) {
                // User exists, authenticate them
                $user = $this->userRepository->find($socialAccount['user_id']);
                
                if (!$user || !$user['is_active']) {
                    return [
                        'success' => false,
                        'error' => 'User account is inactive',
                        'code' => 'ACCOUNT_INACTIVE'
                    ];
                }

                // Update social account data
                $this->updateSocialAccount($socialAccount['id'], [
                    'provider_email' => $providerData['email'] ?? null,
                    'provider_data' => json_encode($providerData)
                ]);

                $this->auditLogger->log('social_auth_success', "Social authentication successful: {$provider}", $user['id']);

                return [
                    'success' => true,
                    'user' => $user,
                    'social_account' => $socialAccount,
                    'action' => 'login'
                ];

            } else {
                // New social account - check if we can link to existing user by email
                if (!empty($providerData['email'])) {
                    $existingUser = $this->userRepository->findByEmail($providerData['email']);
                    
                    if ($existingUser) {
                        // Link social account to existing user
                        $linkResult = $this->linkAccount($existingUser['id'], $provider, $providerData);
                        
                        if ($linkResult['success']) {
                            $this->auditLogger->log('social_auth_linked', "Social account linked during auth: {$provider}", $existingUser['id']);
                            
                            return [
                                'success' => true,
                                'user' => $existingUser,
                                'social_account_id' => $linkResult['social_account_id'],
                                'action' => 'linked_and_login'
                            ];
                        }
                    }
                }

                // Create new user account
                return $this->createUserFromSocial($provider, $providerData);
            }

        } catch (Exception $e) {
            $this->auditLogger->log('social_auth_error', "Social authentication error: " . $e->getMessage(), null, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Social authentication failed',
                'code' => 'SOCIAL_AUTH_ERROR'
            ];
        }
    }

    /**
     * Get user's social accounts
     */
    public function getUserSocialAccounts(int $userId): array
    {
        try {
            $sql = "SELECT * FROM social_accounts WHERE user_id = ? ORDER BY linked_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            
            $accounts = $stmt->fetchAll();
            
            // Decode provider_data for each account
            foreach ($accounts as &$account) {
                $account['provider_data'] = json_decode($account['provider_data'] ?? '{}', true);
            }
            
            return $accounts;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get social account statistics
     */
    public function getSocialAccountStatistics(): array
    {
        try {
            $sql = "
                SELECT 
                    provider,
                    COUNT(*) as account_count,
                    COUNT(DISTINCT user_id) as unique_users
                FROM social_accounts 
                GROUP BY provider
                ORDER BY account_count DESC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            $providerStats = $stmt->fetchAll();

            $sql = "
                SELECT 
                    COUNT(*) as total_social_accounts,
                    COUNT(DISTINCT user_id) as users_with_social,
                    (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users
                FROM social_accounts
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            $overallStats = $stmt->fetch();

            return [
                'overall' => $overallStats,
                'by_provider' => $providerStats
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to get social account statistics',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync user profile from social provider
     */
    public function syncUserProfile(int $userId, string $provider): array
    {
        $socialAccount = $this->getUserSocialAccount($userId, $provider);
        if (!$socialAccount) {
            return [
                'success' => false,
                'error' => 'Social account not found'
            ];
        }

        // This would require storing access tokens and implementing refresh logic
        // For now, return a placeholder response
        return [
            'success' => false,
            'error' => 'Profile sync not implemented yet'
        ];
    }

    /**
     * Find social account by provider and provider ID
     */
    private function findSocialAccount(string $provider, string $providerId): ?array
    {
        try {
            $sql = "SELECT * FROM social_accounts WHERE provider = ? AND provider_id = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$provider, $providerId]);
            
            $account = $stmt->fetch();
            if ($account) {
                $account['provider_data'] = json_decode($account['provider_data'] ?? '{}', true);
            }
            
            return $account ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get user's social account for specific provider
     */
    private function getUserSocialAccount(int $userId, string $provider): ?array
    {
        try {
            $sql = "SELECT * FROM social_accounts WHERE user_id = ? AND provider = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $provider]);
            
            $account = $stmt->fetch();
            if ($account) {
                $account['provider_data'] = json_decode($account['provider_data'] ?? '{}', true);
            }
            
            return $account ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create social account record
     */
    private function createSocialAccount(array $data): ?int
    {
        try {
            $sql = "
                INSERT INTO social_accounts (user_id, provider, provider_id, provider_email, provider_data)
                VALUES (?, ?, ?, ?, ?)
            ";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $data['user_id'],
                $data['provider'],
                $data['provider_id'],
                $data['provider_email'],
                $data['provider_data']
            ]);

            return $success ? (int)$this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update social account record
     */
    private function updateSocialAccount(int $socialAccountId, array $data): bool
    {
        try {
            $setParts = [];
            $values = [];

            foreach ($data as $key => $value) {
                $setParts[] = "{$key} = ?";
                $values[] = $value;
            }

            $values[] = $socialAccountId;

            $sql = "UPDATE social_accounts SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute($values);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete social account record
     */
    private function deleteSocialAccount(int $socialAccountId): bool
    {
        try {
            $sql = "DELETE FROM social_accounts WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute([$socialAccountId]);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create new user from social account data
     */
    private function createUserFromSocial(string $provider, array $providerData): array
    {
        try {
            // Create user account
            $userData = [
                'email' => $providerData['email'] ?? null,
                'password_hash' => null, // No password for social-only accounts
                'role' => 'customer',
                'email_verified' => $providerData['verified'] ?? false,
                'is_active' => true
            ];

            // Only create user if we have an email
            if (empty($userData['email'])) {
                return [
                    'success' => false,
                    'error' => 'Email is required to create account',
                    'code' => 'EMAIL_REQUIRED'
                ];
            }

            $userId = $this->userRepository->create($userData);

            if (!$userId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create user account',
                    'code' => 'USER_CREATION_FAILED'
                ];
            }

            // Link social account
            $linkResult = $this->linkAccount($userId, $provider, $providerData);

            if (!$linkResult['success']) {
                // Rollback user creation if social account linking fails
                $this->userRepository->delete($userId);
                return $linkResult;
            }

            $user = $this->userRepository->find($userId);

            $this->auditLogger->log('user_created_from_social', "User created from social: {$provider}", $userId);

            return [
                'success' => true,
                'user' => $user,
                'social_account_id' => $linkResult['social_account_id'],
                'action' => 'register_and_login'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create user from social account',
                'code' => 'SOCIAL_USER_CREATION_ERROR'
            ];
        }
    }
}