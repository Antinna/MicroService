<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Interfaces\MFAHandlerInterface;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use OTPHP\TOTP;
use Exception;

/**
 * TOTP (Time-based One-Time Password) MFA Handler
 */
class TOTPHandler implements MFAHandlerInterface
{
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
    }

    public function setupMFA(int $userId, string $method): array
    {
        if ($method !== 'totp') {
            return [
                'success' => false,
                'error' => 'Invalid MFA method for TOTP handler',
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

        if ($user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is already enabled for this user',
                'code' => 'MFA_ALREADY_ENABLED'
            ];
        }

        try {
            // Generate TOTP secret
            $totp = TOTP::create();
            $totp->setLabel($user['email']);
            $totp->setIssuer('Auth Service');
            
            $secret = $totp->getSecret();
            
            // Generate backup codes
            $backupCodes = $this->generateBackupCodes($userId);
            
            // Store secret temporarily (not enabled yet)
            $this->userRepository->update($userId, [
                'mfa_secret' => $secret,
                'backup_codes' => json_encode($backupCodes['codes'])
            ]);

            // Generate QR code URL
            $qrCodeUrl = $totp->getQrCodeUri(
                'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={DATA}',
                '{DATA}'
            );

            $this->auditLogger->log('mfa_setup_initiated', 'TOTP MFA setup initiated', $userId);

            return [
                'success' => true,
                'secret' => $secret,
                'qr_code_url' => $qrCodeUrl,
                'backup_codes' => $backupCodes['codes'],
                'manual_entry_key' => $secret,
                'instructions' => [
                    '1. Install an authenticator app (Google Authenticator, Authy, etc.)',
                    '2. Scan the QR code or enter the manual key',
                    '3. Enter the 6-digit code from your app to verify setup',
                    '4. Save your backup codes in a secure location'
                ]
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('mfa_setup_error', 'TOTP MFA setup error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return [
                'success' => false,
                'error' => 'Failed to setup MFA',
                'code' => 'MFA_SETUP_ERROR'
            ];
        }
    }

    public function verifyMFA(int $userId, string $code, string $method): bool
    {
        if ($method !== 'totp') {
            return false;
        }

        $user = $this->userRepository->find($userId);
        if (!$user || empty($user['mfa_secret'])) {
            return false;
        }

        try {
            // Create TOTP instance with user's secret
            $totp = TOTP::create($user['mfa_secret']);
            $totp->setLabel($user['email']);
            $totp->setIssuer('Auth Service');

            // Verify the code (with time window tolerance)
            $isValid = $totp->verify($code, null, 1); // 1 window tolerance (30 seconds before/after)

            if ($isValid) {
                $this->auditLogger->log('mfa_verification_success', 'TOTP MFA verification successful', $userId);
                return true;
            } else {
                $this->auditLogger->log('mfa_verification_failed', 'TOTP MFA verification failed', $userId);
                return false;
            }

        } catch (Exception $e) {
            $this->auditLogger->log('mfa_verification_error', 'TOTP MFA verification error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return false;
        }
    }

    public function generateBackupCodes(int $userId): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8-character hex codes
        }

        $this->auditLogger->log('backup_codes_generated', 'Backup codes generated', $userId);

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
            $this->auditLogger->log('backup_code_invalid', 'Invalid backup code attempted', $userId);
            return false;
        }

        // Mark code as used by removing it
        unset($backupCodes[$codeIndex]);
        $backupCodes = array_values($backupCodes); // Re-index array

        // Update user's backup codes
        $this->userRepository->updateBackupCodes($userId, $backupCodes);

        $this->auditLogger->log('backup_code_used', 'Backup code used successfully', $userId);

        return true;
    }

    public function disableMFA(int $userId): bool
    {
        try {
            $success = $this->userRepository->updateMFAStatus($userId, false, null);
            
            if ($success) {
                // Clear backup codes
                $this->userRepository->updateBackupCodes($userId, []);
                $this->auditLogger->log('mfa_disabled', 'TOTP MFA disabled', $userId);
            }

            return $success;

        } catch (Exception $e) {
            $this->auditLogger->log('mfa_disable_error', 'TOTP MFA disable error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return false;
        }
    }

    public function isMFARequired(int $userId): bool
    {
        $user = $this->userRepository->find($userId);
        return $user ? (bool)$user['mfa_enabled'] : false;
    }

    /**
     * Complete MFA setup after verification
     */
    public function completeMFASetup(int $userId, string $verificationCode): array
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

        if (empty($user['mfa_secret'])) {
            return [
                'success' => false,
                'error' => 'MFA setup not initiated',
                'code' => 'MFA_SETUP_NOT_INITIATED'
            ];
        }

        // Verify the code
        if (!$this->verifyMFA($userId, $verificationCode, 'totp')) {
            return [
                'success' => false,
                'error' => 'Invalid verification code',
                'code' => 'INVALID_VERIFICATION_CODE'
            ];
        }

        try {
            // Enable MFA
            $success = $this->userRepository->updateMFAStatus($userId, true, $user['mfa_secret']);
            
            if ($success) {
                $this->auditLogger->log('mfa_enabled', 'TOTP MFA enabled successfully', $userId);
                return [
                    'success' => true,
                    'message' => 'MFA has been successfully enabled',
                    'backup_codes_remaining' => count(json_decode($user['backup_codes'] ?? '[]', true))
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to enable MFA',
                    'code' => 'MFA_ENABLE_ERROR'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('mfa_enable_error', 'TOTP MFA enable error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return [
                'success' => false,
                'error' => 'Failed to enable MFA',
                'code' => 'MFA_ENABLE_ERROR'
            ];
        }
    }

    /**
     * Get MFA status and information
     */
    public function getMFAStatus(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        $backupCodes = json_decode($user['backup_codes'] ?? '[]', true);
        
        return [
            'success' => true,
            'mfa_enabled' => (bool)$user['mfa_enabled'],
            'mfa_method' => $user['mfa_enabled'] ? 'totp' : null,
            'backup_codes_remaining' => count($backupCodes),
            'setup_completed' => (bool)$user['mfa_enabled']
        ];
    }

    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        if (!$user['mfa_enabled']) {
            return [
                'success' => false,
                'error' => 'MFA is not enabled'
            ];
        }

        try {
            $backupCodes = $this->generateBackupCodes($userId);
            $this->userRepository->updateBackupCodes($userId, $backupCodes['codes']);

            $this->auditLogger->log('backup_codes_regenerated', 'Backup codes regenerated', $userId);

            return [
                'success' => true,
                'backup_codes' => $backupCodes['codes'],
                'message' => 'New backup codes generated. Please store them securely.'
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('backup_codes_regenerate_error', 'Backup codes regeneration error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            return [
                'success' => false,
                'error' => 'Failed to regenerate backup codes'
            ];
        }
    }

    /**
     * Validate TOTP code format
     */
    public function validateTOTPCode(string $code): bool
    {
        // TOTP codes are typically 6 digits
        return preg_match('/^\d{6}$/', $code) === 1;
    }

    /**
     * Get current TOTP value for testing (development only)
     */
    public function getCurrentTOTP(string $secret): string
    {
        try {
            $totp = TOTP::create($secret);
            return $totp->now();
        } catch (Exception $e) {
            return '';
        }
    }
}