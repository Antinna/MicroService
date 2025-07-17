<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Helpers\ErrorHandlingHelper;
use Exception;

/**
 * Biometric Authentication Handler for Mobile Devices
 */
class BiometricHandler
{
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;
    private JWTManager $jwtManager;

    // Biometric types
    public const TYPE_FINGERPRINT = 'fingerprint';
    public const TYPE_FACE_ID = 'face_id';
    public const TYPE_VOICE = 'voice';
    public const TYPE_IRIS = 'iris';
    public const TYPE_PALM = 'palm';

    // Biometric token types
    public const TOKEN_TYPE_ENROLLMENT = 'biometric_enrollment';
    public const TOKEN_TYPE_AUTHENTICATION = 'biometric_auth';
    public const TOKEN_TYPE_CHALLENGE = 'biometric_challenge';

    // Security levels
    public const SECURITY_LEVEL_LOW = 'low';
    public const SECURITY_LEVEL_MEDIUM = 'medium';
    public const SECURITY_LEVEL_HIGH = 'high';

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
        $this->jwtManager = new JWTManager();
    }

    /**
     * Enroll biometric data for user
     */
    public function enrollBiometric(int $userId, string $biometricType, array $biometricData, array $deviceInfo = []): array
    {
        try {
            // Rate limiting for enrollment attempts
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'biometric_enrollment'
            );

            if (!$rateLimitResult['allowed']) {
                return [
                    'success' => false,
                    'message' => 'Too many enrollment attempts. Please try again later.',
                    'code' => 'RATE_LIMIT_EXCEEDED'
                ];
            }

            // Validate biometric type
            if (!$this->isValidBiometricType($biometricType)) {
                return [
                    'success' => false,
                    'message' => 'Invalid biometric type',
                    'code' => 'INVALID_BIOMETRIC_TYPE'
                ];
            }

            // Validate user exists and is active
            $user = $this->userRepository->find($userId);
            if (!$user || !$user['is_active']) {
                return [
                    'success' => false,
                    'message' => 'User not found or inactive',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Validate biometric data
            $validationResult = $this->validateBiometricData($biometricType, $biometricData);
            if (!$validationResult['valid']) {
                return [
                    'success' => false,
                    'message' => $validationResult['message'],
                    'code' => 'INVALID_BIOMETRIC_DATA'
                ];
            }

            // Check if biometric type already enrolled
            if ($this->isBiometricEnrolled($userId, $biometricType)) {
                return [
                    'success' => false,
                    'message' => 'Biometric type already enrolled',
                    'code' => 'BIOMETRIC_ALREADY_ENROLLED'
                ];
            }

            // Generate enrollment token
            $enrollmentToken = $this->generateBiometricToken($userId, self::TOKEN_TYPE_ENROLLMENT, [
                'biometric_type' => $biometricType,
                'device_info' => $deviceInfo
            ]);

            // Store biometric enrollment
            $biometricId = $this->storeBiometricEnrollment($userId, $biometricType, $biometricData, $deviceInfo, $enrollmentToken);

            // Log enrollment
            $this->auditLogger->logAuthentication(
                AuditLogger::EVENT_BIOMETRIC_ENROLLED,
                'Biometric enrollment successful',
                $userId,
                $biometricType,
                true,
                [
                    'biometric_type' => $biometricType,
                    'device_info' => $deviceInfo,
                    'biometric_id' => $biometricId
                ]
            );

            return [
                'success' => true,
                'message' => 'Biometric enrollment successful',
                'biometric_id' => $biometricId,
                'enrollment_token' => $enrollmentToken,
                'biometric_type' => $biometricType,
                'enrolled_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'biometric_enrollment_error',
                'Biometric enrollment error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId, 'biometric_type' => $biometricType]
            );

            return [
                'success' => false,
                'message' => 'Biometric enrollment failed',
                'code' => 'ENROLLMENT_FAILED'
            ];
        }
    }

    /**
     * Authenticate user using biometric data
     */
    public function authenticateBiometric(string $biometricType, array $biometricData, array $deviceInfo = []): array
    {
        try {
            // Rate limiting for authentication attempts
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $ipAddress,
                RateLimiter::LIMIT_TYPE_IP,
                'biometric_auth'
            );

            if (!$rateLimitResult['allowed']) {
                return [
                    'success' => false,
                    'message' => 'Too many authentication attempts. Please try again later.',
                    'code' => 'RATE_LIMIT_EXCEEDED'
                ];
            }

            // Validate biometric type
            if (!$this->isValidBiometricType($biometricType)) {
                return [
                    'success' => false,
                    'message' => 'Invalid biometric type',
                    'code' => 'INVALID_BIOMETRIC_TYPE'
                ];
            }

            // Validate biometric data
            $validationResult = $this->validateBiometricData($biometricType, $biometricData);
            if (!$validationResult['valid']) {
                return [
                    'success' => false,
                    'message' => $validationResult['message'],
                    'code' => 'INVALID_BIOMETRIC_DATA'
                ];
            }

            // Find matching biometric enrollment
            $matchResult = $this->matchBiometricData($biometricType, $biometricData);
            if (!$matchResult['matched']) {
                $this->auditLogger->logAuthentication(
                    AuditLogger::EVENT_LOGIN_FAILED,
                    'Biometric authentication failed - no match',
                    null,
                    $biometricType,
                    false,
                    [
                        'biometric_type' => $biometricType,
                        'device_info' => $deviceInfo,
                        'ip_address' => $ipAddress
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Biometric authentication failed',
                    'code' => 'BIOMETRIC_NO_MATCH'
                ];
            }

            $userId = $matchResult['user_id'];
            $biometricId = $matchResult['biometric_id'];

            // Verify user is still active
            $user = $this->userRepository->find($userId);
            if (!$user || !$user['is_active']) {
                return [
                    'success' => false,
                    'message' => 'User account is inactive',
                    'code' => 'ACCOUNT_INACTIVE'
                ];
            }

            // Generate authentication token
            $authToken = $this->generateBiometricToken($userId, self::TOKEN_TYPE_AUTHENTICATION, [
                'biometric_type' => $biometricType,
                'biometric_id' => $biometricId,
                'device_info' => $deviceInfo
            ]);

            // Update last used timestamp
            $this->updateBiometricLastUsed($biometricId);

            // Log successful authentication
            $this->auditLogger->logAuthentication(
                AuditLogger::EVENT_LOGIN_SUCCESS,
                'Biometric authentication successful',
                $userId,
                $biometricType,
                true,
                [
                    'biometric_type' => $biometricType,
                    'biometric_id' => $biometricId,
                    'device_info' => $deviceInfo,
                    'ip_address' => $ipAddress
                ]
            );

            return [
                'success' => true,
                'message' => 'Biometric authentication successful',
                'user_id' => $userId,
                'auth_token' => $authToken,
                'biometric_type' => $biometricType,
                'user' => $this->sanitizeUserData($user),
                'authenticated_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'biometric_auth_error',
                'Biometric authentication error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['biometric_type' => $biometricType]
            );

            return [
                'success' => false,
                'message' => 'Biometric authentication failed',
                'code' => 'AUTHENTICATION_FAILED'
            ];
        }
    }

    /**
     * Generate biometric challenge for authentication
     */
    public function generateBiometricChallenge(int $userId, string $biometricType): array
    {
        try {
            // Validate user and biometric enrollment
            if (!$this->isBiometricEnrolled($userId, $biometricType)) {
                return [
                    'success' => false,
                    'message' => 'Biometric not enrolled for this user',
                    'code' => 'BIOMETRIC_NOT_ENROLLED'
                ];
            }

            // Generate challenge data
            $challengeData = [
                'challenge_id' => bin2hex(random_bytes(16)),
                'timestamp' => time(),
                'nonce' => bin2hex(random_bytes(32)),
                'biometric_type' => $biometricType,
                'user_id' => $userId
            ];

            // Generate challenge token
            $challengeToken = $this->generateBiometricToken($userId, self::TOKEN_TYPE_CHALLENGE, $challengeData, 300); // 5 minutes

            // Store challenge temporarily
            $this->storeBiometricChallenge($challengeData['challenge_id'], $challengeData, $challengeToken);

            return [
                'success' => true,
                'challenge_id' => $challengeData['challenge_id'],
                'challenge_token' => $challengeToken,
                'nonce' => $challengeData['nonce'],
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
                'biometric_type' => $biometricType
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'biometric_challenge_error',
                'Biometric challenge generation error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId, 'biometric_type' => $biometricType]
            );

            return [
                'success' => false,
                'message' => 'Challenge generation failed',
                'code' => 'CHALLENGE_FAILED'
            ];
        }
    }

    /**
     * Verify biometric challenge response
     */
    public function verifyBiometricChallenge(string $challengeId, string $challengeToken, array $biometricData): array
    {
        try {
            // Validate challenge token
            $tokenResult = $this->jwtManager->validateToken($challengeToken);
            if (!$tokenResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired challenge token',
                    'code' => 'INVALID_CHALLENGE_TOKEN'
                ];
            }

            // Get challenge data
            $challengeData = $this->getBiometricChallenge($challengeId);
            if (!$challengeData) {
                return [
                    'success' => false,
                    'message' => 'Challenge not found or expired',
                    'code' => 'CHALLENGE_NOT_FOUND'
                ];
            }

            // Verify biometric data against enrolled data
            $matchResult = $this->matchBiometricData($challengeData['biometric_type'], $biometricData, $challengeData['user_id']);
            if (!$matchResult['matched']) {
                return [
                    'success' => false,
                    'message' => 'Biometric verification failed',
                    'code' => 'BIOMETRIC_VERIFICATION_FAILED'
                ];
            }

            // Clean up challenge
            $this->removeBiometricChallenge($challengeId);

            return [
                'success' => true,
                'message' => 'Biometric challenge verified successfully',
                'user_id' => $challengeData['user_id'],
                'biometric_type' => $challengeData['biometric_type'],
                'verified_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'biometric_challenge_verify_error',
                'Biometric challenge verification error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['challenge_id' => $challengeId]
            );

            return [
                'success' => false,
                'message' => 'Challenge verification failed',
                'code' => 'VERIFICATION_FAILED'
            ];
        }
    }

    /**
     * Get user's enrolled biometrics
     */
    public function getUserBiometrics(int $userId): array
    {
        try {
            $biometrics = $this->getBiometricsByUserId($userId);
            
            // Sanitize biometric data for response
            $sanitizedBiometrics = array_map(function($biometric) {
                return [
                    'id' => $biometric['id'],
                    'biometric_type' => $biometric['biometric_type'],
                    'device_name' => $biometric['device_name'] ?? 'Unknown Device',
                    'enrolled_at' => $biometric['enrolled_at'],
                    'last_used_at' => $biometric['last_used_at'],
                    'is_active' => $biometric['is_active'],
                    'security_level' => $biometric['security_level'] ?? self::SECURITY_LEVEL_MEDIUM
                ];
            }, $biometrics);

            return [
                'success' => true,
                'biometrics' => $sanitizedBiometrics,
                'total_count' => count($sanitizedBiometrics)
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'get_user_biometrics_error',
                'Error retrieving user biometrics: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId]
            );

            return [
                'success' => false,
                'message' => 'Failed to retrieve biometrics',
                'code' => 'RETRIEVAL_FAILED'
            ];
        }
    }

    /**
     * Remove biometric enrollment
     */
    public function removeBiometric(int $userId, int $biometricId): array
    {
        try {
            // Verify biometric belongs to user
            $biometric = $this->getBiometricById($biometricId);
            if (!$biometric || $biometric['user_id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => 'Biometric not found',
                    'code' => 'BIOMETRIC_NOT_FOUND'
                ];
            }

            // Remove biometric enrollment
            $success = $this->deleteBiometricEnrollment($biometricId);
            if (!$success) {
                return [
                    'success' => false,
                    'message' => 'Failed to remove biometric',
                    'code' => 'REMOVAL_FAILED'
                ];
            }

            // Log removal
            $this->auditLogger->logAuthentication(
                AuditLogger::EVENT_BIOMETRIC_REMOVED,
                'Biometric enrollment removed',
                $userId,
                $biometric['biometric_type'],
                true,
                [
                    'biometric_id' => $biometricId,
                    'biometric_type' => $biometric['biometric_type']
                ]
            );

            return [
                'success' => true,
                'message' => 'Biometric removed successfully',
                'biometric_id' => $biometricId,
                'removed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'biometric_removal_error',
                'Biometric removal error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['user_id' => $userId, 'biometric_id' => $biometricId]
            );

            return [
                'success' => false,
                'message' => 'Biometric removal failed',
                'code' => 'REMOVAL_FAILED'
            ];
        }
    }

    // Private helper methods

    /**
     * Validate biometric type
     */
    private function isValidBiometricType(string $type): bool
    {
        return in_array($type, [
            self::TYPE_FINGERPRINT,
            self::TYPE_FACE_ID,
            self::TYPE_VOICE,
            self::TYPE_IRIS,
            self::TYPE_PALM
        ]);
    }

    /**
     * Validate biometric data based on type
     */
    private function validateBiometricData(string $type, array $data): array
    {
        switch ($type) {
            case self::TYPE_FINGERPRINT:
                return $this->validateFingerprintData($data);
            case self::TYPE_FACE_ID:
                return $this->validateFaceIdData($data);
            case self::TYPE_VOICE:
                return $this->validateVoiceData($data);
            case self::TYPE_IRIS:
                return $this->validateIrisData($data);
            case self::TYPE_PALM:
                return $this->validatePalmData($data);
            default:
                return ['valid' => false, 'message' => 'Unknown biometric type'];
        }
    }

    /**
     * Validate fingerprint data
     */
    private function validateFingerprintData(array $data): array
    {
        if (!isset($data['template']) || !isset($data['quality_score'])) {
            return ['valid' => false, 'message' => 'Missing fingerprint template or quality score'];
        }

        if ($data['quality_score'] < 0.7) {
            return ['valid' => false, 'message' => 'Fingerprint quality too low'];
        }

        if (strlen($data['template']) < 100) {
            return ['valid' => false, 'message' => 'Fingerprint template too short'];
        }

        return ['valid' => true];
    }

    /**
     * Validate Face ID data
     */
    private function validateFaceIdData(array $data): array
    {
        if (!isset($data['face_encoding']) || !isset($data['confidence_score'])) {
            return ['valid' => false, 'message' => 'Missing face encoding or confidence score'];
        }

        if ($data['confidence_score'] < 0.8) {
            return ['valid' => false, 'message' => 'Face recognition confidence too low'];
        }

        if (!is_array($data['face_encoding']) || count($data['face_encoding']) < 128) {
            return ['valid' => false, 'message' => 'Invalid face encoding format'];
        }

        return ['valid' => true];
    }

    /**
     * Validate voice data
     */
    private function validateVoiceData(array $data): array
    {
        if (!isset($data['voice_print']) || !isset($data['duration'])) {
            return ['valid' => false, 'message' => 'Missing voice print or duration'];
        }

        if ($data['duration'] < 2.0) {
            return ['valid' => false, 'message' => 'Voice sample too short'];
        }

        return ['valid' => true];
    }

    /**
     * Validate iris data
     */
    private function validateIrisData(array $data): array
    {
        if (!isset($data['iris_template']) || !isset($data['quality_score'])) {
            return ['valid' => false, 'message' => 'Missing iris template or quality score'];
        }

        if ($data['quality_score'] < 0.75) {
            return ['valid' => false, 'message' => 'Iris quality too low'];
        }

        return ['valid' => true];
    }

    /**
     * Validate palm data
     */
    private function validatePalmData(array $data): array
    {
        if (!isset($data['palm_template']) || !isset($data['quality_score'])) {
            return ['valid' => false, 'message' => 'Missing palm template or quality score'];
        }

        if ($data['quality_score'] < 0.7) {
            return ['valid' => false, 'message' => 'Palm quality too low'];
        }

        return ['valid' => true];
    }

    /**
     * Generate biometric token
     */
    private function generateBiometricToken(int $userId, string $tokenType, array $data, int $expiration = 3600): string
    {
        $payload = [
            'user_id' => $userId,
            'token_type' => $tokenType,
            'biometric_data' => $data,
            'iss' => 'auth-service',
            'aud' => 'biometric-auth',
            'iat' => time(),
            'exp' => time() + $expiration
        ];

        $tokenResult = $this->jwtManager->generateToken($payload, $expiration);
        return $tokenResult['token'] ?? '';
    }

    /**
     * Check if biometric is enrolled for user
     */
    private function isBiometricEnrolled(int $userId, string $biometricType): bool
    {
        // This would query the database
        // For now, we'll simulate the check
        return false; // Placeholder
    }

    /**
     * Store biometric enrollment
     */
    private function storeBiometricEnrollment(int $userId, string $biometricType, array $biometricData, array $deviceInfo, string $enrollmentToken): int
    {
        // This would store in the database
        // For now, we'll simulate storage
        return rand(1000, 9999); // Placeholder biometric ID
    }

    /**
     * Match biometric data against enrolled data
     */
    private function matchBiometricData(string $biometricType, array $biometricData, ?int $userId = null): array
    {
        // This would perform actual biometric matching
        // For now, we'll simulate matching
        return [
            'matched' => true, // Placeholder
            'user_id' => $userId ?? 123,
            'biometric_id' => 1001,
            'confidence_score' => 0.95
        ];
    }

    /**
     * Update biometric last used timestamp
     */
    private function updateBiometricLastUsed(int $biometricId): bool
    {
        // This would update the database
        return true; // Placeholder
    }

    /**
     * Store biometric challenge
     */
    private function storeBiometricChallenge(string $challengeId, array $challengeData, string $challengeToken): bool
    {
        // This would store in cache/database
        return true; // Placeholder
    }

    /**
     * Get biometric challenge
     */
    private function getBiometricChallenge(string $challengeId): ?array
    {
        // This would retrieve from cache/database
        return null; // Placeholder
    }

    /**
     * Remove biometric challenge
     */
    private function removeBiometricChallenge(string $challengeId): bool
    {
        // This would remove from cache/database
        return true; // Placeholder
    }

    /**
     * Get biometrics by user ID
     */
    private function getBiometricsByUserId(int $userId): array
    {
        // This would query the database
        return []; // Placeholder
    }

    /**
     * Get biometric by ID
     */
    private function getBiometricById(int $biometricId): ?array
    {
        // This would query the database
        return null; // Placeholder
    }

    /**
     * Delete biometric enrollment
     */
    private function deleteBiometricEnrollment(int $biometricId): bool
    {
        // This would delete from database
        return true; // Placeholder
    }

    /**
     * Sanitize user data
     */
    private function sanitizeUserData(array $user): array
    {
        unset($user['password_hash']);
        unset($user['mfa_secret']);
        return $user;
    }
}
                  