<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Database\Connection;
use PDO;

/**
 * Token blacklist service for managing revoked tokens
 */
class TokenBlacklistService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Add token to blacklist
     */
    public function addToBlacklist(string $tokenHash, ?int $userId = null, string $reason = 'Revoked', string $expiresAt = null): bool
    {
        try {
            $sql = "INSERT INTO token_blacklist (token_hash, user_id, reason, expires_at) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute([
                $tokenHash,
                $userId,
                $reason,
                $expiresAt ?? date('Y-m-d H:i:s', strtotime('+1 year'))
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if token is blacklisted
     */
    public function isBlacklisted(string $tokenHash): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM token_blacklist WHERE token_hash = ? AND expires_at > NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tokenHash]);
            
            return $stmt->fetchColumn() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove expired tokens from blacklist
     */
    public function cleanupExpiredTokens(): int
    {
        try {
            $sql = "DELETE FROM token_blacklist WHERE expires_at <= NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->rowCount();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllUserTokens(int $userId, string $reason = 'All tokens revoked'): bool
    {
        try {
            // This is a placeholder - in a real implementation, we'd need to track all active tokens
            // For now, we'll just mark the user as requiring re-authentication
            $sql = "INSERT INTO token_blacklist (token_hash, user_id, reason, expires_at) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            
            // Use a special marker for user-wide revocation
            $userMarker = 'user_revoke_' . $userId . '_' . time();
            
            return $stmt->execute([
                hash('sha256', $userMarker),
                $userId,
                $reason,
                date('Y-m-d H:i:s', strtotime('+1 year'))
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get blacklist statistics
     */
    public function getBlacklistStats(): array
    {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_blacklisted,
                    COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active_blacklisted,
                    COUNT(CASE WHEN user_id IS NOT NULL THEN 1 END) as user_tokens,
                    COUNT(CASE WHEN user_id IS NULL THEN 1 END) as service_tokens
                FROM token_blacklist
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetch() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get recent blacklist entries
     */
    public function getRecentBlacklistEntries(int $limit = 10): array
    {
        try {
            $sql = "
                SELECT tb.*, u.email 
                FROM token_blacklist tb
                LEFT JOIN users u ON tb.user_id = u.id
                ORDER BY tb.created_at DESC 
                LIMIT ?
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }
}