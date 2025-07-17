<?php

namespace Antinna\Auth\Interfaces;

/**
 * Core authenticator interface for all authentication methods
 */
interface AuthenticatorInterface
{
    /**
     * Authenticate user with provided credentials
     */
    public function authenticate(array $credentials): array;

    /**
     * Validate authentication data
     */
    public function validate(array $data): bool;

    /**
     * Get authentication method name
     */
    public function getMethodName(): string;
}