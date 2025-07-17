<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Services\Logger;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\ErrorHandler;
use Antinna\Auth\Repositories\UserRepository;
use Exception;

/**
 * Offline Authentication Service for Mobile Devices
 */
class OfflineAuthService
{
    private Logger $logger;
    private AuditLogger $auditLogger;
    private JWTManager $jwtManager;
    private ErrorHandler $errorHandler;
    private UserRepository $userRepository;
    private array $config;

    // Cache types
    public const CACHE_TYPE_TOKEN = 'token';
    public const CACHE_TYPE_USER_DATA = 'user_data';
    public const CACHE_TYPE_PERMISSIONS = 'permissions';
    public const CACHE_TYPE_BIOMETRIC = 'biometric';
    public const CACHE_TYPE_SETTINGS = 'settings';

    // Sync strategies
    public const SYNC_STRATEGY_IMMEDIATE = 'immediate';
    public const SYNC_STRATEGY_BACKGROUND = 'background';
    public const SYNC_STRATEGY_MANUAL = 'manual';

    // Offline modes
    public const MODE_FULL_OFFLINE = 'full_offline';
    public const MODE_PARTIAL_OFFLINE = 'partial_offline';
    public const MODE_ONLINE_ONLY = 'online_only';

    public function __construct()
    {
        $this->logger = new Logger();
        $this->auditLogger = new AuditLogger();
        $this->jwtManager = new JWTManager();
        $this->errorHandler = new ErrorHandler();
        $this->userRepository = new UserRepository();

        $this->config = [
            'cache_duration' => [
                self::CACHE_TYPE_TOKEN => 86400, // 24 hours
                self::CACHE_TYPE_USER_DATA => 604800, // 7 days
                self::CACHE_TYPE_PERMISSIONS => 3600, // 1 hour
                self::CACHE_TYPE_BIOMETRIC => 2592000, // 30 days
                self::CACHE_TYPE_SETTINGS => 86400 // 24 hours
            ],
            'max_offline_duration' => 604800, // 7 days maximum offline
            'sync_interval' => 300, // 5 minutes
            'cache_encryption_key' => $_ENV['OFFLINE_CACHE_KEY'] ?? 'default_key_change_in_production',
            'enable_biometric_offline' => true,
            'enable_pin_fallback' => true,
            'max_failed_attempts' => 5,
            'lockout_duration' => 1800 // 30 minutes
        ];
    }

    /**
     * Prepare user data for offline access
     */
    public function prepareOfflineData(int $userId, array $options = []): array
    {
        try {
            // Get user data
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Generate offline tokens
            $offlineTokens = $this->generateOfflineTokens($userId, $options);
            
            // Prepare cached data
            $cacheData = [
                'user_data' => $this->sanitizeUserDataForCache($user),
                'permissions' => $this->getUserPermissions($userId),
                'settings' => $this->getUserSettings($userId),
                'biometric_data' => $this->getBiometricDataForOffline($userId),
                'sync_timestamp' => time(),
                'expires_at' => time() + $this->config['max_offline_duration']
            ];

            // Encrypt cache data
            $encryptedCache = $this->encryptCacheData($cacheData);

            // Store offline data
            $cacheId = $this->storeOfflineCache($userId, $encryptedCache);

            // Log offline preparation
            $this->auditLogger->logSystemEvent(
                'offline_data_prepared',
                'User data prepared for offline access',
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'cache_id' => $cacheId,
                    'expires_at' => date('Y-m-d H:i:s', $cacheData['expires_at']),
                    'data_types' => array_keys($cacheData)
                ]
            );

            return [
                'success' => true,
                'message' => 'Offline data prepared successfully',
                'cache_id' => $cacheId,
                'offline_tokens' => $offlineTokens,
                'expires_at' => date('Y-m-d H:i:s', $cacheData['expires_at']),
                'sync_required_at' => date('Y-m-d H:i:s', time() + $this->config['sync_interval'])
            ];

        } catch (Exception $e) {
            $this->logger->error('Offline data preparation error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ], Logger::CHANNEL_APPLICATION);

            return [
                'success' => false,
                'message' => 'Failed to prepare offline data',
                'code' => 'PREPARATION_FAILED'
            ];
        }
    }

    /**
     * Validate offline authentication
     */
    public function validateOfflineAuth(string $cacheId, array $credentials): array
    {
        try {
            // Retrieve cached data
            $cachedData = $this->getOfflineCache($cacheId);
            if (!$cachedData) {
                return [
                    'success' => false,
                    'message' => 'Offline cache not found or expired',
                    'code' => 'CACHE_NOT_FOUND'
                ];
            }

            // Decrypt cache data
            $decryptedData = $this->decryptCacheData($cachedData['data']);
            if (!$decryptedData) {
                return [
                    'success' => false,
                    'message' => 'Failed to decrypt offline cache',
                    'code' => 'DECRYPTION_FAILED'
                ];
            }

            // Check cache expiration
            if ($decryptedData['expires_at'] < time()) {
                return [
                    'success' => false,
                    'message' => 'Offline cache has expired',
                    'code' => 'CACHE_EXPIRED'
                ];
            }

            // Validate credentials
            $validationResult = $this->validateOfflineCredentials($decryptedData, $credentials);
            if (!$validationResult['valid']) {
                // Log failed attempt
                $this->logOfflineAuthAttempt($cacheId, false, $validationResult['reason']);
                
                return [
                    'success' => false,
                    'message' => $validationResult['message'],
                    'code' => $validationResult['code']
                ];
            }

            // Generate offline session token
            $sessionToken = $this->generateOfflineSessionToken($decryptedData['user_data']);

            // Log successful offline authentication
            $this->logOfflineAuthAttempt($cacheId, true, 'offline_auth_success');

            return [
                'success' => true,
                'message' => 'Offline authentication successful',
                'session_token' => $sessionToken,
                'user_data' => $decryptedData['user_data'],
                'permissions' => $decryptedData['permissions'],
                'sync_required' => $this->isSyncRequired($decryptedData),
                'cache_expires_at' => date('Y-m-d H:i:s', $decryptedData['expires_at'])
            ];

        } catch (Exception $e) {
            $this->logger->error('Offline authentication error', [
                'cache_id' => $cacheId,
                'error' => $e->getMessage()
            ], Logger::CHANNEL_APPLICATION);

            return [
                'success' => false,
                'message' => 'Offline authentication failed',
                'code' => 'OFFLINE_AUTH_FAILED'
            ];
        }
    }

    /**
     * Sync offline data with server
     */
    public function syncOfflineData(string $cacheId, array $localChanges = []): array
    {
        try {
            // Get cached data
            $cachedData = $this->getOfflineCache($cacheId);
            if (!$cachedData) {
                return [
                    'success' => false,
                    'message' => 'Cache not found',
                    'code' => 'CACHE_NOT_FOUND'
                ];
            }

            $decryptedData = $this->decryptCacheData($cachedData['data']);
            $userId = $decryptedData['user_data']['id'];

            // Check network connectivity (simulated)
            if (!$this->isNetworkAvailable()) {
                return [
                    'success' => false,
                    'message' => 'Network not available for sync',
                    'code' => 'NETWORK_UNAVAILABLE'
                ];
            }

            // Sync local changes to server
            $syncResults = [];
            if (!empty($localChanges)) {
                $syncResults = $this->syncLocalChangesToServer($userId, $localChanges);
            }

            // Refresh cached data from server
            $refreshResult = $this->refreshOfflineCache($cacheId, $userId);

            // Log sync operation
            $this->auditLogger->logSystemEvent(
                'offline_data_synced',
                'Offline data synchronized with server',
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'cache_id' => $cacheId,
                    'local_changes_count' => count($localChanges),
                    'sync_results' => $syncResults,
                    'refresh_success' => $refreshResult['success']
                ]
            );

            return [
                'success' => true,
                'message' => 'Offline data synchronized successfully',
                'sync_results' => $syncResults,
                'cache_refreshed' => $refreshResult['success'],
                'next_sync_at' => date('Y-m-d H:i:s', time() + $this->config['sync_interval'])
            ];

        } catch (Exception $e) {
            $this->logger->error('Offline sync error', [
                'cache_id' => $cacheId,
                'error' => $e->getMessage()
            ], Logger::CHANNEL_APPLICATION);

            return [
                'success' => false,
                'message' => 'Sync failed',
                'code' => 'SYNC_FAILED'
            ];
        }
    }

    /**
     * Handle graceful degradation when network is unavailable
     */
    public function handleNetworkDegradation(int $userId, string $operation): array
    {
        try {
            // Check if user has offline cache
            $cacheId = $this->getUserCacheId($userId);
            if (!$cacheId) {
                return [
                    'success' => false,
                    'message' => 'No offline cache available',
                    'code' => 'NO_OFFLINE_CACHE',
                    'fallback_options' => $this->getOfflineFallbackOptions()
                ];
            }

            // Get offline capabilities
            $offlineCapabilities = $this->getOfflineCapabilities($cacheId);

            // Determine available operations
            $availableOperations = $this->getAvailableOfflineOperations($offlineCapabilities, $operation);

            return [
                'success' => true,
                'message' => 'Offline mode activated',
                'offline_mode' => $this->determineOfflineMode($offlineCapabilities),
                'available_operations' => $availableOperations,
                'limitations' => $this->getOfflineLimitations(),
                'sync_required' => true,
                'cache_expires_at' => $offlineCapabilities['expires_at']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to handle network degradation',
                'code' => 'DEGRADATION_FAILED'
            ];
        }
    }

    /**
     * Validate offline token
     */
    public function validateOfflineToken(string $token): array
    {
        try {
            // Validate token structure and signature
            $tokenResult = $this->jwtManager->validateToken($token);
            if (!$tokenResult['success']) {
                return [
                    'valid' => false,
                    'message' => 'Invalid offline token',
                    'code' => 'INVALID_TOKEN'
                ];
            }

            $payload = $tokenResult['payload'];

            // Check if it's an offline token
            if (!isset($payload['offline']) || !$payload['offline']) {
                return [
                    'valid' => false,
                    'message' => 'Not an offline token',
                    'code' => 'NOT_OFFLINE_TOKEN'
                ];
            }

            // Check offline-specific expiration
            if (isset($payload['offline_exp']) && $payload['offline_exp'] < time()) {
                return [
                    'valid' => false,
                    'message' => 'Offline token expired',
                    'code' => 'OFFLINE_TOKEN_EXPIRED'
                ];
            }

            return [
                'valid' => true,
                'user_id' => $payload['user_id'],
                'permissions' => $payload['permissions'] ?? [],
                'expires_at' => $payload['offline_exp'] ?? $payload['exp'],
                'cache_id' => $payload['cache_id'] ?? null
            ];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => 'Token validation failed',
                'code' => 'VALIDATION_FAILED'
            ];
        }
    }

    /**
     * Clear offline cache
     */
    public function clearOfflineCache(string $cacheId, int $userId): array
    {
        try {
            $success = $this->removeOfflineCache($cacheId);
            
            // Log cache clearing
            $this->auditLogger->logSystemEvent(
                'offline_cache_cleared',
                'Offline cache cleared',
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'cache_id' => $cacheId,
                    'success' => $success
                ]
            );

            return [
                'success' => $success,
                'message' => $success ? 'Offline cache cleared successfully' : 'Failed to clear cache'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to clear offline cache',
                'code' => 'CLEAR_FAILED'
            ];
        }
    }

    // Private helper methods

    /**
     * Generate offline tokens
     */
    private function generateOfflineTokens(int $userId, array $options): array
    {
        $duration = $options['duration'] ?? $this->config['max_offline_duration'];
        $permissions = $this->getUserPermissions($userId);

        // Generate offline access token
        $accessTokenPayload = [
            'user_id' => $userId,
            'offline' => true,
            'offline_exp' => time() + $duration,
            'permissions' => $permissions,
            'cache_id' => uniqid('cache_', true),
            'iat' => time(),
            'exp' => time() + $duration
        ];

        $accessToken = $this->jwtManager->generateToken($accessTokenPayload, $duration);

        // Generate offline refresh token
        $refreshTokenPayload = [
            'user_id' => $userId,
            'offline' => true,
            'type' => 'refresh',
            'iat' => time(),
            'exp' => time() + ($duration * 2) // Refresh token lasts twice as long
        ];

        $refreshToken = $this->jwtManager->generateToken($refreshTokenPayload, $duration * 2);

        return [
            'access_token' => $accessToken['token'],
            'refresh_token' => $refreshToken['token'],
            'expires_in' => $duration,
            'token_type' => 'offline'
        ];
    }

    /**
     * Validate offline credentials
     */
    private function validateOfflineCredentials(array $cachedData, array $credentials): array
    {
        // Check credential type
        if (isset($credentials['biometric_data']) && $this->config['enable_biometric_offline']) {
            return $this->validateOfflineBiometric($cachedData, $credentials['biometric_data']);
        }

        if (isset($credentials['pin']) && $this->config['enable_pin_fallback']) {
            return $this->validateOfflinePin($cachedData, $credentials['pin']);
        }

        if (isset($credentials['password'])) {
            return $this->validateOfflinePassword($cachedData, $credentials['password']);
        }

        return [
            'valid' => false,
            'message' => 'No valid credentials provided',
            'code' => 'NO_CREDENTIALS',
            'reason' => 'missing_credentials'
        ];
    }

    /**
     * Validate offline biometric
     */
    private function validateOfflineBiometric(array $cachedData, array $biometricData): array
    {
        if (!isset($cachedData['biometric_data']) || empty($cachedData['biometric_data'])) {
            return [
                'valid' => false,
                'message' => 'No biometric data cached',
                'code' => 'NO_BIOMETRIC_CACHE',
                'reason' => 'no_biometric_data'
            ];
        }

        // Simulate biometric validation (in production, this would use actual biometric matching)
        $isValid = $this->matchBiometricData($cachedData['biometric_data'], $biometricData);

        return [
            'valid' => $isValid,
            'message' => $isValid ? 'Biometric validation successful' : 'Biometric validation failed',
            'code' => $isValid ? 'BIOMETRIC_VALID' : 'BIOMETRIC_INVALID',
            'reason' => $isValid ? 'biometric_match' : 'biometric_mismatch'
        ];
    }

    /**
     * Validate offline PIN
     */
    private function validateOfflinePin(array $cachedData, string $pin): array
    {
        if (!isset($cachedData['user_data']['offline_pin_hash'])) {
            return [
                'valid' => false,
                'message' => 'No PIN configured for offline access',
                'code' => 'NO_PIN_CONFIGURED',
                'reason' => 'no_pin'
            ];
        }

        $isValid = password_verify($pin, $cachedData['user_data']['offline_pin_hash']);

        return [
            'valid' => $isValid,
            'message' => $isValid ? 'PIN validation successful' : 'Invalid PIN',
            'code' => $isValid ? 'PIN_VALID' : 'PIN_INVALID',
            'reason' => $isValid ? 'pin_match' : 'pin_mismatch'
        ];
    }

    /**
     * Validate offline password
     */
    private function validateOfflinePassword(array $cachedData, string $password): array
    {
        if (!isset($cachedData['user_data']['password_hash'])) {
            return [
                'valid' => false,
                'message' => 'No password hash cached',
                'code' => 'NO_PASSWORD_CACHE',
                'reason' => 'no_password_hash'
            ];
        }

        $isValid = password_verify($password, $cachedData['user_data']['password_hash']);

        return [
            'valid' => $isValid,
            'message' => $isValid ? 'Password validation successful' : 'Invalid password',
            'code' => $isValid ? 'PASSWORD_VALID' : 'PASSWORD_INVALID',
            'reason' => $isValid ? 'password_match' : 'password_mismatch'
        ];
    }

    /**
     * Generate offline session token
     */
    private function generateOfflineSessionToken(array $userData): string
    {
        $payload = [
            'user_id' => $userData['id'],
            'email' => $userData['email'],
            'offline_session' => true,
            'iat' => time(),
            'exp' => time() + 3600 // 1 hour session
        ];

        $tokenResult = $this->jwtManager->generateToken($payload, 3600);
        return $tokenResult['token'];
    }

    /**
     * Check if sync is required
     */
    private function isSyncRequired(array $cachedData): bool
    {
        $lastSync = $cachedData['sync_timestamp'] ?? 0;
        return (time() - $lastSync) > $this->config['sync_interval'];
    }

    /**
     * Encrypt cache data
     */
    private function encryptCacheData(array $data): string
    {
        $json = json_encode($data);
        $key = hash('sha256', $this->config['cache_encryption_key'], true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt cache data
     */
    private function decryptCacheData(string $encryptedData): ?array
    {
        try {
            $data = base64_decode($encryptedData);
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            $key = hash('sha256', $this->config['cache_encryption_key'], true);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
            return json_decode($decrypted, true);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check network availability (placeholder)
     */
    private function isNetworkAvailable(): bool
    {
        // This would implement actual network connectivity check
        return true; // Placeholder
    }

    /**
     * Get offline capabilities
     */
    private function getOfflineCapabilities(string $cacheId): array
    {
        $cachedData = $this->getOfflineCache($cacheId);
        if (!$cachedData) {
            return [];
        }

        $decryptedData = $this->decryptCacheData($cachedData['data']);
        return [
            'biometric_auth' => !empty($decryptedData['biometric_data']),
            'pin_auth' => isset($decryptedData['user_data']['offline_pin_hash']),
            'password_auth' => isset($decryptedData['user_data']['password_hash']),
            'expires_at' => date('Y-m-d H:i:s', $decryptedData['expires_at']),
            'permissions' => $decryptedData['permissions'] ?? []
        ];
    }

    /**
     * Determine offline mode based on capabilities
     */
    private function determineOfflineMode(array $capabilities): string
    {
        if (empty($capabilities)) {
            return self::MODE_ONLINE_ONLY;
        }

        if ($capabilities['biometric_auth'] || $capabilities['pin_auth']) {
            return self::MODE_FULL_OFFLINE;
        }

        return self::MODE_PARTIAL_OFFLINE;
    }

    /**
     * Get available offline operations
     */
    private function getAvailableOfflineOperations(array $capabilities, string $requestedOperation): array
    {
        $baseOperations = ['view_profile', 'change_settings'];
        
        if ($capabilities['biometric_auth'] || $capabilities['pin_auth']) {
            $baseOperations = array_merge($baseOperations, [
                'authenticate',
                'view_sensitive_data',
                'update_preferences'
            ]);
        }

        return $baseOperations;
    }

    /**
     * Get offline limitations
     */
    private function getOfflineLimitations(): array
    {
        return [
            'no_password_changes',
            'no_account_deletion',
            'no_security_settings_changes',
            'limited_data_updates',
            'sync_required_for_changes'
        ];
    }

    /**
     * Get offline fallback options
     */
    private function getOfflineFallbackOptions(): array
    {
        return [
            'setup_offline_pin',
            'enable_biometric_auth',
            'download_offline_data',
            'contact_support'
        ];
    }

    // Database interaction methods (placeholders)

    private function sanitizeUserDataForCache(array $user): array
    {
        // Remove sensitive data that shouldn't be cached
        unset($user['password_hash']); // Will be added separately if needed
        return $user;
    }

    private function getUserPermissions(int $userId): array
    {
        // This would query user permissions
        return ['read', 'write']; // Placeholder
    }

    private function getUserSettings(int $userId): array
    {
        // This would query user settings
        return []; // Placeholder
    }

    private function getBiometricDataForOffline(int $userId): array
    {
        // This would get biometric templates for offline validation
        return []; // Placeholder
    }

    private function storeOfflineCache(int $userId, string $encryptedData): string
    {
        // This would store in database or secure storage
        return uniqid('cache_', true); // Placeholder cache ID
    }

    private function getOfflineCache(string $cacheId): ?array
    {
        // This would retrieve from database
        return null; // Placeholder
    }

    private function removeOfflineCache(string $cacheId): bool
    {
        // This would delete from database
        return true; // Placeholder
    }

    private function getUserCacheId(int $userId): ?string
    {
        // This would query for user's active cache
        return null; // Placeholder
    }

    private function matchBiometricData(array $cachedBiometric, array $providedBiometric): bool
    {
        // This would perform actual biometric matching
        return true; // Placeholder
    }

    private function logOfflineAuthAttempt(string $cacheId, bool $success, string $reason): void
    {
        $this->auditLogger->logSystemEvent(
            'offline_auth_attempt',
            $success ? 'Offline authentication successful' : 'Offline authentication failed',
            $success ? AuditLogger::SEVERITY_INFO : AuditLogger::SEVERITY_WARNING,
            [
                'cache_id' => $cacheId,
                'success' => $success,
                'reason' => $reason,
                'timestamp' => time()
            ]
        );
    }

    private function syncLocalChangesToServer(int $userId, array $changes): array
    {
        // This would sync local changes to server
        return []; // Placeholder
    }

    private function refreshOfflineCache(string $cacheId, int $userId): array
    {
        // This would refresh cache with latest server data
        return ['success' => true]; // Placeholder
    }
}