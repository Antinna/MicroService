<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\ServiceTokenManager;
use Antinna\Auth\Services\TokenValidator;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Exception;

/**
 * Service Token Management API Controller
 */
class ServiceTokenController
{
    private ServiceTokenManager $serviceTokenManager;
    private TokenValidator $tokenValidator;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->serviceTokenManager = new ServiceTokenManager();
        $this->tokenValidator = new TokenValidator();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * Generate service token
     * POST /api/service-tokens/generate
     */
    public function generateToken(): void
    {
        try {
            // Validate authentication (only admin services can generate tokens)
            $authResult = $this->validateServiceAuthentication(['service:admin']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['service_id'])) {
                $this->sendError('Service ID is required', 400, 'MISSING_SERVICE_ID');
                return;
            }

            $serviceId = $input['service_id'];
            $scopes = $input['scopes'] ?? [ServiceTokenManager::SCOPE_SERVICE_ACCESS];
            $expirationTime = $input['expiration_time'] ?? 3600; // 1 hour default

            // Validate expiration time
            if ($expirationTime > 86400) { // Max 24 hours
                $this->sendError('Maximum expiration time is 24 hours', 400, 'INVALID_EXPIRATION');
                return;
            }

            // Rate limiting for token generation
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['service_id'],
                RateLimiter::LIMIT_TYPE_SERVICE,
                'token_generation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Generate service token
            $result = $this->serviceTokenManager->generateServiceToken($serviceId, $scopes, $expirationTime);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'token' => $result['token'],
                'token_id' => $result['token_id'],
                'service_id' => $result['service_id'],
                'scopes' => $result['scopes'],
                'expires_at' => date('Y-m-d H:i:s', $result['expires_at']),
                'expires_in' => $result['expires_in']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_token_generation_api_error',
                'Service token generation API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Service token generation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Validate service token
     * POST /api/service-tokens/validate
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
            $expectedService = $input['expected_service'] ?? null;
            $requiredScopes = $input['required_scopes'] ?? [];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'service_token_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate service token
            $result = $this->serviceTokenManager->validateServiceToken($token, $expectedService, $requiredScopes);

            if (!$result['valid']) {
                $this->sendError(
                    $result['message'],
                    $this->getStatusCodeFromValidation($result['code']),
                    $result['code'],
                    [
                        'validation_time' => $result['validation_time'],
                        'required_scopes' => $requiredScopes,
                        'available_scopes' => $result['available_scopes'] ?? []
                    ]
                );
                return;
            }

            $this->sendSuccess([
                'valid' => true,
                'service_id' => $result['service_id'],
                'service_name' => $result['service_name'],
                'token_id' => $result['token_id'],
                'scopes' => $result['scopes'],
                'issued_at' => $result['issued_at'] ? date('Y-m-d H:i:s', $result['issued_at']) : null,
                'expires_at' => $result['expires_at'] ? date('Y-m-d H:i:s', $result['expires_at']) : null,
                'validation_time' => $result['validation_time']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_token_validation_api_error',
                'Service token validation API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Service token validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Revoke service token
     * POST /api/service-tokens/revoke
     */
    public function revokeToken(): void
    {
        try {
            // Validate authentication (only admin services can revoke tokens)
            $authResult = $this->validateServiceAuthentication(['service:admin']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['token_id'])) {
                $this->sendError('Token ID is required', 400, 'MISSING_TOKEN_ID');
                return;
            }

            $tokenId = $input['token_id'];
            $reason = $input['reason'] ?? 'Manual revocation';

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['service_id'],
                RateLimiter::LIMIT_TYPE_SERVICE,
                'token_revocation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Revoke service token
            $result = $this->serviceTokenManager->revokeServiceToken($tokenId, $reason);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'message' => $result['message'],
                'token_id' => $result['token_id'],
                'reason' => $result['reason'],
                'revoked_at' => $result['revoked_at']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_token_revocation_api_error',
                'Service token revocation API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Service token revocation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Register a new service
     * POST /api/services/register
     */
    public function registerService(): void
    {
        try {
            // Validate authentication (only super admin services can register new services)
            $authResult = $this->validateServiceAuthentication(['service:admin', 'system:config']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['service_id']) || !isset($input['config'])) {
                $this->sendError('Service ID and config are required', 400, 'MISSING_REQUIRED_FIELDS');
                return;
            }

            $serviceId = $input['service_id'];
            $config = $input['config'];

            // Rate limiting for service registration
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['service_id'],
                RateLimiter::LIMIT_TYPE_SERVICE,
                'service_registration'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Register service
            $result = $this->serviceTokenManager->registerService($serviceId, $config);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'message' => $result['message'],
                'service_id' => $result['service_id'],
                'registered_at' => $result['registered_at']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_registration_api_error',
                'Service registration API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Service registration system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get service information
     * GET /api/services/{serviceId}
     */
    public function getServiceInfo(string $serviceId): void
    {
        try {
            // Validate authentication
            $authResult = $this->validateServiceAuthentication(['service:access']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['service_id'],
                RateLimiter::LIMIT_TYPE_SERVICE,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get service information
            $result = $this->serviceTokenManager->getServiceInfo($serviceId);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'service_id' => $result['service_id'],
                'service_info' => $result['service_info']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_info_api_error',
                'Service info API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Service info system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * List all registered services
     * GET /api/services
     */
    public function listServices(): void
    {
        try {
            // Validate authentication
            $authResult = $this->validateServiceAuthentication(['service:access']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['service_id'],
                RateLimiter::LIMIT_TYPE_SERVICE,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // List services
            $result = $this->serviceTokenManager->listServices();

            $this->sendSuccess([
                'services' => $result['services'],
                'total_count' => $result['total_count']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'list_services_api_error',
                'List services API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('List services system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Generate API key for external integration
     * POST /api/api-keys/generate
     */
    public function generateApiKey(): void
    {
        try {
            // Validate authentication (only admin services can generate API keys)
            $authResult = $this->validateServiceAuthentication(['service:admin']);
            if (!$authResult['authenticated']) {
                $this->sendError($authResult['message'], $authResult['status_code'], $authResult['code']);
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['service_id'])) {
                $this->sendError('Service ID is required', 400, 'MISSING_SERVICE_ID');
                return;
            }

            $serviceId = $input['service_id'];
            $scopes = $input['scopes'] ?? [ServiceTokenManager::SCOPE_API_READ];
            $expirationDays = $input['expiration_days'] ?? 365;

            // Validate expiration days
            if ($expirationDays > 1095) { // Max 3 years
                $this->sendError('Maximum expiration is 3 years', 400, 'INVALID_EXPIRATION');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $authResult['service_id'],
                RateLimiter::LIMIT_TYPE_SERVICE,
                'api_key_generation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Generate API key
            $result = $this->serviceTokenManager->generateApiKey($serviceId, $scopes, $expirationDays);

            if (!$result['success']) {
                $this->sendError($result['message'], $this->getStatusCodeFromError($result['code']), $result['code']);
                return;
            }

            $this->sendSuccess([
                'api_key' => $result['api_key'],
                'key_id' => $result['key_id'],
                'service_id' => $result['service_id'],
                'scopes' => $result['scopes'],
                'expires_at' => $result['expires_at'],
                'expires_in_days' => $result['expires_in_days']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'api_key_generation_api_error',
                'API key generation API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('API key generation system error', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Validate API key
     * POST /api/api-keys/validate
     */
    public function validateApiKey(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['api_key'])) {
                $this->sendError('API key is required', 400, 'MISSING_API_KEY');
                return;
            }

            $apiKey = $input['api_key'];
            $requiredScopes = $input['required_scopes'] ?? [];

            // Rate limiting
            $ipAddress = $this->getRealIpAddress();
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'api_key_validation'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Validate API key
            $result = $this->serviceTokenManager->validateApiKey($apiKey, $requiredScopes);

            if (!$result['valid']) {
                $this->sendError(
                    $result['message'],
                    $this->getStatusCodeFromValidation($result['code']),
                    $result['code'],
                    ['validation_time' => $result['validation_time']]
                );
                return;
            }

            $this->sendSuccess([
                'valid' => true,
                'service_id' => $result['service_id'],
                'key_id' => $result['key_id'],
                'scopes' => $result['scopes'],
                'expires_at' => $result['expires_at'],
                'validation_time' => $result['validation_time']
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'api_key_validation_api_error',
                'API key validation API error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('API key validation system error', 500, 'INTERNAL_ERROR');
        }
    }

    // Private helper methods

    /**
     * Validate service authentication
     */
    private function validateServiceAuthentication(array $requiredScopes = []): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($authHeader)) {
            return [
                'authenticated' => false,
                'message' => 'Missing Authorization header',
                'code' => 'MISSING_AUTH_HEADER',
                'status_code' => 401
            ];
        }

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return [
                'authenticated' => false,
                'message' => 'Invalid Authorization header format',
                'code' => 'INVALID_AUTH_HEADER',
                'status_code' => 401
            ];
        }

        $token = $matches[1];
        $validation = $this->tokenValidator->validateServiceToken($token);

        if (!$validation['valid']) {
            return [
                'authenticated' => false,
                'message' => $validation['message'] ?? 'Service token validation failed',
                'code' => $validation['code'] ?? 'INVALID_SERVICE_TOKEN',
                'status_code' => 401
            ];
        }

        // Check required scopes
        if (!empty($requiredScopes)) {
            $tokenScopes = $validation['scopes'] ?? [];
            $missingScopes = array_diff($requiredScopes, $tokenScopes);
            
            if (!empty($missingScopes)) {
                return [
                    'authenticated' => false,
                    'message' => 'Insufficient scopes: ' . implode(', ', $missingScopes),
                    'code' => 'INSUFFICIENT_SCOPES',
                    'status_code' => 403
                ];
            }
        }

        return [
            'authenticated' => true,
            'service_id' => $validation['service_id'],
            'token_id' => $validation['token_id'],
            'scopes' => $validation['scopes']
        ];
    }

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
     * Get HTTP status code from error code
     */
    private function getStatusCodeFromError(string $code): int
    {
        switch ($code) {
            case 'INVALID_SERVICE_ID':
            case 'INVALID_CONFIG':
            case 'INVALID_SCOPES':
                return 400;
            case 'SERVICE_NOT_FOUND':
            case 'TOKEN_NOT_FOUND':
                return 404;
            case 'SYSTEM_ERROR':
            default:
                return 500;
        }
    }

    /**
     * Get HTTP status code from validation code
     */
    private function getStatusCodeFromValidation(string $code): int
    {
        switch ($code) {
            case 'INVALID_TOKEN':
            case 'INVALID_TOKEN_TYPE':
            case 'INVALID_SERVICE_ID':
            case 'INVALID_API_KEY':
                return 400;
            case 'TOKEN_REVOKED':
            case 'EXPIRED_API_KEY':
            case 'INACTIVE_API_KEY':
                return 401;
            case 'SERVICE_MISMATCH':
            case 'INSUFFICIENT_SCOPES':
                return 403;
            case 'VALIDATION_ERROR':
            default:
                return 500;
        }
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