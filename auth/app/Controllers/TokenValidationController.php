<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\TokenValidator;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Exception;

/**
 * Token Validation API Controller for Service-to-Service Authentication
 */
class TokenValidationController
{
    private TokenValidator $tokenValidator;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->tokenValidator = new TokenValidator();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * Validate a single token
     * POST /api/validate/token
     */
    public function validateToken(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['token'])) {
                $this->sendError('Token is required', 400, 'MISSING_TOKEN');
                return;
            }

            $token = $input['token'];
            $requiredScopes = $input['scopes'] ?? [];
            $useCache = $input['use_cache'] ?? true;

            // Rate limiting based on IP
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'token_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate token
            $validation = $this->tokenValidator->validateToken($token, $requiredScopes, $useCache);

            // Log validation attempt
            $this->auditLogger->logSystemEvent(
                'token_validation_request',
                'Token validation requested',
                AuditLogger::SEVERITY_INFO,
                [
                    'ip_address' => $ipAddress,
                    'valid' => $validation['valid'],
                    'status' => $validation['status'],
                    'cached' => $validation['cached'] ?? false,
                    'validation_time' => $validation['validation_time'] ?? 0,
                    'required_scopes' => $requiredScopes
                ]
            );

            if ($validation['valid']) {
                $this->sendSuccess([
                    'valid' => true,
                    'status' => $validation['status'],
                    'token_info' => $validation['token_info'],
                    'user_info' => $validation['user_info'] ?? null,
                    'scopes' => $validation['scopes'],
                    'validation_time' => $validation['validation_time'],
                    'cached' => $validation['cached'] ?? false
                ]);
            } else {
                $this->sendError(
                    $validation['message'],
                    $this->getHttpStatusFromValidation($validation['status']),
                    $validation['code'] ?? 'VALIDATION_FAILED',
                    [
                        'status' => $validation['status'],
                        'validation_time' => $validation['validation_time'],
                        'cached' => $validation['cached'] ?? false
                    ]
                );
            }

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'token_validation_error',
                'Token validation endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Token validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Validate token and return user information
     * POST /api/validate/user
     */
    public function validateUser(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['token'])) {
                $this->sendError('Token is required', 400, 'MISSING_TOKEN');
                return;
            }

            $token = $input['token'];
            $requiredScopes = $input['scopes'] ?? [];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'token_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate token and get user
            $validation = $this->tokenValidator->validateAndGetUser($token, $requiredScopes);

            // Log validation attempt
            $this->auditLogger->logSystemEvent(
                'user_validation_request',
                'User validation via token requested',
                AuditLogger::SEVERITY_INFO,
                [
                    'ip_address' => $ipAddress,
                    'valid' => $validation['valid'],
                    'user_id' => $validation['user']['id'] ?? null,
                    'required_scopes' => $requiredScopes
                ]
            );

            if ($validation['valid']) {
                $this->sendSuccess([
                    'valid' => true,
                    'user' => $validation['user'],
                    'token_info' => $validation['token_info'],
                    'scopes' => $validation['scopes']
                ]);
            } else {
                $this->sendError(
                    $validation['message'] ?? 'User validation failed',
                    $this->getHttpStatusFromValidation($validation['status'] ?? 'invalid'),
                    $validation['code'] ?? 'USER_VALIDATION_FAILED'
                );
            }

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'user_validation_error',
                'User validation endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('User validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Validate service token
     * POST /api/validate/service
     */
    public function validateService(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['token'])) {
                $this->sendError('Token is required', 400, 'MISSING_TOKEN');
                return;
            }

            $token = $input['token'];
            $expectedService = $input['service_id'] ?? null;

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'token_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate service token
            $validation = $this->tokenValidator->validateServiceToken($token, $expectedService);

            // Log validation attempt
            $this->auditLogger->logSystemEvent(
                'service_validation_request',
                'Service token validation requested',
                AuditLogger::SEVERITY_INFO,
                [
                    'ip_address' => $ipAddress,
                    'valid' => $validation['valid'],
                    'service_id' => $validation['service_id'] ?? null,
                    'expected_service' => $expectedService
                ]
            );

            if ($validation['valid']) {
                $this->sendSuccess([
                    'valid' => true,
                    'service_id' => $validation['service_id'],
                    'token_info' => $validation['token_info'],
                    'scopes' => $validation['scopes']
                ]);
            } else {
                $this->sendError(
                    $validation['message'] ?? 'Service validation failed',
                    $this->getHttpStatusFromValidation($validation['status'] ?? 'invalid'),
                    $validation['code'] ?? 'SERVICE_VALIDATION_FAILED'
                );
            }

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_validation_error',
                'Service validation endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Service validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Batch validate multiple tokens
     * POST /api/validate/batch
     */
    public function validateBatch(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['tokens']) || !is_array($input['tokens'])) {
                $this->sendError('Tokens array is required', 400, 'MISSING_TOKENS');
                return;
            }

            $tokens = $input['tokens'];
            $requiredScopes = $input['scopes'] ?? [];
            $maxBatchSize = 50; // Limit batch size

            if (count($tokens) > $maxBatchSize) {
                $this->sendError("Batch size cannot exceed $maxBatchSize tokens", 400, 'BATCH_TOO_LARGE');
                return;
            }

            // Rate limiting with higher limits for batch operations
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'batch_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate tokens in batch
            $batchResult = $this->tokenValidator->validateTokensBatch($tokens, $requiredScopes);

            // Log batch validation
            $this->auditLogger->logSystemEvent(
                'batch_validation_request',
                'Batch token validation requested',
                AuditLogger::SEVERITY_INFO,
                [
                    'ip_address' => $ipAddress,
                    'total_tokens' => $batchResult['total_tokens'],
                    'valid_tokens' => $batchResult['valid_tokens'],
                    'invalid_tokens' => $batchResult['invalid_tokens'],
                    'batch_validation_time' => $batchResult['batch_validation_time']
                ]
            );

            $this->sendSuccess([
                'batch_results' => $batchResult['results'],
                'summary' => [
                    'total_tokens' => $batchResult['total_tokens'],
                    'valid_tokens' => $batchResult['valid_tokens'],
                    'invalid_tokens' => $batchResult['invalid_tokens'],
                    'batch_validation_time' => $batchResult['batch_validation_time']
                ]
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'batch_validation_error',
                'Batch validation endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Batch validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Inspect token without full validation (for debugging)
     * POST /api/validate/inspect
     */
    public function inspectToken(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['token'])) {
                $this->sendError('Token is required', 400, 'MISSING_TOKEN');
                return;
            }

            $token = $input['token'];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'token_inspection'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Inspect token
            $inspection = $this->tokenValidator->inspectToken($token);

            // Log inspection
            $this->auditLogger->logSystemEvent(
                'token_inspection_request',
                'Token inspection requested',
                AuditLogger::SEVERITY_INFO,
                [
                    'ip_address' => $ipAddress,
                    'valid_structure' => $inspection['valid_structure'],
                    'token_type' => $inspection['token_type'] ?? 'unknown'
                ]
            );

            $this->sendSuccess($inspection);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'token_inspection_error',
                'Token inspection endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Token inspection system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get validation statistics
     * GET /api/validate/stats
     */
    public function getValidationStats(): void
    {
        try {
            // Basic authentication check for stats endpoint
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (!$this->isAuthorizedForStats($authHeader)) {
                $this->sendError('Unauthorized access to validation statistics', 401, 'UNAUTHORIZED');
                return;
            }

            $stats = $this->tokenValidator->getValidationStats();

            $this->sendSuccess([
                'validation_stats' => $stats,
                'generated_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'validation_stats_error',
                'Validation stats endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Stats retrieval system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Clear validation cache
     * POST /api/validate/cache/clear
     */
    public function clearCache(): void
    {
        try {
            // Basic authentication check for cache operations
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (!$this->isAuthorizedForCacheOps($authHeader)) {
                $this->sendError('Unauthorized access to cache operations', 401, 'UNAUTHORIZED');
                return;
            }

            $this->tokenValidator->clearCache();

            $this->auditLogger->logSystemEvent(
                'validation_cache_cleared',
                'Token validation cache cleared',
                AuditLogger::SEVERITY_INFO,
                ['ip_address' => $this->getRealIpAddress()]
            );

            $this->sendSuccess([
                'message' => 'Validation cache cleared successfully',
                'cleared_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'cache_clear_error',
                'Cache clear endpoint error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Cache clear system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Health check endpoint
     * GET /api/validate/health
     */
    public function healthCheck(): void
    {
        try {
            $stats = $this->tokenValidator->getValidationStats();
            
            $health = [
                'status' => 'healthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'cache_size' => $stats['cache_size'],
                'memory_usage' => $stats['memory_usage'],
                'uptime' => $stats['uptime']
            ];

            // Check if system is under stress
            if ($stats['memory_usage'] > 100 * 1024 * 1024) { // 100MB
                $health['status'] = 'warning';
                $health['warnings'] = ['High memory usage'];
            }

            $this->sendSuccess($health);

        } catch (Exception $e) {
            $this->sendError('Health check failed', 500, 'HEALTH_CHECK_FAILED', [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }

    // Private helper methods

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }

        $decoded = json_decode($input, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Get real IP address
     */
    private function getRealIpAddress(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
                return 400;
        }
    }

    /**
     * Check if request is authorized for stats endpoint
     */
    private function isAuthorizedForStats(string $authHeader): bool
    {
        // Simple bearer token check for internal services
        // In production, this would validate service tokens
        return str_starts_with($authHeader, 'Bearer ') && strlen($authHeader) > 20;
    }

    /**
     * Check if request is authorized for cache operations
     */
    private function isAuthorizedForCacheOps(string $authHeader): bool
    {
        // More restrictive check for cache operations
        return str_starts_with($authHeader, 'Bearer ') && strlen($authHeader) > 50;
    }

    /**
     * Send success response
     */
    private function sendSuccess(array $data, string $message = 'Success'): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Send error response
     */
    private function sendError(string $message, int $statusCode = 400, string $code = 'ERROR', array $details = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ];
        
        if (!empty($details)) {
            $response['details'] = $details;
        }
        
        echo json_encode($response);
    }
}