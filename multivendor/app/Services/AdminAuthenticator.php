<?php

namespace Antinna\Multivendor\Services;

use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Exceptions\UnauthorizedException;

class AdminAuthenticator
{
    private Logger $logger;
    private string $adminUsername;
    private string $adminPassword;
    private int $sessionTimeout;

    public function __construct(Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->adminUsername = getenv('ADMIN_USERNAME') ?: 'admin';
        $this->adminPassword = getenv('ADMIN_PASSWORD') ?: 'admin';
        $this->sessionTimeout = (int)(getenv('ADMIN_SESSION_TIMEOUT') ?: 3600); // 1 hour default
        
        $this->initializeSession();
    }

    private function initializeSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Configure secure session settings
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $this->isHttps() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            
            session_start();
        }
    }

    /**
     * Authenticate admin user with credentials
     */
    public function authenticate(string $username, string $password): array
    {
        $this->logger->info('Admin authentication attempt', [
            'username' => $username,
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Validate credentials
        if (!$this->validateCredentials($username, $password)) {
            $this->logger->warning('Admin authentication failed - invalid credentials', [
                'username' => $username,
                'ip' => $this->getClientIp()
            ]);
            
            throw new UnauthorizedException('Invalid admin credentials');
        }

        // Create session
        $sessionData = $this->createSession($username);
        
        $this->logger->info('Admin authentication successful', [
            'username' => $username,
            'session_id' => $sessionData['session_id'],
            'ip' => $this->getClientIp()
        ]);

        return [
            'success' => true,
            'message' => 'Authentication successful',
            'session' => $sessionData,
            'expires_at' => date('c', time() + $this->sessionTimeout)
        ];
    }

    /**
     * Validate admin credentials
     */
    private function validateCredentials(string $username, string $password): bool
    {
        // Use timing-safe comparison to prevent timing attacks
        $usernameValid = hash_equals($this->adminUsername, $username);
        $passwordValid = hash_equals($this->adminPassword, $password);
        
        return $usernameValid && $passwordValid;
    }

    /**
     * Create admin session
     */
    private function createSession(string $username): array
    {
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        $sessionId = session_id();
        $loginTime = time();
        $expiresAt = $loginTime + $this->sessionTimeout;
        
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_login_time'] = $loginTime;
        $_SESSION['admin_expires_at'] = $expiresAt;
        $_SESSION['admin_ip'] = $this->getClientIp();
        $_SESSION['admin_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        return [
            'session_id' => $sessionId,
            'username' => $username,
            'login_time' => $loginTime,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Check if current session is authenticated
     */
    public function isAuthenticated(): bool
    {
        if (!isset($_SESSION['admin_authenticated']) || !$_SESSION['admin_authenticated']) {
            return false;
        }

        // Check session expiry
        if (isset($_SESSION['admin_expires_at']) && time() > $_SESSION['admin_expires_at']) {
            $this->logger->info('Admin session expired', [
                'username' => $_SESSION['admin_username'] ?? 'unknown',
                'expired_at' => $_SESSION['admin_expires_at']
            ]);
            
            $this->logout();
            return false;
        }

        // Check IP consistency (optional security measure)
        if (isset($_SESSION['admin_ip']) && $_SESSION['admin_ip'] !== $this->getClientIp()) {
            $this->logger->warning('Admin session IP mismatch detected', [
                'username' => $_SESSION['admin_username'] ?? 'unknown',
                'session_ip' => $_SESSION['admin_ip'],
                'current_ip' => $this->getClientIp()
            ]);
            
            $this->logout();
            return false;
        }

        return true;
    }

    /**
     * Get current admin session info
     */
    public function getSessionInfo(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return [
            'username' => $_SESSION['admin_username'] ?? null,
            'login_time' => $_SESSION['admin_login_time'] ?? null,
            'expires_at' => $_SESSION['admin_expires_at'] ?? null,
            'session_id' => session_id(),
            'time_remaining' => ($_SESSION['admin_expires_at'] ?? 0) - time()
        ];
    }

    /**
     * Extend current session
     */
    public function extendSession(): array
    {
        if (!$this->isAuthenticated()) {
            throw new UnauthorizedException('No active admin session');
        }

        $newExpiresAt = time() + $this->sessionTimeout;
        $_SESSION['admin_expires_at'] = $newExpiresAt;

        $this->logger->info('Admin session extended', [
            'username' => $_SESSION['admin_username'],
            'new_expires_at' => $newExpiresAt
        ]);

        return [
            'success' => true,
            'message' => 'Session extended',
            'expires_at' => date('c', $newExpiresAt),
            'time_remaining' => $this->sessionTimeout
        ];
    }

    /**
     * Logout admin user
     */
    public function logout(): array
    {
        $username = $_SESSION['admin_username'] ?? 'unknown';
        
        $this->logger->info('Admin logout', [
            'username' => $username,
            'session_id' => session_id()
        ]);

        // Clear admin session data
        unset($_SESSION['admin_authenticated']);
        unset($_SESSION['admin_username']);
        unset($_SESSION['admin_login_time']);
        unset($_SESSION['admin_expires_at']);
        unset($_SESSION['admin_ip']);
        unset($_SESSION['admin_user_agent']);

        // Destroy session if it only contained admin data
        if (empty($_SESSION)) {
            session_destroy();
        }

        return [
            'success' => true,
            'message' => 'Logout successful'
        ];
    }

    /**
     * Require authentication middleware
     */
    public function requireAuthentication(): void
    {
        if (!$this->isAuthenticated()) {
            $this->logger->warning('Unauthorized admin access attempt', [
                'ip' => $this->getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'requested_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
            
            throw new UnauthorizedException('Admin authentication required');
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return 'unknown';
    }

    /**
     * Check if connection is HTTPS
     */
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }

    /**
     * Get admin credentials info (for debugging/setup)
     */
    public function getCredentialsInfo(): array
    {
        return [
            'username_source' => getenv('ADMIN_USERNAME') ? 'environment' : 'default',
            'password_source' => getenv('ADMIN_PASSWORD') ? 'environment' : 'default',
            'session_timeout' => $this->sessionTimeout,
            'default_credentials' => [
                'username' => 'admin',
                'password' => 'admin'
            ]
        ];
    }

    /**
     * Validate session token (for API authentication)
     */
    public function validateSessionToken(string $token): bool
    {
        return hash_equals(session_id(), $token) && $this->isAuthenticated();
    }
}