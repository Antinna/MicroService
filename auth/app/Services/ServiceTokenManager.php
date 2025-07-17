<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Exception;

/**
 * Service Token Management for Service-to-Service Authentication
 */
class ServiceTokenManager
{
    private JWTManager $jwtManager;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;
    private array $serviceRegistry = [];
    private array $tokenCache = [];

    // Service token types
    public const TOKEN_TYPE_SERVICE = 'service';
    public const TOKEN_TYPE_API_KEY = 'api_key';
    public const TOKEN_TYPE_INTERNAL = 'internal';

    // Service scopes
    public const SCOPE_SERVICE_ACCESS = 'service:access';
    public const SCOPE_SERVICE_ADMIN = 'service:admin';
    public const SCOPE_API_READ = 'api:read';
    public const SCOPE_API_WRITE = 'api:write';
    public const SCOPE_INTERNAL_COMMUNICATION = 'internal:communication';

    // Registered services
    public const SERVICE_AUTH = 'auth-service';
    public const SERVICE_PAYMENT = 'payment-service';
    public const SERVICE_DELIVERY = 'delivery-service';
    public const SERVICE_MULTIVENDOR = 'multivendor-service';
    public const SERVICE_SOCIAL = 'social-service';
    public const SERVICE_API_GATEWAY = 'api-gateway';

    public function __construct()
    {
        $this->jwtManager = new JWTManager();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
        $this->initializeServiceRegistry();
    }

    /**
     * Generate service token for service-to-service authentication
     */
    public function generateServiceToken(string $serviceId, array $scopes = [], int $expirationTime = 3600): array
    {
        try {
            // Validate service
            if (!$this->isValidService($serviceId)) {
                return [
                    'success' => false,
                    'message' => 'Invalid service ID',
                    'code' => 'INVALID_SERVICE_ID'
                ];
            }

            // Get service configuration
            $serviceConfig = $this->serviceRegistry[$serviceId];

            // Validate requested scopes
            $validatedScopes = $this->validateServiceScopes($serviceId, $scopes);
            if (!$validatedScopes['valid']) {
                return [
                    'success' => false,
                    'message' => $validatedScopes['message'],
                    'code' => 'INVALID_SCOPES'
                ];
            }

            // Generate unique token ID
            $tokenId = $this->generateTokenId($serviceId);

            // Create token payload
            $payload = [
                'service_id' => $serviceId,
                'service_name' => $serviceConfig['name'],
                'token_type' => self::TOKEN_TYPE_SERVICE,
                'scopes' => $validatedScopes['scopes'],
                'token_id' => $tokenId,
                'iss' => 'auth-service',
                'aud' => 'service-mesh',
                'sub' => $serviceId,
                'iat' => time(),
                'exp' => time() + $expirationTime,
                'jti' => $tokenId
            ];

            // Generate JWT token
            $tokenResult = $this->jwtManager->generateToken($payload, $expirationTime);
            
            if (!$tokenResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to generate service token',
                    'code' => 'TOKEN_GENERATION_FAILED'
                ];
            }

            // Store token metadata
            $this->storeTokenMetadata($tokenId, $serviceId, $payload);

            // Log token generation
            $this->auditLogger->logSystemEvent(
                'service_token_generated',
                "Service token generated for $serviceId",
                AuditLogger::SEVERITY_INFO,
                [
                    'service_id' => $serviceId,
                    'token_id' => $tokenId,
                    'scopes' => $validatedScopes['scopes'],
                    'expires_at' => date('Y-m-d H:i:s', $payload['exp'])
                ]
            );

            return [
                'success' => true,
                'token' => $tokenResult['token'],
                'token_id' => $tokenId,
                'service_id' => $serviceId,
                'scopes' => $validatedScopes['scopes'],
                'expires_at' => $payload['exp'],
                'expires_in' => $expirationTime
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_token_generation_error',
                'Service token generation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['service_id' => $serviceId]
            );

            return [
                'success' => false,
                'message' => 'Service token generation system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Validate service token
     */
    public function validateServiceToken(string $token, string $expectedService = null, array $requiredScopes = []): array
    {
        try {
            $startTime = microtime(true);

            // Validate JWT structure and signature
            $jwtResult = $this->jwtManager->validateToken($token);
            if (!$jwtResult['success']) {
                return [
                    'valid' => false,
                    'message' => $jwtResult['message'] ?? 'Invalid token',
                    'code' => $jwtResult['code'] ?? 'INVALID_TOKEN',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            $payload = $jwtResult['payload'];

            // Verify it's a service token
            if (($payload['token_type'] ?? '') !== self::TOKEN_TYPE_SERVICE) {
                return [
                    'valid' => false,
                    'message' => 'Not a service token',
                    'code' => 'INVALID_TOKEN_TYPE',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Verify service ID
            $serviceId = $payload['service_id'] ?? null;
            if (!$serviceId || !$this->isValidService($serviceId)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid service ID in token',
                    'code' => 'INVALID_SERVICE_ID',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Check expected service if specified
            if ($expectedService && $serviceId !== $expectedService) {
                return [
                    'valid' => false,
                    'message' => 'Token not issued for expected service',
                    'code' => 'SERVICE_MISMATCH',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Check if token is revoked
            $tokenId = $payload['jti'] ?? $payload['token_id'] ?? null;
            if ($tokenId && $this->isTokenRevoked($tokenId)) {
                return [
                    'valid' => false,
                    'message' => 'Service token has been revoked',
                    'code' => 'TOKEN_REVOKED',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Validate required scopes
            $tokenScopes = $payload['scopes'] ?? [];
            if (!empty($requiredScopes)) {
                $scopeValidation = $this->validateRequiredScopes($tokenScopes, $requiredScopes);
                if (!$scopeValidation['valid']) {
                    return [
                        'valid' => false,
                        'message' => $scopeValidation['message'],
                        'code' => 'INSUFFICIENT_SCOPES',
                        'required_scopes' => $requiredScopes,
                        'available_scopes' => $tokenScopes,
                        'validation_time' => microtime(true) - $startTime
                    ];
                }
            }

            // Log successful validation
            $this->auditLogger->logSystemEvent(
                'service_token_validated',
                "Service token validated for $serviceId",
                AuditLogger::SEVERITY_INFO,
                [
                    'service_id' => $serviceId,
                    'token_id' => $tokenId,
                    'scopes' => $tokenScopes,
                    'validation_time' => microtime(true) - $startTime
                ]
            );

            return [
                'valid' => true,
                'service_id' => $serviceId,
                'service_name' => $payload['service_name'] ?? $serviceId,
                'token_id' => $tokenId,
                'scopes' => $tokenScopes,
                'issued_at' => $payload['iat'] ?? null,
                'expires_at' => $payload['exp'] ?? null,
                'validation_time' => microtime(true) - $startTime
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_token_validation_error',
                'Service token validation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['token_preview' => substr($token, 0, 20) . '...']
            );

            return [
                'valid' => false,
                'message' => 'Service token validation system error',
                'code' => 'VALIDATION_ERROR',
                'validation_time' => microtime(true) - ($startTime ?? microtime(true))
            ];
        }
    }

    /**
     * Revoke service token
     */
    public function revokeServiceToken(string $tokenId, string $reason = 'Manual revocation'): array
    {
        try {
            // Check if token exists
            $tokenMetadata = $this->getTokenMetadata($tokenId);
            if (!$tokenMetadata) {
                return [
                    'success' => false,
                    'message' => 'Token not found',
                    'code' => 'TOKEN_NOT_FOUND'
                ];
            }

            // Add to revocation list
            $this->addToRevocationList($tokenId, $reason);

            // Remove from active tokens
            $this->removeTokenMetadata($tokenId);

            // Log revocation
            $this->auditLogger->logSystemEvent(
                'service_token_revoked',
                "Service token revoked: $reason",
                AuditLogger::SEVERITY_WARNING,
                [
                    'token_id' => $tokenId,
                    'service_id' => $tokenMetadata['service_id'] ?? 'unknown',
                    'reason' => $reason,
                    'revoked_at' => date('Y-m-d H:i:s')
                ]
            );

            return [
                'success' => true,
                'message' => 'Service token revoked successfully',
                'token_id' => $tokenId,
                'reason' => $reason,
                'revoked_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_token_revocation_error',
                'Service token revocation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['token_id' => $tokenId]
            );

            return [
                'success' => false,
                'message' => 'Service token revocation system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Register a new service
     */
    public function registerService(string $serviceId, array $config): array
    {
        try {
            // Validate service configuration
            $validation = $this->validateServiceConfig($config);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message'],
                    'code' => 'INVALID_CONFIG'
                ];
            }

            // Register service
            $this->serviceRegistry[$serviceId] = array_merge($config, [
                'registered_at' => date('Y-m-d H:i:s'),
                'status' => 'active'
            ]);

            // Log service registration
            $this->auditLogger->logSystemEvent(
                'service_registered',
                "Service $serviceId registered",
                AuditLogger::SEVERITY_INFO,
                [
                    'service_id' => $serviceId,
                    'service_name' => $config['name'] ?? $serviceId,
                    'allowed_scopes' => $config['allowed_scopes'] ?? []
                ]
            );

            return [
                'success' => true,
                'message' => 'Service registered successfully',
                'service_id' => $serviceId,
                'registered_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'service_registration_error',
                'Service registration error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['service_id' => $serviceId]
            );

            return [
                'success' => false,
                'message' => 'Service registration system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Get service information
     */
    public function getServiceInfo(string $serviceId): array
    {
        if (!$this->isValidService($serviceId)) {
            return [
                'success' => false,
                'message' => 'Service not found',
                'code' => 'SERVICE_NOT_FOUND'
            ];
        }

        $serviceConfig = $this->serviceRegistry[$serviceId];
        
        return [
            'success' => true,
            'service_id' => $serviceId,
            'service_info' => [
                'name' => $serviceConfig['name'],
                'description' => $serviceConfig['description'] ?? '',
                'allowed_scopes' => $serviceConfig['allowed_scopes'] ?? [],
                'registered_at' => $serviceConfig['registered_at'] ?? null,
                'status' => $serviceConfig['status'] ?? 'unknown'
            ]
        ];
    }

    /**
     * List all registered services
     */
    public function listServices(): array
    {
        $services = [];
        
        foreach ($this->serviceRegistry as $serviceId => $config) {
            $services[] = [
                'service_id' => $serviceId,
                'name' => $config['name'],
                'description' => $config['description'] ?? '',
                'status' => $config['status'] ?? 'unknown',
                'registered_at' => $config['registered_at'] ?? null
            ];
        }

        return [
            'success' => true,
            'services' => $services,
            'total_count' => count($services)
        ];
    }

    /**
     * Generate API key for external service integration
     */
    public function generateApiKey(string $serviceId, array $scopes = [], int $expirationDays = 365): array
    {
        try {
            if (!$this->isValidService($serviceId)) {
                return [
                    'success' => false,
                    'message' => 'Invalid service ID',
                    'code' => 'INVALID_SERVICE_ID'
                ];
            }

            // Generate API key
            $apiKey = 'ak_' . bin2hex(random_bytes(32));
            $keyId = 'key_' . bin2hex(random_bytes(16));

            // Create API key metadata
            $keyData = [
                'key_id' => $keyId,
                'service_id' => $serviceId,
                'api_key_hash' => hash('sha256', $apiKey),
                'scopes' => $scopes,
                'created_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', strtotime("+{$expirationDays} days")),
                'status' => 'active'
            ];

            // Store API key metadata (this would typically be in database)
            $this->storeApiKeyMetadata($keyId, $keyData);

            // Log API key generation
            $this->auditLogger->logSystemEvent(
                'api_key_generated',
                "API key generated for $serviceId",
                AuditLogger::SEVERITY_INFO,
                [
                    'service_id' => $serviceId,
                    'key_id' => $keyId,
                    'scopes' => $scopes,
                    'expires_at' => $keyData['expires_at']
                ]
            );

            return [
                'success' => true,
                'api_key' => $apiKey,
                'key_id' => $keyId,
                'service_id' => $serviceId,
                'scopes' => $scopes,
                'expires_at' => $keyData['expires_at'],
                'expires_in_days' => $expirationDays
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'api_key_generation_error',
                'API key generation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['service_id' => $serviceId]
            );

            return [
                'success' => false,
                'message' => 'API key generation system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Validate API key
     */
    public function validateApiKey(string $apiKey, array $requiredScopes = []): array
    {
        try {
            $startTime = microtime(true);

            // Hash the provided API key
            $keyHash = hash('sha256', $apiKey);

            // Find API key metadata
            $keyData = $this->findApiKeyByHash($keyHash);
            if (!$keyData) {
                return [
                    'valid' => false,
                    'message' => 'Invalid API key',
                    'code' => 'INVALID_API_KEY',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Check if key is active
            if ($keyData['status'] !== 'active') {
                return [
                    'valid' => false,
                    'message' => 'API key is inactive',
                    'code' => 'INACTIVE_API_KEY',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Check expiration
            if (strtotime($keyData['expires_at']) < time()) {
                return [
                    'valid' => false,
                    'message' => 'API key has expired',
                    'code' => 'EXPIRED_API_KEY',
                    'validation_time' => microtime(true) - $startTime
                ];
            }

            // Validate required scopes
            if (!empty($requiredScopes)) {
                $scopeValidation = $this->validateRequiredScopes($keyData['scopes'], $requiredScopes);
                if (!$scopeValidation['valid']) {
                    return [
                        'valid' => false,
                        'message' => $scopeValidation['message'],
                        'code' => 'INSUFFICIENT_SCOPES',
                        'validation_time' => microtime(true) - $startTime
                    ];
                }
            }

            // Log successful validation
            $this->auditLogger->logSystemEvent(
                'api_key_validated',
                "API key validated for {$keyData['service_id']}",
                AuditLogger::SEVERITY_INFO,
                [
                    'service_id' => $keyData['service_id'],
                    'key_id' => $keyData['key_id'],
                    'scopes' => $keyData['scopes'],
                    'validation_time' => microtime(true) - $startTime
                ]
            );

            return [
                'valid' => true,
                'service_id' => $keyData['service_id'],
                'key_id' => $keyData['key_id'],
                'scopes' => $keyData['scopes'],
                'expires_at' => $keyData['expires_at'],
                'validation_time' => microtime(true) - $startTime
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'api_key_validation_error',
                'API key validation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );

            return [
                'valid' => false,
                'message' => 'API key validation system error',
                'code' => 'VALIDATION_ERROR',
                'validation_time' => microtime(true) - ($startTime ?? microtime(true))
            ];
        }
    }

    // Private helper methods

    /**
     * Initialize service registry with default services
     */
    private function initializeServiceRegistry(): void
    {
        $this->serviceRegistry = [
            self::SERVICE_AUTH => [
                'name' => 'Authentication Service',
                'description' => 'Core authentication and authorization service',
                'allowed_scopes' => [
                    self::SCOPE_SERVICE_ACCESS,
                    self::SCOPE_SERVICE_ADMIN,
                    self::SCOPE_API_READ,
                    self::SCOPE_API_WRITE,
                    self::SCOPE_INTERNAL_COMMUNICATION
                ],
                'status' => 'active'
            ],
            self::SERVICE_PAYMENT => [
                'name' => 'Payment Service',
                'description' => 'Payment processing and transaction management',
                'allowed_scopes' => [
                    self::SCOPE_SERVICE_ACCESS,
                    self::SCOPE_API_READ,
                    self::SCOPE_API_WRITE,
                    'payment:process',
                    'payment:refund'
                ],
                'status' => 'active'
            ],
            self::SERVICE_DELIVERY => [
                'name' => 'Delivery Service',
                'description' => 'Delivery management and logistics',
                'allowed_scopes' => [
                    self::SCOPE_SERVICE_ACCESS,
                    self::SCOPE_API_READ,
                    self::SCOPE_API_WRITE,
                    'delivery:manage',
                    'delivery:track'
                ],
                'status' => 'active'
            ],
            self::SERVICE_MULTIVENDOR => [
                'name' => 'Multi-vendor Service',
                'description' => 'Vendor management and marketplace operations',
                'allowed_scopes' => [
                    self::SCOPE_SERVICE_ACCESS,
                    self::SCOPE_API_READ,
                    self::SCOPE_API_WRITE,
                    'vendor:manage',
                    'product:manage'
                ],
                'status' => 'active'
            ],
            self::SERVICE_SOCIAL => [
                'name' => 'Social Service',
                'description' => 'Social features and communication',
                'allowed_scopes' => [
                    self::SCOPE_SERVICE_ACCESS,
                    self::SCOPE_API_READ,
                    self::SCOPE_API_WRITE,
                    'social:chat',
                    'social:video'
                ],
                'status' => 'active'
            ],
            self::SERVICE_API_GATEWAY => [
                'name' => 'API Gateway',
                'description' => 'API gateway and routing service',
                'allowed_scopes' => [
                    self::SCOPE_SERVICE_ACCESS,
                    self::SCOPE_SERVICE_ADMIN,
                    self::SCOPE_API_READ,
                    self::SCOPE_API_WRITE,
                    self::SCOPE_INTERNAL_COMMUNICATION
                ],
                'status' => 'active'
            ]
        ];
    }

    /**
     * Check if service ID is valid
     */
    private function isValidService(string $serviceId): bool
    {
        return isset($this->serviceRegistry[$serviceId]);
    }

    /**
     * Validate service scopes
     */
    private function validateServiceScopes(string $serviceId, array $requestedScopes): array
    {
        $serviceConfig = $this->serviceRegistry[$serviceId];
        $allowedScopes = $serviceConfig['allowed_scopes'] ?? [];

        $invalidScopes = [];
        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, $allowedScopes)) {
                $invalidScopes[] = $scope;
            }
        }

        if (!empty($invalidScopes)) {
            return [
                'valid' => false,
                'message' => 'Invalid scopes for service: ' . implode(', ', $invalidScopes),
                'invalid_scopes' => $invalidScopes,
                'allowed_scopes' => $allowedScopes
            ];
        }

        return [
            'valid' => true,
            'scopes' => $requestedScopes
        ];
    }

    /**
     * Validate required scopes
     */
    private function validateRequiredScopes(array $availableScopes, array $requiredScopes): array
    {
        $missingScopes = [];
        
        foreach ($requiredScopes as $requiredScope) {
            if (!in_array($requiredScope, $availableScopes)) {
                $missingScopes[] = $requiredScope;
            }
        }

        if (!empty($missingScopes)) {
            return [
                'valid' => false,
                'message' => 'Missing required scopes: ' . implode(', ', $missingScopes),
                'missing_scopes' => $missingScopes
            ];
        }

        return ['valid' => true];
    }

    /**
     * Generate unique token ID
     */
    private function generateTokenId(string $serviceId): string
    {
        return 'st_' . $serviceId . '_' . bin2hex(random_bytes(16));
    }

    /**
     * Store token metadata (placeholder - would use database)
     */
    private function storeTokenMetadata(string $tokenId, string $serviceId, array $payload): void
    {
        $this->tokenCache[$tokenId] = [
            'service_id' => $serviceId,
            'payload' => $payload,
            'created_at' => time()
        ];
    }

    /**
     * Get token metadata (placeholder - would query database)
     */
    private function getTokenMetadata(string $tokenId): ?array
    {
        return $this->tokenCache[$tokenId] ?? null;
    }

    /**
     * Remove token metadata
     */
    private function removeTokenMetadata(string $tokenId): void
    {
        unset($this->tokenCache[$tokenId]);
    }

    /**
     * Check if token is revoked (placeholder - would check database)
     */
    private function isTokenRevoked(string $tokenId): bool
    {
        // This would check a revocation list in database or cache
        return false;
    }

    /**
     * Add token to revocation list (placeholder - would update database)
     */
    private function addToRevocationList(string $tokenId, string $reason): void
    {
        // This would add to revocation list in database
    }

    /**
     * Validate service configuration
     */
    private function validateServiceConfig(array $config): array
    {
        $required = ['name', 'allowed_scopes'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                return [
                    'valid' => false,
                    'message' => "Missing required field: $field"
                ];
            }
        }

        if (!is_array($config['allowed_scopes'])) {
            return [
                'valid' => false,
                'message' => 'allowed_scopes must be an array'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Store API key metadata (placeholder - would use database)
     */
    private function storeApiKeyMetadata(string $keyId, array $keyData): void
    {
        // This would store in database
    }

    /**
     * Find API key by hash (placeholder - would query database)
     */
    private function findApiKeyByHash(string $keyHash): ?array
    {
        // This would query database for API key by hash
        return null;
    }
}