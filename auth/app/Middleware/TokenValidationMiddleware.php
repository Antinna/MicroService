<?php

namespace Antinna\Auth\Middleware;

use Antinna\Auth\Services\TokenValidator;
use Antinna\Auth\Services\AuditLogger;

/**
 * Token Validation Middleware for Service Integration
 */
class TokenValidationMiddleware
{
    private TokenValidator $tokenValidator;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->tokenValidator = new TokenValidator();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Validate token from Authorization header
     */
    public function validateRequest(array $requiredScopes = [], bool $optional = false): array
    {
        try {
            // Extract token from Authorization header
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            
            if (empty($authHeader)) {
                if ($optional) {
                    return ['authenticated' => false, 'user' => null];
                }
                return $this->unauthorizedResponse('Missing Authorization header');
            }

            // Parse Bearer token
            if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                if ($optional) {
                    return ['authenticated' => false, 'user' => null];
                }
                return $this->unauthorizedResponse('Invalid Authorization header format');
            }

            $token = $matches[1];

            // Validate token
            $validation = $this->tokenValidator->validateToken($token, $requiredScopes);

            if (!$validation['valid']) {
                if ($optional) {
                    return ['authenticated' => false, 'user' => null, 'error' => $validation['message']];
                }
                
                return $this->unauthorizedResponse(
                    $validation['message'],
                    $validation['code'] ?? 'INVALID_TOKEN',
                    $this->getHttpStatusFromValidation($validation['status'])
                );
            }

            // Log successful validation
            $this->auditLogger->logSystemEvent(
                'middleware_token_validated',
                'Token validated via middleware',
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $validation['user_info']['id'] ?? null,
                    'token_type' => $validation['token_info']['token_type'] ?? 'unknown',
                    'scopes' => $validation['scopes'],
                    'validation_time' => $validation['validation_time'],
                    'cached' => $validation['cached'] ?? false
                ]
            );

            return [
                'authenticated' => true,
                'user' => $validation['user_info'],
                'token_info' => $validation['token_info'],
                'scopes' => $validation['scopes']
            ];

        } catch (\Exception $e) {
            $this->auditLogger->logSystemEvent(
                'middleware_validation_error',
                'Middleware token validation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );

            if ($optional) {
                return ['authenticated' => false, 'user' => null, 'error' => 'Validation system error'];
            }

            return $this->unauthorizedResponse('Token validation system error', 'SYSTEM_ERROR', 500);
        }
    }

    /**
     * Validate service token
     */
    public function validateServiceRequest(string $expectedService = null): array
    {
        try {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            
            if (empty($authHeader)) {
                return $this->unauthorizedResponse('Missing Authorization header');
            }

            if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return $this->unauthorizedResponse('Invalid Authorization header format');
            }

            $token = $matches[1];

            // Validate service token
            $validation = $this->tokenValidator->validateServiceToken($token, $expectedService);

            if (!$validation['valid']) {
                return $this->unauthorizedResponse(
                    $validation['message'],
                    $validation['code'] ?? 'INVALID_SERVICE_TOKEN',
                    $this->getHttpStatusFromValidation($validation['status'])
                );
            }

            // Log successful service validation
            $this->auditLogger->logSystemEvent(
                'middleware_service_validated',
                'Service token validated via middleware',
                AuditLogger::SEVERITY_INFO,
                [
                    'service_id' => $validation['service_id'],
                    'expected_service' => $expectedService,
                    'scopes' => $validation['scopes']
                ]
            );

            return [
                'authenticated' => true,
                'service_id' => $validation['service_id'],
                'token_info' => $validation['token_info'],
                'scopes' => $validation['scopes']
            ];

        } catch (\Exception $e) {
            $this->auditLogger->logSystemEvent(
                'middleware_service_validation_error',
                'Middleware service validation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );

            return $this->unauthorizedResponse('Service validation system error', 'SYSTEM_ERROR', 500);
        }
    }

    /**
     * Check if user has required scope
     */
    public function requireScope(string $scope, array $userScopes): bool
    {
        return in_array($scope, $userScopes) || $this->hasWildcardScope($scope, $userScopes);
    }

    /**
     * Check if user has any of the required scopes
     */
    public function requireAnyScope(array $requiredScopes, array $userScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if ($this->requireScope($scope, $userScopes)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all required scopes
     */
    public function requireAllScopes(array $requiredScopes, array $userScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (!$this->requireScope($scope, $userScopes)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract user ID from current request context
     */
    public function getCurrentUserId(): ?int
    {
        $validation = $this->validateRequest([], true);
        return $validation['authenticated'] ? ($validation['user']['id'] ?? null) : null;
    }

    /**
     * Extract service ID from current request context
     */
    public function getCurrentServiceId(): ?string
    {
        $validation = $this->validateServiceRequest();
        return $validation['authenticated'] ? ($validation['service_id'] ?? null) : null;
    }

    /**
     * Middleware function for easy integration
     */
    public function handle(callable $next, array $requiredScopes = [], bool $optional = false)
    {
        $validation = $this->validateRequest($requiredScopes, $optional);
        
        if (!$validation['authenticated'] && !$optional) {
            // Send unauthorized response and exit
            http_response_code($validation['status_code'] ?? 401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $validation['message'],
                'code' => $validation['code'],
                'timestamp' => date('c')
            ]);
            return;
        }

        // Add authentication context to request
        $_REQUEST['auth'] = $validation;
        
        // Continue to next middleware/handler
        return $next();
    }

    /**
     * Service middleware function
     */
    public function handleService(callable $next, string $expectedService = null)
    {
        $validation = $this->validateServiceRequest($expectedService);
        
        if (!$validation['authenticated']) {
            // Send unauthorized response and exit
            http_response_code($validation['status_code'] ?? 401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $validation['message'],
                'code' => $validation['code'],
                'timestamp' => date('c')
            ]);
            return;
        }

        // Add service context to request
        $_REQUEST['service_auth'] = $validation;
        
        // Continue to next middleware/handler
        return $next();
    }

    // Private helper methods

    /**
     * Create unauthorized response
     */
    private function unauthorizedResponse(string $message, string $code = 'UNAUTHORIZED', int $statusCode = 401): array
    {
        return [
            'authenticated' => false,
            'message' => $message,
            'code' => $code,
            'status_code' => $statusCode
        ];
    }

    /**
     * Get HTTP status code from validation status
     */
    private function getHttpStatusFromValidation(string $status): int
    {
        switch ($status) {
            case TokenValidator::VALIDATION_SUCCESS:
                return 200;
            case TokenValidator::VALIDATION_EXPIRED:
                return 401;
            case TokenValidator::VALIDATION_REVOKED:
                return 401;
            case TokenValidator::VALIDATION_INSUFFICIENT_SCOPE:
                return 403;
            case TokenValidator::VALIDATION_INVALID:
            default:
                return 401;
        }
    }

    /**
     * Check for wildcard scope matches
     */
    private function hasWildcardScope(string $requiredScope, array $userScopes): bool
    {
        foreach ($userScopes as $userScope) {
            if (str_ends_with($userScope, ':*')) {
                $prefix = substr($userScope, 0, -2);
                if (str_starts_with($requiredScope, $prefix . ':')) {
                    return true;
                }
            }
        }
        return false;
    }
}

/**
 * Helper functions for easy middleware usage
 */

/**
 * Require authentication with optional scopes
 */
function requireAuth(array $scopes = []): array
{
    $middleware = new TokenValidationMiddleware();
    return $middleware->validateRequest($scopes);
}

/**
 * Optional authentication
 */
function optionalAuth(array $scopes = []): array
{
    $middleware = new TokenValidationMiddleware();
    return $middleware->validateRequest($scopes, true);
}

/**
 * Require service authentication
 */
function requireServiceAuth(string $expectedService = null): array
{
    $middleware = new TokenValidationMiddleware();
    return $middleware->validateServiceRequest($expectedService);
}

/**
 * Get current authenticated user
 */
function getCurrentUser(): ?array
{
    $auth = requireAuth();
    return $auth['authenticated'] ? $auth['user'] : null;
}

/**
 * Get current user ID
 */
function getCurrentUserId(): ?int
{
    $user = getCurrentUser();
    return $user['id'] ?? null;
}

/**
 * Check if current user has scope
 */
function hasScope(string $scope): bool
{
    $auth = optionalAuth();
    if (!$auth['authenticated']) {
        return false;
    }
    
    $middleware = new TokenValidationMiddleware();
    return $middleware->requireScope($scope, $auth['scopes'] ?? []);
}

/**
 * Check if current user has any of the scopes
 */
function hasAnyScope(array $scopes): bool
{
    $auth = optionalAuth();
    if (!$auth['authenticated']) {
        return false;
    }
    
    $middleware = new TokenValidationMiddleware();
    return $middleware->requireAnyScope($scopes, $auth['scopes'] ?? []);
}

/**
 * Check if current user has all scopes
 */
function hasAllScopes(array $scopes): bool
{
    $auth = optionalAuth();
    if (!$auth['authenticated']) {
        return false;
    }
    
    $middleware = new TokenValidationMiddleware();
    return $middleware->requireAllScopes($scopes, $auth['scopes'] ?? []);
}