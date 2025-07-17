<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Exception;
use PDO;

/**
 * Magic Link Handler for passwordless authentication
 */
class MagicLinkHandler
{
    private PDO $db;
    private App $config;
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->config = App::getInstance();
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Generate a magic link for user authentication
     */
    public function generateMagicLink(string $email, array $options = []): array
    {
        try {
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'error' => 'Invalid email format',
                    'code' => 'INVALID_EMAIL'
                ];
            }

            // Check if user exists
            $user = $this->userRepository->findByEmail($email);
            if (!$user) {
                // For security, don't reveal if user exists or not
                // Still generate a response but don't actually create a link
                $this->auditLogger->log(
                    'magic_link_attempt_unknown_user',
                    "Magic link requested for unknown email: $email",
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                );

                return [
                    'success' => true,
                    'message' => 'If the email exists in our system, a magic link has been sent',
                    'email' => $email
                ];
            }

            $userId = $user['id'];

            // Check rate limiting
            if (!$this->checkRateLimit($userId, $email)) {
                return [
                    'success' => false,
                    'error' => 'Too many magic link requests. Please wait before requesting another',
                    'code' => 'RATE_LIMIT_EXCEEDED'
                ];
            }

            // Generate secure token
            $token = $this->generateSecureToken();
            $tokenHash = hash('sha256', $token);

            // Set expiration time (default 15 minutes)
            $expirationMinutes = $options['expiration_minutes'] ?? 15;
            $expiresAt = date('Y-m-d H:i:s', time() + ($expirationMinutes * 60));

            // Store magic link token
            $linkId = $this->storeMagicLinkToken($userId, $tokenHash, $expiresAt, $options);
            if (!$linkId) {
                return [
                    'success' => false,
                    'error' => 'Failed to generate magic link',
                    'code' => 'GENERATION_FAILED'
                ];
            }

            // Generate the magic link URL
            $baseUrl = $this->config->get('app.url', 'https://localhost');
            $magicLinkUrl = $baseUrl . '/auth/magic-link/verify?token=' . urlencode($token);

            // Add additional parameters if provided
            if (isset($options['redirect_url'])) {
                $magicLinkUrl .= '&redirect=' . urlencode($options['redirect_url']);
            }

            if (isset($options['mobile_deep_link'])) {
                $magicLinkUrl .= '&mobile=1';
            }

            $this->auditLogger->log(
                'magic_link_generated',
                'Magic link generated successfully',
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            );

            return [
                'success' => true,
                'message' => 'Magic link generated successfully',
                'data' => [
                    'link_id' => $linkId,
                    'magic_link' => $magicLinkUrl,
                    'expires_at' => $expiresAt,
                    'expires_in_minutes' => $expirationMinutes,
                    'email' => $email
                ]
            ];

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_generation_error',
                'Magic link generation error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to generate magic link',
                'code' => 'GENERATION_ERROR'
            ];
        }
    }

    /**
     * Validate and consume a magic link token
     */
    public function validateMagicLink(string $token): array
    {
        try {
            $tokenHash = hash('sha256', $token);

            // Find the magic link record
            $magicLink = $this->findMagicLinkByToken($tokenHash);
            if (!$magicLink) {
                $this->auditLogger->log(
                    'magic_link_invalid_token',
                    'Invalid magic link token attempted',
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'warning'
                );

                return [
                    'success' => false,
                    'error' => 'Invalid or expired magic link',
                    'code' => 'INVALID_TOKEN'
                ];
            }

            // Check if token has expired
            if (strtotime($magicLink['expires_at']) < time()) {
                $this->auditLogger->log(
                    'magic_link_expired',
                    'Expired magic link token attempted',
                    $magicLink['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'warning'
                );

                return [
                    'success' => false,
                    'error' => 'Magic link has expired',
                    'code' => 'TOKEN_EXPIRED'
                ];
            }

            // Check if token has already been used
            if ($magicLink['used_at']) {
                $this->auditLogger->log(
                    'magic_link_already_used',
                    'Already used magic link token attempted',
                    $magicLink['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'warning'
                );

                return [
                    'success' => false,
                    'error' => 'Magic link has already been used',
                    'code' => 'TOKEN_ALREADY_USED'
                ];
            }

            // Get user information
            $user = $this->userRepository->find($magicLink['user_id']);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Mark token as used
            $this->markTokenAsUsed($magicLink['id']);

            $this->auditLogger->log(
                'magic_link_validated',
                'Magic link validated successfully',
                $user['id'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            );

            return [
                'success' => true,
                'message' => 'Magic link validated successfully',
                'data' => [
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'is_verified' => (bool)$user['is_verified']
                    ],
                    'magic_link' => [
                        'id' => $magicLink['id'],
                        'created_at' => $magicLink['created_at'],
                        'expires_at' => $magicLink['expires_at'],
                        'metadata' => json_decode($magicLink['metadata'], true) ?? []
                    ]
                ]
            ];

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_validation_error',
                'Magic link validation error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to validate magic link',
                'code' => 'VALIDATION_ERROR'
            ];
        }
    }

    /**
     * Revoke all active magic links for a user
     */
    public function revokeUserMagicLinks(int $userId): array
    {
        try {
            $sql = "
                UPDATE magic_links 
                SET revoked_at = NOW() 
                WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$userId]);
            $revokedCount = $stmt->rowCount();

            if ($success) {
                $this->auditLogger->log(
                    'magic_links_revoked',
                    "Revoked $revokedCount active magic links",
                    $userId
                );

                return [
                    'success' => true,
                    'message' => 'Magic links revoked successfully',
                    'revoked_count' => $revokedCount
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to revoke magic links',
                    'code' => 'REVOCATION_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_revocation_error',
                'Magic link revocation error: ' . $e->getMessage(),
                $userId,
                'unknown',
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to revoke magic links',
                'code' => 'REVOCATION_ERROR'
            ];
        }
    }

    /**
     * Get magic link statistics for a user
     */
    public function getUserMagicLinkStats(int $userId): array
    {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_generated,
                    COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as total_used,
                    COUNT(CASE WHEN expires_at < NOW() AND used_at IS NULL THEN 1 END) as total_expired,
                    COUNT(CASE WHEN revoked_at IS NOT NULL THEN 1 END) as total_revoked,
                    COUNT(CASE WHEN expires_at > NOW() AND used_at IS NULL AND revoked_at IS NULL THEN 1 END) as active_links,
                    MAX(created_at) as last_generated,
                    MAX(used_at) as last_used
                FROM magic_links 
                WHERE user_id = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => [
                    'total_generated' => (int)$stats['total_generated'],
                    'total_used' => (int)$stats['total_used'],
                    'total_expired' => (int)$stats['total_expired'],
                    'total_revoked' => (int)$stats['total_revoked'],
                    'active_links' => (int)$stats['active_links'],
                    'last_generated' => $stats['last_generated'],
                    'last_used' => $stats['last_used'],
                    'usage_rate' => $stats['total_generated'] > 0 
                        ? round(($stats['total_used'] / $stats['total_generated']) * 100, 2) 
                        : 0
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get magic link statistics',
                'code' => 'STATS_ERROR'
            ];
        }
    }

    /**
     * Clean up expired magic links
     */
    public function cleanupExpiredLinks(): array
    {
        try {
            $sql = "DELETE FROM magic_links WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute();
            $deletedCount = $stmt->rowCount();

            return [
                'success' => $success,
                'message' => "Cleaned up $deletedCount expired magic links",
                'deleted_count' => $deletedCount
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to cleanup expired links',
                'code' => 'CLEANUP_ERROR'
            ];
        }
    }

    /**
     * Generate a cryptographically secure token
     */
    private function generateSecureToken(): string
    {
        // Generate 32 bytes of random data and encode as URL-safe base64
        $randomBytes = random_bytes(32);
        return rtrim(strtr(base64_encode($randomBytes), '+/', '-_'), '=');
    }

    /**
     * Store magic link token in database
     */
    private function storeMagicLinkToken(int $userId, string $tokenHash, string $expiresAt, array $options): ?int
    {
        try {
            // Prepare metadata
            $metadata = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s'),
                'options' => $options
            ];

            $sql = "
                INSERT INTO magic_links (user_id, token_hash, expires_at, metadata, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $userId,
                $tokenHash,
                $expiresAt,
                json_encode($metadata)
            ]);

            return $success ? (int)$this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Find magic link by token hash
     */
    private function findMagicLinkByToken(string $tokenHash): ?array
    {
        try {
            $sql = "
                SELECT * FROM magic_links 
                WHERE token_hash = ? AND revoked_at IS NULL 
                ORDER BY created_at DESC 
                LIMIT 1
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tokenHash]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Mark token as used
     */
    private function markTokenAsUsed(int $linkId): bool
    {
        try {
            $sql = "UPDATE magic_links SET used_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$linkId]);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check rate limiting for magic link requests
     */
    private function checkRateLimit(int $userId, string $email): bool
    {
        try {
            // Check how many magic links were generated in the last hour
            $sql = "
                SELECT COUNT(*) as count 
                FROM magic_links 
                WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $hourlyLimit = $this->config->get('magic_link.hourly_limit', 5);
            
            if ($result['count'] >= $hourlyLimit) {
                $this->auditLogger->log(
                    'magic_link_rate_limit',
                    "Rate limit exceeded for user $userId ($email)",
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'warning'
                );
                return false;
            }

            return true;

        } catch (Exception $e) {
            // If we can't check rate limit, allow the request but log the error
            $this->auditLogger->log(
                'magic_link_rate_limit_error',
                'Rate limit check error: ' . $e->getMessage(),
                $userId,
                'unknown',
                'error'
            );
            return true;
        }
    }
}