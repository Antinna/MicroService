<?php

namespace Antinna\Auth\Exceptions;

use Exception;

/**
 * Not Found Exception for missing resources
 */
class NotFoundException extends Exception
{
    private string $resource;
    private string $identifier;

    public function __construct(string $message, string $resource = '', string $identifier = '', int $code = 1201, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->resource = $resource;
        $this->identifier = $identifier;
    }

    /**
     * Get resource type
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Get resource identifier
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Get error response array
     */
    public function getErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => $this->getMessage(),
                'resource' => $this->resource,
                'identifier' => $this->identifier,
                'timestamp' => date('c')
            ]
        ];
    }

    /**
     * Create exception for user not found
     */
    public static function user(string $identifier): self
    {
        return new self(
            "User not found: {$identifier}",
            'user',
            $identifier,
            1201
        );
    }

    /**
     * Create exception for session not found
     */
    public static function session(string $sessionId): self
    {
        return new self(
            "Session not found: {$sessionId}",
            'session',
            $sessionId,
            1201
        );
    }

    /**
     * Create exception for token not found
     */
    public static function token(string $tokenId): self
    {
        return new self(
            "Token not found: {$tokenId}",
            'token',
            $tokenId,
            1201
        );
    }

    /**
     * Create exception for endpoint not found
     */
    public static function endpoint(string $path): self
    {
        return new self(
            "Endpoint not found: {$path}",
            'endpoint',
            $path,
            1201
        );
    }

    /**
     * Create exception for service not found
     */
    public static function service(string $serviceId): self
    {
        return new self(
            "Service not found: {$serviceId}",
            'service',
            $serviceId,
            1201
        );
    }

    /**
     * Create exception for passkey not found
     */
    public static function passkey(string $credentialId): self
    {
        return new self(
            "Passkey not found: {$credentialId}",
            'passkey',
            $credentialId,
            1201
        );
    }

    /**
     * Create exception for magic link not found
     */
    public static function magicLink(string $token): self
    {
        return new self(
            "Magic link not found or expired",
            'magic_link',
            $token,
            1201
        );
    }
}