<?php

namespace Antinna\Auth\Interfaces;

/**
 * Session management interface
 */
interface SessionManagerInterface
{
    /**
     * Create new session for user
     */
    public function createSession(int $userId, array $sessionData): string;

    /**
     * Validate session
     */
    public function validateSession(string $sessionId): array;

    /**
     * Update session activity
     */
    public function updateActivity(string $sessionId): bool;

    /**
     * Invalidate session
     */
    public function invalidateSession(string $sessionId): bool;

    /**
     * Invalidate all user sessions
     */
    public function invalidateAllUserSessions(int $userId): bool;

    /**
     * Get active sessions for user
     */
    public function getUserSessions(int $userId): array;
}