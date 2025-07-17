<?php

namespace Antinna\Auth\Exceptions;

use Exception;

/**
 * Unauthorized Exception for authentication failures
 */
class UnauthorizedException extends Exception
{
    private string $reason;
    private array $context;

    public function __construct(string $message, string $reason = '', array $context = [], int $code = 1001, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->reason = $reason;
        $this->context = $context;
    }

    /**
     * Get failure reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get additional context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get error response array
     */
    public function getErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => $this->getMessage(),
                'reason' => $this->reason,
                'timestamp' => date('c')
            ]
        ];
    }

    /**
     * Create exception for invalid credentials
     */
    public static function invalidCredentials(): self
    {
        return new self(
            'Invalid credentials provided',
            'invalid_credentials',
            [],
            1001
        );
    }

    /**
     * Create exception for expired token
     */
    public static function expiredToken(): self
    {
        return new self(
            'Authentication token has expired',
            'token_expired',
            [],
            1006
        );
    }

    /**
     * Create exception for invalid token
     */
    public static function invalidToken(): self
    {
        return new self(
            'Invalid authentication token',
            'invalid_token',
            [],
            1005
        );
    }

    /**
     * Create exception for account locked
     */
    public static function accountLocked(int $lockoutTime = 0): self
    {
        $context = $lockoutTime > 0 ? ['lockout_expires' => $lockoutTime] : [];
        
        return new self(
            'Account is temporarily locked due to multiple failed login attempts',
            'account_locked',
            $context,
            1002
        );
    }

    /**
     * Create exception for disabled account
     */
    public static function accountDisabled(): self
    {
        return new self(
            'Account has been disabled',
            'account_disabled',
            [],
            1003
        );
    }

    /**
     * Create exception for expired session
     */
    public static function sessionExpired(): self
    {
        return new self(
            'Session has expired',
            'session_expired',
            [],
            1004
        );
    }

    /**
     * Create exception for insufficient privileges
     */
    public static function insufficientPrivileges(string $requiredRole = ''): self
    {
        $context = $requiredRole ? ['required_role' => $requiredRole] : [];
        
        return new self(
            'Insufficient privileges to access this resource',
            'insufficient_privileges',
            $context,
            1009
        );
    }

    /**
     * Create exception for MFA required
     */
    public static function mfaRequired(array $availableMethods = []): self
    {
        return new self(
            'Multi-factor authentication is required',
            'mfa_required',
            ['available_methods' => $availableMethods],
            1007
        );
    }

    /**
     * Create exception for invalid MFA
     */
    public static function invalidMfa(): self
    {
        return new self(
            'Invalid multi-factor authentication code',
            'invalid_mfa',
            [],
            1008
        );
    }
}