<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Interfaces\MFAHandlerInterface;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use GuzzleHttp\Client;
use Exception;

/**
 * SMS-based MFA Handler
 */
class SMSMFAHandler implements MFAHandlerInterface
{
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;
    private Client $httpClient;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
        $this->httpClient = new Client();
    }

    public function setupMFA(int $userId, string $method): array
    {
        if ($method !== 'sms') {
            return [
                'success' => false,
                'error' => 'Invalid MFA method for SMS handler',
                'code' => 'INVALID_MFA_METHOD'
            ];
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        if (empty($user['phone'])) {
            return [
                'success' => false,
                'error' => 'Phone number is required for SMS MFA',
                'code' => 'PHONE_REQUIRED'
            ];
        }

        if (!$user['phone_verified']) {
            return [
                'success' => false,
                'error' => 'Phone number must be verified before enabling SMS MFA',
                'code' => 'PHONE_NOT_VERIFIED'
            ];
        }

        if ($user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is already enabled for this user',
                'code' => 'MFA_ALREADY_ENABLED'
            ];
        }

        try {
            // Generate backup codes
            $backupCodes = $this->generateBackupCodes($userId);
            
            // Store backup codes
            $this->userRepository->updateBackupCodes($userId, $backupCodes['codes']);

            // Send verification SMS
            $verificationResult = $this->sendVerificationSMS($userId);
            
            if (!$verificationResult['success']) {
                return $verificationResult;
            }

            $this->auditLogger->log('sms_mfa_setup_initiated', 'SMS MFA setup initiated', $userId);

            return [
                'success' => true,
                'phone_number' => $this->maskPhoneNumber($user['phone']),
                'backup_codes' => $backupCodes['codes'],
                'verification_sent' => true,
                'instructions' => [
                    '1. A verification code has been sent to your phone',
                    '2. Enter the 6-digit code to complete SMS MFA setup',
                    '3. Save your backup codes in a secure location',
                    '4. You can use backup codes if you lose access to your phone'
                ]
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('sms_mfa_setup_error', 'SMS MFA setup error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return [
                'success' => false,
                'error' => 'Failed to setup SMS MFA',
                'code' => 'SMS_MFA_SETUP_ERROR'
            ];
        }
    }

    public function verifyMFA(int $userId, string $code, string $method): bool
    {
        if ($method !== 'sms') {
            return false;
        }

        $user = $this->userRepository->find($userId);
        if (!$user || !$user['mfa_enabled']) {
            return false;
        }

        // Check if code is valid format
        if (!$this->validateSMSCode($code)) {
            $this->auditLogger->log('sms_mfa_invalid_format', 'Invalid SMS code format', $userId);
            return false;
        }

        // Get stored verification code
        $storedCode = $this->getStoredVerificationCode($userId);
        if (!$storedCode) {
            $this->auditLogger->log('sms_mfa_no_code', 'No SMS verification code found', $userId);
            return false;
        }

        // Check if code has expired (5 minutes)
        if (time() - $storedCode['created_at'] > 300) {
            $this->clearStoredVerificationCode($userId);
            $this->auditLogger->log('sms_mfa_code_expired', 'SMS verification code expired', $userId);
            return false;
        }

        // Verify the code
        if ($code === $storedCode['code']) {
            $this->clearStoredVerificationCode($userId);
            $this->auditLogger->log('sms_mfa_verification_success', 'SMS MFA verification successful', $userId);
            return true;
        } else {
            $this->auditLogger->log('sms_mfa_verification_failed', 'SMS MFA verification failed', $userId);
            return false;
        }
    }

    public function generateBackupCodes(int $userId): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8-character hex codes
        }

        $this->auditLogger->log('sms_backup_codes_generated', 'SMS MFA backup codes generated', $userId);

        return [
            'codes' => $codes,
            'generated_at' => date('Y-m-d H:i:s'),
            'used_codes' => []
        ];
    }

    public function verifyBackupCode(int $userId, string $code): bool
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return false;
        }

        $backupCodes = json_decode($user['backup_codes'] ?? '[]', true);
        if (empty($backupCodes) || !is_array($backupCodes)) {
            return false;
        }

        $code = strtoupper(trim($code));
        
        // Check if code exists and hasn't been used
        $codeIndex = array_search($code, $backupCodes);
        if ($codeIndex === false) {
            $this->auditLogger->log('sms_backup_code_invalid', 'Invalid SMS backup code attempted', $userId);
            return false;
        }

        // Mark code as used by removing it
        unset($backupCodes[$codeIndex]);
        $backupCodes = array_values($backupCodes); // Re-index array

        // Update user's backup codes
        $this->userRepository->updateBackupCodes($userId, $backupCodes);

        $this->auditLogger->log('sms_backup_code_used', 'SMS backup code used successfully', $userId);

        return true;
    }

    public function disableMFA(int $userId): bool
    {
        try {
            $success = $this->userRepository->updateMFAStatus($userId, false, null);
            
            if ($success) {
                // Clear backup codes and stored verification codes
                $this->userRepository->updateBackupCodes($userId, []);
                $this->clearStoredVerificationCode($userId);
                $this->auditLogger->log('sms_mfa_disabled', 'SMS MFA disabled', $userId);
            }

            return $success;

        } catch (Exception $e) {
            $this->auditLogger->log('sms_mfa_disable_error', 'SMS MFA disable error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return false;
        }
    }

    public function isMFARequired(int $userId): bool
    {
        $user = $this->userRepository->find($userId);
        return $user ? (bool)$user['mfa_enabled'] : false;
    }

    /**
     * Send SMS verification code
     */
    public function sendSMSCode(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        if (empty($user['phone'])) {
            return [
                'success' => false,
                'error' => 'Phone number not found',
                'code' => 'PHONE_NOT_FOUND'
            ];
        }

        // Check rate limiting
        $rateLimitKey = "sms_mfa:{$userId}";
        if (!$this->rateLimiter->checkLimit($rateLimitKey, 3, 300)) { // 3 SMS per 5 minutes
            return [
                'success' => false,
                'error' => 'Too many SMS requests. Please wait before requesting another code.',
                'code' => 'SMS_RATE_LIMITED'
            ];
        }

        try {
            // Generate 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Store verification code
            $this->storeVerificationCode($userId, $code);
            
            // Send SMS
            $smsResult = $this->sendSMS($user['phone'], $code);
            
            if ($smsResult['success']) {
                $this->rateLimiter->recordAttempt($rateLimitKey);
                $this->auditLogger->log('sms_code_sent', 'SMS verification code sent', $userId);
                
                return [
                    'success' => true,
                    'phone_number' => $this->maskPhoneNumber($user['phone']),
                    'message' => 'Verification code sent to your phone'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to send SMS',
                    'code' => 'SMS_SEND_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('sms_send_error', 'SMS send error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return [
                'success' => false,
                'error' => 'Failed to send SMS verification code',
                'code' => 'SMS_SEND_ERROR'
            ];
        }
    }

    /**
     * Complete SMS MFA setup after verification
     */
    public function completeSMSMFASetup(int $userId, string $verificationCode): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        if ($user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is already enabled',
                'code' => 'MFA_ALREADY_ENABLED'
            ];
        }

        // Verify the SMS code
        if (!$this->verifySMSSetupCode($userId, $verificationCode)) {
            return [
                'success' => false,
                'error' => 'Invalid verification code',
                'code' => 'INVALID_VERIFICATION_CODE'
            ];
        }

        try {
            // Enable SMS MFA
            $success = $this->userRepository->updateMFAStatus($userId, true, 'sms');
            
            if ($success) {
                $this->auditLogger->log('sms_mfa_enabled', 'SMS MFA enabled successfully', $userId);
                return [
                    'success' => true,
                    'message' => 'SMS MFA has been successfully enabled',
                    'backup_codes_remaining' => count(json_decode($user['backup_codes'] ?? '[]', true))
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to enable SMS MFA',
                    'code' => 'SMS_MFA_ENABLE_ERROR'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('sms_mfa_enable_error', 'SMS MFA enable error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return [
                'success' => false,
                'error' => 'Failed to enable SMS MFA',
                'code' => 'SMS_MFA_ENABLE_ERROR'
            ];
        }
    }

    /**
     * Send SMS using external provider
     */
    private function sendSMS(string $phoneNumber, string $code): array
    {
        Environment::load();
        $apiKey = Environment::get('SMS_PROVIDER_API_KEY');
        
        if (empty($apiKey)) {
            // For development/testing - log the code instead of sending SMS
            error_log("SMS MFA Code for {$phoneNumber}: {$code}");
            return [
                'success' => true,
                'message' => 'SMS sent (development mode)'
            ];
        }

        try {
            // Example implementation for a generic SMS provider
            // Replace with your actual SMS provider's API
            $response = $this->httpClient->post('https://api.sms-provider.com/send', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'to' => $phoneNumber,
                    'message' => "Your verification code is: {$code}. This code will expire in 5 minutes.",
                    'from' => 'AuthService'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'SMS provider returned error: ' . $statusCode
                ];
            }

        } catch (Exception $e) {
            error_log("SMS send error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to send SMS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send verification SMS during setup
     */
    private function sendVerificationSMS(int $userId): array
    {
        return $this->sendSMSCode($userId);
    }

    /**
     * Verify SMS code during setup
     */
    private function verifySMSSetupCode(int $userId, string $code): bool
    {
        // Get stored verification code
        $storedCode = $this->getStoredVerificationCode($userId);
        if (!$storedCode) {
            return false;
        }

        // Check if code has expired (5 minutes)
        if (time() - $storedCode['created_at'] > 300) {
            $this->clearStoredVerificationCode($userId);
            return false;
        }

        // Verify the code
        if ($code === $storedCode['code']) {
            $this->clearStoredVerificationCode($userId);
            return true;
        }

        return false;
    }

    /**
     * Store verification code temporarily
     */
    private function storeVerificationCode(int $userId, string $code): void
    {
        // Store in a temporary table or cache
        // For simplicity, using a file-based approach (replace with Redis/database in production)
        $data = [
            'code' => $code,
            'created_at' => time()
        ];
        
        $tempDir = sys_get_temp_dir();
        file_put_contents("{$tempDir}/sms_code_{$userId}.json", json_encode($data));
    }

    /**
     * Get stored verification code
     */
    private function getStoredVerificationCode(int $userId): ?array
    {
        $tempDir = sys_get_temp_dir();
        $filePath = "{$tempDir}/sms_code_{$userId}.json";
        
        if (!file_exists($filePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($filePath), true);
        return $data ?: null;
    }

    /**
     * Clear stored verification code
     */
    private function clearStoredVerificationCode(int $userId): void
    {
        $tempDir = sys_get_temp_dir();
        $filePath = "{$tempDir}/sms_code_{$userId}.json";
        
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Validate SMS code format
     */
    private function validateSMSCode(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1;
    }

    /**
     * Mask phone number for display
     */
    private function maskPhoneNumber(string $phoneNumber): string
    {
        $length = strlen($phoneNumber);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        
        return substr($phoneNumber, 0, 2) . str_repeat('*', $length - 4) . substr($phoneNumber, -2);
    }
}