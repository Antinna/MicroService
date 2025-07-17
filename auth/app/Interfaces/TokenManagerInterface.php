<?php

namespace Antinna\Auth\Interfaces;

/**
 * Token management interface for JWT operations
 */
interface TokenManagerInterface
{
    /**
     * Generate JWT token for user
     */
    public function generateToken(int $userId, array $claims = []): string;

    /**
     * Validate JWT token
     */
    public function validateToken(string $token): array;

    /**
     * Refresh JWT token
     */
    public function refreshToken(string $token): string;

    /**
     * Revoke JWT token
     */
    public function revokeToken(string $token): bool;

    /**
     * Check if token is blacklisted
     */
    public function isTokenBlacklisted(string $token): bool;
}