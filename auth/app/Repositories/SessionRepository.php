<?php

namespace Antinna\Auth\Repositories;

/**
 * Session repository for database operations
 */
class SessionRepository extends BaseRepository
{
    protected function getTableName(): string
    {
        return 'sessions';
    }

    /**
     * Find session by token hash
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        return $this->findBy(['token_hash' => $tokenHash, 'is_active' => true]);
    }

    /**
     * Find active sessions for user
     */
    public function findActiveUserSessions(int $userId): array
    {
        return $this->findAll([
            'user_id' => $userId,
            'is_active' => true
        ]);
    }

    /**
     * Create new session
     */
    public function createSession(array $sessionData): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $sessionData['id'] = $sessionId;
        $sessionData['created_at'] = date('Y-m-d H:i:s');
        
        $this->create($sessionData);
        return $sessionId;
    }

    /**
     * Update session activity
     */
    public function updateActivity(string $sessionId): bool
    {
        $sql = "UPDATE {$this->getTableName()} SET last_activity = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$sessionId]);
    }

    /**
     * Invalidate session
     */
    public function invalidateSession(string $sessionId): bool
    {
        $sql = "UPDATE {$this->getTableName()} SET is_active = FALSE WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$sessionId]);
    }

    /**
     * Invalidate all user sessions
     */
    public function invalidateAllUserSessions(int $userId): bool
    {
        $sql = "UPDATE {$this->getTableName()} SET is_active = FALSE WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions(): int
    {
        $sql = "DELETE FROM {$this->getTableName()} WHERE expires_at < NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Get session statistics
     */
    public function getSessionStats(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_sessions,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_sessions,
                COUNT(CASE WHEN session_type = 'web' THEN 1 END) as web_sessions,
                COUNT(CASE WHEN session_type = 'mobile' THEN 1 END) as mobile_sessions,
                COUNT(CASE WHEN session_type = 'api' THEN 1 END) as api_sessions
            FROM {$this->getTableName()}
            WHERE expires_at > NOW()
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Find sessions by IP address
     */
    public function findByIpAddress(string $ipAddress, int $limit = 10): array
    {
        $sql = "SELECT * FROM {$this->getTableName()} WHERE ip_address = ? ORDER BY created_at DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ipAddress, $limit]);
        return $stmt->fetchAll();
    }
}