<?php

namespace Antinna\Auth\Interfaces;

/**
 * Social authentication interface
 */
interface SocialAuthInterface
{
    /**
     * Get OAuth authorization URL
     */
    public function getAuthorizationUrl(string $provider, array $scopes = []): string;

    /**
     * Handle OAuth callback and exchange code for token
     */
    public function handleCallback(string $provider, string $code): array;

    /**
     * Get user profile from social provider
     */
    public function getUserProfile(string $provider, string $accessToken): array;

    /**
     * Link social account to user
     */
    public function linkAccount(int $userId, string $provider, array $providerData): bool;

    /**
     * Unlink social account from user
     */
    public function unlinkAccount(int $userId, string $provider): bool;

    /**
     * Get supported providers
     */
    public function getSupportedProviders(): array;
}