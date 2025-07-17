<?php

namespace Antinna\Auth\Interfaces;

/**
 * Multi-Factor Authentication handler interface
 */
interface MFAHandlerInterface
{
    /**
     * Setup MFA for user
     */
    public function setupMFA(int $userId, string $method): array;

    /**
     * Verify MFA code
     */
    public function verifyMFA(int $userId, string $code, string $method): bool;

    /**
     * Generate backup codes
     */
    public function generateBackupCodes(int $userId): array;

    /**
     * Verify backup code
     */
    public function verifyBackupCode(int $userId, string $code): bool;

    /**
     * Disable MFA for user
     */
    public function disableMFA(int $userId): bool;

    /**
     * Check if MFA is required for user
     */
    public function isMFARequired(int $userId): bool;
}