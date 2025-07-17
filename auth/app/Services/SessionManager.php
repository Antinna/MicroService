<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Interfaces\SessionManagerInterface;
use Antinna\Auth\Repositories\SessionRepository;
use Antinna\Auth\Repositories\UserRepository;

/**
 * Session Manager implementation
 */
class SessionManager implements SessionManagerInterface
{
    private App $config;
    private SessionRepository $sessionRepository;
    private UserRepository $userRepository;
    private JWTManager $jwtManager;

    public function __construct()
    {
        $this->config = App::getInstance();
        $this->sessionRepository = new SessionRepository();
        $this->userRepository = new UserRepository();
        $this->jwtManager = new JWTManager();
    }

    public function createSession(int $userId, array $sessionData): string
    {
        // Verify user exists and is active
        $user = $this->userRepository->find($userId);
        if (!$user || !$user['is_active']) {
            throw new \Exception('User not found or inactive');
        }

        // Generate JWT token
        $jwtToken = $this->jwtManager->generateToken($userId, $sessionData['claims'] ?? []);
        $tokenHash = hash('sha256', $jwtToken);

        // Prepare session data
        $sessionData = array_merge($sessionData, [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ip_address' => $sessionData['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $sessionData['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'device_info' => $sessionData['device_info'] ?? $this->extractDeviceInfo(),
            'session_type' => $sessionData['session_type'] ?? 'web',
            'expires_at' => date('Y-m-d H:i:s', time() + $this->config->get('jwt.expiry', 3600)),
            'last_activity' => date('Y-m-d H:i:s'),
        ]);

        // Create session record
        $sessionId = $this->sessionRepository->createSession($sessionData);

        // Update user's last login
        $this->userRepository->updateLastLogin($userId);

        // Return the JWT token (not the session ID)
        return $jwtToken;
    }

    public function validateSession(string $sessionId): array
    {
        // For JWT-based sessions, sessionId is actually the JWT token
        $tokenValidation = $this->jwtManager->validateToken($sessionId);
        
        if (!$tokenValidation['valid']) {
            return [
                'valid' => false,
                'error' => $tokenValidation['error'],
                'code' => $tokenValidation['code']
            ];
        }

        // Find session by token hash
        $tokenHash = hash('sha256', $sessionId);
        $session = $this->sessionRepository->findByTokenHash($tokenHash);

        if (!$session) {
            return [
                'valid' => false,
                'error' => 'Session not found',
                'code' => 'SESSION_NOT_FOUND'
            ];
        }

        // Check if session is expired
        if (strtotime($session['expires_at']) < time()) {
            $this->invalidateSession($sessionId);
            return [
                'valid' => false,
                'error' => 'Session expired',
                'code' => 'SESSION_EXPIRED'
            ];
        }

        // Update last activity
        $this->updateActivity($sessionId);

        return [
            'valid' => true,
            'session' => $session,
            'user' => $tokenValidation,
            'expires_at' => $session['expires_at']
        ];
    }

    public function updateActivity(string $sessionId): bool
    {
        // For JWT tokens, we need to find the session by token hash
        $tokenHash = hash('sha256', $sessionId);
        $session = $this->sessionRepository->findByTokenHash($tokenHash);
        
        if (!$session) {
            return false;
        }

        return $this->sessionRepository->updateActivity($session['id']);
    }

    public function invalidateSession(string $sessionId): bool
    {
        // Blacklist the JWT token
        $this->jwtManager->revokeToken($sessionId);

        // Find and invalidate the session record
        $tokenHash = hash('sha256', $sessionId);
        $session = $this->sessionRepository->findByTokenHash($tokenHash);
        
        if ($session) {
            return $this->sessionRepository->invalidateSession($session['id']);
        }

        return true; // Token is blacklisted even if session record not found
    }

    public function invalidateAllUserSessions(int $userId): bool
    {
        // Get all active sessions for user
        $sessions = $this->sessionRepository->findActiveUserSessions($userId);
        
        $success = true;
        foreach ($sessions as $session) {
            // Reconstruct token from hash (not possible with hash)
            // Instead, we'll invalidate the session records and rely on token validation
            if (!$this->sessionRepository->invalidateSession($session['id'])) {
                $success = false;
            }
        }

        // Also invalidate all user sessions in the repository
        return $this->sessionRepository->invalidateAllUserSessions($userId) && $success;
    }

    public function getUserSessions(int $userId): array
    {
        return $this->sessionRepository->findActiveUserSessions($userId);
    }

    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions(): int
    {
        return $this->sessionRepository->cleanupExpiredSessions();
    }

    /**
     * Get session statistics
     */
    public function getSessionStatistics(): array
    {
        return $this->sessionRepository->getSessionStats();
    }

    /**
     * Find sessions by IP address (for security monitoring)
     */
    public function findSessionsByIP(string $ipAddress, int $limit = 10): array
    {
        return $this->sessionRepository->findByIpAddress($ipAddress, $limit);
    }

    /**
     * Create session with device fingerprinting
     */
    public function createSecureSession(int $userId, array $sessionData): string
    {
        // Add security checks
        $securityChecks = $this->performSecurityChecks($userId, $sessionData);
        
        if (!$securityChecks['passed']) {
            throw new \Exception('Security check failed: ' . $securityChecks['reason']);
        }

        // Add device fingerprint
        $sessionData['device_fingerprint'] = $this->generateDeviceFingerprint($sessionData);
        
        return $this->createSession($userId, $sessionData);
    }

    /**
     * Validate session with security checks
     */
    public function validateSecureSession(string $sessionId, array $requestData = []): array
    {
        $validation = $this->validateSession($sessionId);
        
        if (!$validation['valid']) {
            return $validation;
        }

        // Additional security checks
        $securityChecks = $this->performSessionSecurityChecks($validation['session'], $requestData);
        
        if (!$securityChecks['passed']) {
            $this->invalidateSession($sessionId);
            return [
                'valid' => false,
                'error' => 'Security validation failed',
                'code' => 'SECURITY_CHECK_FAILED',
                'reason' => $securityChecks['reason']
            ];
        }

        return $validation;
    }

    /**
     * Extract device information from request
     */
    private function extractDeviceInfo(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        return [
            'user_agent' => $userAgent,
            'platform' => $this->detectPlatform($userAgent),
            'browser' => $this->detectBrowser($userAgent),
            'is_mobile' => $this->isMobile($userAgent),
            'screen_resolution' => $_SERVER['HTTP_X_SCREEN_RESOLUTION'] ?? null,
            'timezone' => $_SERVER['HTTP_X_TIMEZONE'] ?? null,
        ];
    }

    /**
     * Generate device fingerprint
     */
    private function generateDeviceFingerprint(array $sessionData): string
    {
        $fingerprint = [
            'ip' => $sessionData['ip_address'] ?? '',
            'user_agent' => $sessionData['user_agent'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ];

        return hash('sha256', json_encode($fingerprint));
    }

    /**
     * Perform security checks before creating session
     */
    private function performSecurityChecks(int $userId, array $sessionData): array
    {
        // Check for suspicious IP addresses
        $ipAddress = $sessionData['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Check rate limiting
        $recentSessions = $this->findSessionsByIP($ipAddress, 5);
        if (count($recentSessions) > 3) {
            return [
                'passed' => false,
                'reason' => 'Too many sessions from this IP'
            ];
        }

        // Check for account lockout
        $user = $this->userRepository->find($userId);
        if (!$user || !$user['is_active']) {
            return [
                'passed' => false,
                'reason' => 'Account is locked or inactive'
            ];
        }

        return ['passed' => true];
    }

    /**
     * Perform security checks during session validation
     */
    private function performSessionSecurityChecks(array $session, array $requestData): array
    {
        // Check IP address consistency (optional, can be disabled for mobile users)
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $sessionIP = $session['ip_address'];
        
        // Allow IP changes for mobile sessions
        if ($session['session_type'] !== 'mobile' && $currentIP !== $sessionIP) {
            // Log suspicious activity but don't block (IP can change legitimately)
            error_log("IP address changed for session {$session['id']}: {$sessionIP} -> {$currentIP}");
        }

        return ['passed' => true];
    }

    /**
     * Detect platform from user agent
     */
    private function detectPlatform(string $userAgent): string
    {
        if (stripos($userAgent, 'windows') !== false) return 'Windows';
        if (stripos($userAgent, 'macintosh') !== false) return 'macOS';
        if (stripos($userAgent, 'linux') !== false) return 'Linux';
        if (stripos($userAgent, 'android') !== false) return 'Android';
        if (stripos($userAgent, 'iphone') !== false) return 'iOS';
        if (stripos($userAgent, 'ipad') !== false) return 'iPadOS';
        
        return 'Unknown';
    }

    /**
     * Detect browser from user agent
     */
    private function detectBrowser(string $userAgent): string
    {
        if (stripos($userAgent, 'chrome') !== false) return 'Chrome';
        if (stripos($userAgent, 'firefox') !== false) return 'Firefox';
        if (stripos($userAgent, 'safari') !== false) return 'Safari';
        if (stripos($userAgent, 'edge') !== false) return 'Edge';
        if (stripos($userAgent, 'opera') !== false) return 'Opera';
        
        return 'Unknown';
    }

    /**
     * Check if request is from mobile device
     */
    private function isMobile(string $userAgent): bool
    {
        return preg_match('/Mobile|Android|iPhone|iPad/', $userAgent) === 1;
    }
}