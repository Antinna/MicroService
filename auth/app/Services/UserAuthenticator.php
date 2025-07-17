<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Interfaces\AuthenticatorInterface;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;

/**
 * Core user authentication service
 */
class UserAuthenticator implements AuthenticatorInterface
{
    private App $config;
    private UserRepository $userRepository;
    private SessionManager $sessionManager;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->config = App::getInstance();
        $this->userRepository = new UserRepository();
        $this->sessionManager = new SessionManager();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
    }

    public function authenticate(array $credentials): array
    {
        $email = $credentials['email'] ?? '';
        $password = $credentials['password'] ?? '';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Validate input
        if (!$this->validate($credentials)) {
            $this->auditLogger->log('auth_failed', 'Invalid credentials format', null, $ipAddress);
            return [
                'success' => false,
                'error' => 'Invalid credentials format',
                'code' => 'AUTH_001'
            ];
        }

        // Check rate limiting
        $rateLimitKey = "auth_attempts:{$ipAddress}";
        if (!$this->rateLimiter->checkLimit($rateLimitKey, 5, 300)) { // 5 attempts per 5 minutes
            $this->auditLogger->log('auth_rate_limited', 'Too many authentication attempts', null, $ipAddress, 'warning');
            return [
                'success' => false,
                'error' => 'Too many authentication attempts. Please try again later.',
                'code' => 'AUTH_006'
            ];
        }

        // Find user by email
        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            $this->rateLimiter->recordAttempt($rateLimitKey);
            $this->auditLogger->log('auth_failed', 'User not found', null, $ipAddress);
            return [
                'success' => false,
                'error' => 'Invalid credentials',
                'code' => 'AUTH_001'
            ];
        }

        // Check if account is active
        if (!$user['is_active']) {
            $this->auditLogger->log('auth_failed', 'Account inactive', $user['id'], $ipAddress);
            return [
                'success' => false,
                'error' => 'Account is inactive',
                'code' => 'AUTH_002'
            ];
        }

        // Check account lockout
        $lockoutKey = "account_lockout:{$user['id']}";
        if (!$this->rateLimiter->checkLimit($lockoutKey, 
            $this->config->get('security.account_lockout_attempts', 5),
            $this->config->get('security.account_lockout_duration', 1800)
        )) {
            $this->auditLogger->log('auth_failed', 'Account locked due to too many failed attempts', $user['id'], $ipAddress, 'warning');
            return [
                'success' => false,
                'error' => 'Account is temporarily locked due to too many failed attempts',
                'code' => 'AUTH_002'
            ];
        }

        // Verify password
        if (!$this->verifyPassword($password, $user['password_hash'])) {
            $this->rateLimiter->recordAttempt($rateLimitKey);
            $this->rateLimiter->recordAttempt($lockoutKey);
            $this->auditLogger->log('auth_failed', 'Invalid password', $user['id'], $ipAddress);
            return [
                'success' => false,
                'error' => 'Invalid credentials',
                'code' => 'AUTH_001'
            ];
        }

        // Check if MFA is required
        if ($user['mfa_enabled']) {
            // Return partial success - MFA required
            $this->auditLogger->log('auth_mfa_required', 'MFA verification required', $user['id'], $ipAddress);
            return [
                'success' => false,
                'mfa_required' => true,
                'user_id' => $user['id'],
                'message' => 'Multi-factor authentication required',
                'code' => 'AUTH_003'
            ];
        }

        // Authentication successful
        return $this->completeAuthentication($user, $credentials);
    }

    /**
     * Complete authentication after password verification
     */
    public function completeAuthentication(array $user, array $sessionData = []): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        try {
            // Create session
            $token = $this->sessionManager->createSession($user['id'], [
                'ip_address' => $ipAddress,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'session_type' => $sessionData['session_type'] ?? 'web',
                'claims' => $sessionData['claims'] ?? []
            ]);

            // Clear rate limiting counters on successful auth
            $this->rateLimiter->clearAttempts("auth_attempts:{$ipAddress}");
            $this->rateLimiter->clearAttempts("account_lockout:{$user['id']}");

            // Log successful authentication
            $this->auditLogger->log('auth_success', 'User authenticated successfully', $user['id'], $ipAddress);

            return [
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'permissions' => json_decode($user['permissions'] ?? '[]', true),
                    'email_verified' => (bool)$user['email_verified'],
                    'phone_verified' => (bool)$user['phone_verified'],
                    'mfa_enabled' => (bool)$user['mfa_enabled']
                ],
                'expires_in' => $this->config->get('jwt.expiry', 3600)
            ];

        } catch (\Exception $e) {
            $this->auditLogger->log('auth_error', 'Authentication error: ' . $e->getMessage(), $user['id'], $ipAddress, 'error');
            return [
                'success' => false,
                'error' => 'Authentication failed due to system error',
                'code' => 'AUTH_SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Register new user
     */
    public function register(array $userData): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Validate registration data
        $validation = $this->validateRegistration($userData);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
                'code' => 'REG_VALIDATION_FAILED'
            ];
        }

        // Check if user already exists
        $existingUser = $this->userRepository->findByEmail($userData['email']);
        if ($existingUser) {
            $this->auditLogger->log('registration_failed', 'Email already exists', null, $ipAddress);
            return [
                'success' => false,
                'error' => 'Email address is already registered',
                'code' => 'REG_EMAIL_EXISTS'
            ];
        }

        try {
            // Hash password
            $passwordHash = $this->hashPassword($userData['password']);

            // Prepare user data
            $newUserData = [
                'email' => strtolower(trim($userData['email'])),
                'phone' => $userData['phone'] ?? null,
                'password_hash' => $passwordHash,
                'role' => $userData['role'] ?? 'customer',
                'permissions' => json_encode($userData['permissions'] ?? []),
                'email_verified' => false,
                'phone_verified' => false,
                'mfa_enabled' => false,
                'is_active' => true,
                'security_settings' => json_encode([
                    'password_changed_at' => date('Y-m-d H:i:s'),
                    'require_password_change' => false
                ])
            ];

            // Create user
            $userId = $this->userRepository->create($newUserData);

            $this->auditLogger->log('user_registered', 'New user registered', $userId, $ipAddress);

            return [
                'success' => true,
                'user_id' => $userId,
                'message' => 'User registered successfully'
            ];

        } catch (\Exception $e) {
            $this->auditLogger->log('registration_error', 'Registration error: ' . $e->getMessage(), null, $ipAddress, 'error');
            return [
                'success' => false,
                'error' => 'Registration failed due to system error',
                'code' => 'REG_SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Change user password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Get user
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Verify current password
        if (!$this->verifyPassword($currentPassword, $user['password_hash'])) {
            $this->auditLogger->log('password_change_failed', 'Invalid current password', $userId, $ipAddress);
            return [
                'success' => false,
                'error' => 'Current password is incorrect',
                'code' => 'INVALID_CURRENT_PASSWORD'
            ];
        }

        // Validate new password
        $passwordValidation = $this->validatePassword($newPassword);
        if (!$passwordValidation['valid']) {
            return [
                'success' => false,
                'errors' => $passwordValidation['errors'],
                'code' => 'INVALID_NEW_PASSWORD'
            ];
        }

        try {
            // Hash new password
            $newPasswordHash = $this->hashPassword($newPassword);

            // Update password
            $this->userRepository->update($userId, [
                'password_hash' => $newPasswordHash,
                'security_settings' => json_encode([
                    'password_changed_at' => date('Y-m-d H:i:s'),
                    'require_password_change' => false
                ])
            ]);

            // Invalidate all user sessions (force re-login)
            $this->sessionManager->invalidateAllUserSessions($userId);

            $this->auditLogger->log('password_changed', 'Password changed successfully', $userId, $ipAddress);

            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];

        } catch (\Exception $e) {
            $this->auditLogger->log('password_change_error', 'Password change error: ' . $e->getMessage(), $userId, $ipAddress, 'error');
            return [
                'success' => false,
                'error' => 'Password change failed due to system error',
                'code' => 'PASSWORD_CHANGE_ERROR'
            ];
        }
    }

    public function validate(array $data): bool
    {
        return !empty($data['email']) && 
               !empty($data['password']) && 
               filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    }

    public function getMethodName(): string
    {
        return 'password';
    }

    /**
     * Hash password using bcrypt
     */
    private function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify password against hash
     */
    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Validate registration data
     */
    private function validateRegistration(array $userData): array
    {
        $errors = [];

        // Email validation
        if (empty($userData['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        // Password validation
        $passwordValidation = $this->validatePassword($userData['password'] ?? '');
        if (!$passwordValidation['valid']) {
            $errors['password'] = $passwordValidation['errors'];
        }

        // Role validation
        $validRoles = ['admin', 'vendor', 'customer', 'guest', 'delivery_partner', 'management_staff'];
        if (isset($userData['role']) && !in_array($userData['role'], $validRoles)) {
            $errors['role'] = 'Invalid role specified';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate password strength
     */
    private function validatePassword(string $password): array
    {
        $errors = [];
        $minLength = $this->config->get('security.password_min_length', 8);
        $requireSpecial = $this->config->get('security.password_require_special', true);

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        if ($requireSpecial && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}