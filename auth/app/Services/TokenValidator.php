<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\AuditLogger;
use Exception;

/**
 * High-performance token validation service for service-to-service authentication
 */
class TokenValidator
{
    private JWTManager $jwtManager;
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private array $cache = [];
    private int $cacheTimeout = 300; // 5 minutes
    private int $maxCacheSize = 1000;

    // Token types
    public const TOKEN_TYPE_ACCESS = 'access';
    public const TOKEN_TYPE_REFRESH = 'refresh';
    public const TOKEN_TYPE_SERVICE = 'service';
    public const TOKEN_TYPE_API = 'api';

    // Validation results
    public const VALIDATION_SUCCESS = 'success';
    public const VALIDATION_EXPIRED = 'expired';
    public const VALIDATION_INVALID = 'invalid';
    public const VALIDATION_REVOKED = 'revoked';
    public const VALIDATION_INSUFFICIENT_SCOPE = 'insufficient_scope';

    public function __construct()
    {
        $this->jwtManager = new JWTManager();
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * High-performance token validation with caching
     */
    public function validateToken(string $token, array $requiredScopes = [], bool $useCache = true): array
    {
        try {
            $startTime = microtime(true);
            
            // Check cache first if enabled
            if ($useCache) {
                $cachedResult = $this->getCachedValidation($token, $requiredScopes);
                if ($cachedResult !== null) {
                    $cachedResult['cached'] = true;
                    $cachedResult['validation_time'] = microtime(true) - $startTime;
                    return $cachedResult;
                }
            }

            // Validate JWT structure and signature
            $jwtResult = $this->jwtManager->validateToken($token);
            if (!$jwtResult['success']) {
                $result = [
                    'valid' => false,
                    'status' => self::VALIDATION_INVALID,
                    'message' => $jwtResult['message'] ?? 'Invalid token',
                    'code' => $jwtResult['code'] ?? 'INVALID_TOKEN',
                    'validation_time' => microtime(true) - $startTime,
                    'cached' => false
                ];
                
                if ($useCache) {
                    $this->cacheValidation($token, $requiredScopes, $result, 60); // Cache failures for 1 minute
                }
                
                return $result;
            }

            $payload = $jwtResult['payload'];
            
            // Extract token information
            $tokenInfo = [
                'user_id' => $payload['user_id'] ?? null,
                'service_id' => $payload['service_id'] ?? null,
                'token_type' => $payload['token_type'] ?? self::TOKEN_TYPE_ACCESS,
                'scopes' => $payload['scopes'] ?? [],
                'issued_at' => $payload['iat'] ?? null,
                'expires_at' => $payload['exp'] ?? null,
                'issuer' => $payload['iss'] ?? null,
                'audience' => $payload['aud'] ?? null,
                'jti' => $payload['jti'] ?? null
            ];

            // Check if token is revoked
            if ($this->isTokenRevoked($tokenInfo['jti'] ?? $token)) {
                $result = [
                    'valid' => false,
                    'status' => self::VALIDATION_REVOKED,
                    'message' => 'Token has been revoked',
                    'code' => 'TOKEN_REVOKED',
                    'token_info' => $tokenInfo,
                    'validation_time' => microtime(true) - $startTime,
                    'cached' => false
                ];
                
                if ($useCache) {
                    $this->cacheValidation($token, $requiredScopes, $result, 3600); // Cache revoked tokens for 1 hour
                }
                
                return $result;
            }

            // Validate user if user token
            $userInfo = null;
            if ($tokenInfo['user_id']) {
                $userValidation = $this->validateTokenUser($tokenInfo['user_id']);
                if (!$userValidation['valid']) {
                    $result = [
                        'valid' => false,
                        'status' => self::VALIDATION_INVALID,
                        'message' => $userValidation['message'],
                        'code' => $userValidation['code'],
                        'token_info' => $tokenInfo,
                        'validation_time' => microtime(true) - $startTime,
                        'cached' => false
                    ];
                    
                    if ($useCache) {
                        $this->cacheValidation($token, $requiredScopes, $result, 300); // Cache for 5 minutes
                    }
                    
                    return $result;
                }
                $userInfo = $userValidation['user'];
            }

            // Check required scopes
            if (!empty($requiredScopes)) {
                $scopeValidation = $this->validateScopes($tokenInfo['scopes'], $requiredScopes);
                if (!$scopeValidation['valid']) {
                    $result = [
                        'valid' => false,
                        'status' => self::VALIDATION_INSUFFICIENT_SCOPE,
                        'message' => $scopeValidation['message'],
                        'code' => 'INSUFFICIENT_SCOPE',
                        'token_info' => $tokenInfo,
                        'user_info' => $userInfo,
                        'required_scopes' => $requiredScopes,
                        'available_scopes' => $tokenInfo['scopes'],
                        'validation_time' => microtime(true) - $startTime,
                        'cached' => false
                    ];
                    
                    if ($useCache) {
                        $this->cacheValidation($token, $requiredScopes, $result, 300);
                    }
                    
                    return $result;
                }
            }

            // Token is valid
            $result = [
                'valid' => true,
                'status' => self::VALIDATION_SUCCESS,
                'message' => 'Token is valid',
                'token_info' => $tokenInfo,
                'user_info' => $userInfo,
                'scopes' => $tokenInfo['scopes'],
                'validation_time' => microtime(true) - $startTime,
                'cached' => false
            ];

            // Cache successful validation
            if ($useCache) {
                $cacheTimeout = min($this->cacheTimeout, ($tokenInfo['expires_at'] ?? time() + 300) - time());
                $this->cacheValidation($token, $requiredScopes, $result, $cacheTimeout);
            }

            return $result;

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'token_validation_error',
                'Token validation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['token_preview' => substr($token, 0, 20) . '...']
            );

            return [
                'valid' => false,
                'status' => self::VALIDATION_INVALID,
                'message' => 'Token validation system error',
                'code' => 'VALIDATION_ERROR',
                'validation_time' => microtime(true) - ($startTime ?? microtime(true)),
                'cached' => false
            ];
        }
    }

    /**
     * Validate token and return user information
     */
    public function validateAndGetUser(string $token, array $requiredScopes = []): array
    {
        $validation = $this->validateToken($token, $requiredScopes);
        
        if (!$validation['valid']) {
            return $validation;
        }

        if (!$validation['user_info']) {
            return [
                'valid' => false,
                'status' => self::VALIDATION_INVALID,
                'message' => 'Token does not contain user information',
                'code' => 'NO_USER_INFO'
            ];
        }

        return [
            'valid' => true,
            'user' => $validation['user_info'],
            'token_info' => $validation['token_info'],
            'scopes' => $validation['scopes']
        ];
    }

    /**
     * Validate service-to-service token
     */
    public function validateServiceToken(string $token, string $expectedService = null): array
    {
        $validation = $this->validateToken($token, ['service:access']);
        
        if (!$validation['valid']) {
            return $validation;
        }

        $tokenInfo = $validation['token_info'];
        
        // Check if it's a service token
        if ($tokenInfo['token_type'] !== self::TOKEN_TYPE_SERVICE) {
            return [
                'valid' => false,
                'status' => self::VALIDATION_INVALID,
                'message' => 'Not a service token',
                'code' => 'INVALID_TOKEN_TYPE'
            ];
        }

        // Check expected service if specified
        if ($expectedService && $tokenInfo['service_id'] !== $expectedService) {
            return [
                'valid' => false,
                'status' => self::VALIDATION_INVALID,
                'message' => 'Token not issued for expected service',
                'code' => 'INVALID_SERVICE'
            ];
        }

        return [
            'valid' => true,
            'service_id' => $tokenInfo['service_id'],
            'token_info' => $tokenInfo,
            'scopes' => $validation['scopes']
        ];
    }

    /**
     * Batch validate multiple tokens for performance
     */
    public function validateTokensBatch(array $tokens, array $requiredScopes = []): array
    {
        $results = [];
        $startTime = microtime(true);
        
        foreach ($tokens as $index => $token) {
            $results[$index] = $this->validateToken($token, $requiredScopes);
        }
        
        return [
            'results' => $results,
            'batch_validation_time' => microtime(true) - $startTime,
            'total_tokens' => count($tokens),
            'valid_tokens' => count(array_filter($results, fn($r) => $r['valid'])),
            'invalid_tokens' => count(array_filter($results, fn($r) => !$r['valid']))
        ];
    }

    /**
     * Get token information without full validation (for debugging)
     */
    public function inspectToken(string $token): array
    {
        try {
            $jwtResult = $this->jwtManager->decodeToken($token, false); // Don't verify signature
            
            if (!$jwtResult['success']) {
                return [
                    'valid_structure' => false,
                    'error' => $jwtResult['message'] ?? 'Invalid token structure'
                ];
            }

            $payload = $jwtResult['payload'];
            
            return [
                'valid_structure' => true,
                'header' => $jwtResult['header'] ?? null,
                'payload' => $payload,
                'token_type' => $payload['token_type'] ?? 'unknown',
                'user_id' => $payload['user_id'] ?? null,
                'service_id' => $payload['service_id'] ?? null,
                'scopes' => $payload['scopes'] ?? [],
                'issued_at' => $payload['iat'] ? date('Y-m-d H:i:s', $payload['iat']) : null,
                'expires_at' => $payload['exp'] ? date('Y-m-d H:i:s', $payload['exp']) : null,
                'is_expired' => $payload['exp'] ? ($payload['exp'] < time()) : null,
                'issuer' => $payload['iss'] ?? null,
                'audience' => $payload['aud'] ?? null,
                'jti' => $payload['jti'] ?? null
            ];
            
        } catch (Exception $e) {
            return [
                'valid_structure' => false,
                'error' => 'Token inspection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Revoke a token
     */
    public function revokeToken(string $tokenId, string $reason = 'Manual revocation'): bool
    {
        try {
            // Add to revocation list (this would typically be stored in database or cache)
            $this->addToRevocationList($tokenId, $reason);
            
            // Clear from cache
            $this->clearTokenFromCache($tokenId);
            
            // Log revocation
            $this->auditLogger->logSystemEvent(
                'token_revoked',
                'Token revoked: ' . $reason,
                AuditLogger::SEVERITY_INFO,
                ['token_id' => $tokenId, 'reason' => $reason]
            );
            
            return true;
            
        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'token_revocation_error',
                'Token revocation failed: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['token_id' => $tokenId]
            );
            
            return false;
        }
    }

    /**
     * Get validation statistics
     */
    public function getValidationStats(): array
    {
        return [
            'cache_size' => count($this->cache),
            'max_cache_size' => $this->maxCacheSize,
            'cache_timeout' => $this->cacheTimeout,
            'cache_hit_ratio' => $this->calculateCacheHitRatio(),
            'memory_usage' => memory_get_usage(true),
            'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time())
        ];
    }

    /**
     * Clear validation cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    // Private helper methods

    /**
     * Get cached validation result
     */
    private function getCachedValidation(string $token, array $requiredScopes): ?array
    {
        $cacheKey = $this->generateCacheKey($token, $requiredScopes);
        
        if (!isset($this->cache[$cacheKey])) {
            return null;
        }
        
        $cached = $this->cache[$cacheKey];
        
        // Check if cache entry is expired
        if ($cached['expires_at'] < time()) {
            unset($this->cache[$cacheKey]);
            return null;
        }
        
        return $cached['result'];
    }

    /**
     * Cache validation result
     */
    private function cacheValidation(string $token, array $requiredScopes, array $result, int $timeout): void
    {
        // Implement cache size limit
        if (count($this->cache) >= $this->maxCacheSize) {
            $this->evictOldestCacheEntries();
        }
        
        $cacheKey = $this->generateCacheKey($token, $requiredScopes);
        
        $this->cache[$cacheKey] = [
            'result' => $result,
            'cached_at' => time(),
            'expires_at' => time() + $timeout
        ];
    }

    /**
     * Generate cache key
     */
    private function generateCacheKey(string $token, array $requiredScopes): string
    {
        return hash('sha256', $token . '|' . implode(',', $requiredScopes));
    }

    /**
     * Evict oldest cache entries
     */
    private function evictOldestCacheEntries(): void
    {
        // Remove 20% of oldest entries
        $entriesToRemove = (int)($this->maxCacheSize * 0.2);
        
        // Sort by cached_at timestamp
        uasort($this->cache, fn($a, $b) => $a['cached_at'] <=> $b['cached_at']);
        
        $removed = 0;
        foreach ($this->cache as $key => $entry) {
            if ($removed >= $entriesToRemove) {
                break;
            }
            unset($this->cache[$key]);
            $removed++;
        }
    }

    /**
     * Check if token is revoked
     */
    private function isTokenRevoked(string $tokenId): bool
    {
        // This would check against a revocation list stored in database or cache
        // For now, we'll return false (not revoked)
        return false;
    }

    /**
     * Validate token user
     */
    private function validateTokenUser(int $userId): array
    {
        try {
            $user = $this->userRepository->find($userId);
            
            if (!$user) {
                return [
                    'valid' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }
            
            if (!$user['is_active']) {
                return [
                    'valid' => false,
                    'message' => 'User account is inactive',
                    'code' => 'USER_INACTIVE'
                ];
            }
            
            // Remove sensitive information
            unset($user['password_hash']);
            unset($user['mfa_secret']);
            unset($user['backup_codes']);
            
            return [
                'valid' => true,
                'user' => $user
            ];
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => 'User validation error',
                'code' => 'USER_VALIDATION_ERROR'
            ];
        }
    }

    /**
     * Validate scopes
     */
    private function validateScopes(array $tokenScopes, array $requiredScopes): array
    {
        $missingScopes = [];
        
        foreach ($requiredScopes as $requiredScope) {
            if (!in_array($requiredScope, $tokenScopes)) {
                // Check for wildcard scopes
                $hasWildcard = false;
                foreach ($tokenScopes as $tokenScope) {
                    if ($this->scopeMatches($tokenScope, $requiredScope)) {
                        $hasWildcard = true;
                        break;
                    }
                }
                
                if (!$hasWildcard) {
                    $missingScopes[] = $requiredScope;
                }
            }
        }
        
        if (!empty($missingScopes)) {
            return [
                'valid' => false,
                'message' => 'Insufficient scopes: ' . implode(', ', $missingScopes),
                'missing_scopes' => $missingScopes
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Check if scope matches (supports wildcards)
     */
    private function scopeMatches(string $tokenScope, string $requiredScope): bool
    {
        // Support wildcard scopes like "user:*" matching "user:read", "user:write", etc.
        if (str_ends_with($tokenScope, ':*')) {
            $prefix = substr($tokenScope, 0, -2);
            return str_starts_with($requiredScope, $prefix . ':');
        }
        
        return $tokenScope === $requiredScope;
    }

    /**
     * Add token to revocation list
     */
    private function addToRevocationList(string $tokenId, string $reason): void
    {
        // This would store in database or distributed cache
        // For now, we'll use in-memory storage (not persistent)
        static $revokedTokens = [];
        $revokedTokens[$tokenId] = [
            'revoked_at' => time(),
            'reason' => $reason
        ];
    }

    /**
     * Clear token from cache
     */
    private function clearTokenFromCache(string $tokenId): void
    {
        // Remove all cache entries that might contain this token
        foreach ($this->cache as $key => $entry) {
            if (isset($entry['result']['token_info']['jti']) && 
                $entry['result']['token_info']['jti'] === $tokenId) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Calculate cache hit ratio
     */
    private function calculateCacheHitRatio(): float
    {
        // This would track hits/misses over time
        // For now, return a placeholder
        return 0.85; // 85% hit ratio
    }
}