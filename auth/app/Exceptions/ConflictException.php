<?php

namespace Antinna\Auth\Exceptions;

use Exception;

/**
 * Conflict Exception for resource conflicts
 */
class ConflictException extends Exception
{
    private string $resource;
    private string $conflictType;
    private array $conflictData;

    public function __construct(string $message, string $resource = '', string $conflictType = '', array $conflictData = [], int $code = 1203, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->resource = $resource;
        $this->conflictType = $conflictType;
        $this->conflictData = $conflictData;
    }

    /**
     * Get resource type
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Get conflict type
     */
    public function getConflictType(): string
    {
        return $this->conflictType;
    }

    /**
     * Get conflict data
     */
    public function getConflictData(): array
    {
        return $this->conflictData;
    }

    /**
     * Get error response array
     */
    public function getErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'CONFLICT',
                'message' => $this->getMessage(),
                'resource' => $this->resource,
                'conflict_type' => $this->conflictType,
                'conflict_data' => $this->conflictData,
                'timestamp' => date('c')
            ]
        ];
    }

    /**
     * Create exception for duplicate email
     */
    public static function duplicateEmail(string $email): self
    {
        return new self(
            "Email address already exists: {$email}",
            'user',
            'duplicate_email',
            ['email' => $email],
            1202
        );
    }

    /**
     * Create exception for duplicate username
     */
    public static function duplicateUsername(string $username): self
    {
        return new self(
            "Username already exists: {$username}",
            'user',
            'duplicate_username',
            ['username' => $username],
            1202
        );
    }

    /**
     * Create exception for active session conflict
     */
    public static function activeSession(int $userId): self
    {
        return new self(
            "User already has an active session",
            'session',
            'active_session',
            ['user_id' => $userId],
            1203
        );
    }

    /**
     * Create exception for token already used
     */
    public static function tokenAlreadyUsed(string $tokenType): self
    {
        return new self(
            "Token has already been used: {$tokenType}",
            'token',
            'already_used',
            ['token_type' => $tokenType],
            1203
        );
    }

    /**
     * Create exception for service already registered
     */
    public static function serviceAlreadyRegistered(string $serviceId): self
    {
        return new self(
            "Service already registered: {$serviceId}",
            'service',
            'already_registered',
            ['service_id' => $serviceId],
            1202
        );
    }

    /**
     * Create exception for passkey already registered
     */
    public static function passkeyAlreadyRegistered(string $credentialId): self
    {
        return new self(
            "Passkey already registered for this user",
            'passkey',
            'already_registered',
            ['credential_id' => $credentialId],
            1202
        );
    }

    /**
     * Create exception for social account already linked
     */
    public static function socialAccountAlreadyLinked(string $provider, string $providerId): self
    {
        return new self(
            "Social account already linked: {$provider}",
            'social_account',
            'already_linked',
            ['provider' => $provider, 'provider_id' => $providerId],
            1202
        );
    }

    /**
     * Create exception for concurrent modification
     */
    public static function concurrentModification(string $resource, string $identifier): self
    {
        return new self(
            "Resource was modified by another process: {$resource}",
            $resource,
            'concurrent_modification',
            ['identifier' => $identifier],
            1203
        );
    }

    /**
     * Create exception for rate limit conflict
     */
    public static function rateLimitActive(string $identifier, int $resetTime): self
    {
        return new self(
            "Rate limit is currently active for: {$identifier}",
            'rate_limit',
            'limit_active',
            ['identifier' => $identifier, 'reset_time' => $resetTime],
            1203
        );
    }
}