<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\MagicLinkHandler;
use Antinna\Auth\Services\EmailService;
use Antinna\Auth\Services\SessionManager;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\AuditLogger;
use Exception;

/**
 * Magic Link Controller for passwordless authentication API endpoints
 */
class MagicLinkController
{
    private MagicLinkHandler $magicLinkHandler;
    private EmailService $emailService;
    private SessionManager $sessionManager;
    private JWTManager $jwtManager;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->magicLinkHandler = new MagicLinkHandler();
        $this->emailService = new EmailService();
        $this->sessionManager = new SessionManager();
        $this->jwtManager = new JWTManager();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Request a magic link
     * POST /api/magic-link/request
     */
    public function requestMagicLink(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input) {
                $this->sendError('Invalid JSON input', 400, 'INVALID_INPUT');
                return;
            }

            // Validate required fields
            if (!isset($input['email']) || empty(trim($input['email']))) {
                $this->sendError('Email is required', 400, 'EMAIL_REQUIRED');
                return;
            }

            $email = trim($input['email']);

            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendError('Invalid email format', 400, 'INVALID_EMAIL');
                return;
            }

            // Prepare magic link options
            $options = [];
            
            // Custom expiration time
            if (isset($input['expires_in_minutes']) && is_numeric($input['expires_in_minutes'])) {
                $expirationMinutes = (int)$input['expires_in_minutes'];
                if ($expirationMinutes > 0 && $expirationMinutes <= 60) {
                    $options['expiration_minutes'] = $expirationMinutes;
                }
            }

            // Redirect URL after successful authentication
            if (isset($input['redirect_url']) && !empty(trim($input['redirect_url']))) {
                $redirectUrl = trim($input['redirect_url']);
                if (filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                    $options['redirect_url'] = $redirectUrl;
                }
            }

            // Mobile deep linking support
            if (isset($input['mobile']) && $input['mobile']) {
                $options['mobile_deep_link'] = true;
                
                // Mobile app scheme for deep linking
                if (isset($input['app_scheme']) && !empty(trim($input['app_scheme']))) {
                    $options['app_scheme'] = trim($input['app_scheme']);
                }
            }

            // Custom email subject
            if (isset($input['email_subject']) && !empty(trim($input['email_subject']))) {
                $options['subject'] = trim($input['email_subject']);
            }

            // Generate magic link
            $magicLinkResult = $this->magicLinkHandler->generateMagicLink($email, $options);

            if (!$magicLinkResult['success']) {
                $this->sendError($magicLinkResult['error'], 400, $magicLinkResult['code']);
                return;
            }

            // Send magic link via email
            $emailOptions = [
                'expires_in_minutes' => $options['expiration_minutes'] ?? 15,
                'subject' => $options['subject'] ?? null
            ];

            if (isset($options['redirect_url'])) {
                $emailOptions['template_variables'] = [
                    'redirect_info' => 'You will be redirected after signing in'
                ];
            }

            $emailResult = $this->emailService->sendMagicLinkEmail(
                $email,
                $magicLinkResult['data']['magic_link'],
                $emailOptions
            );

            if (!$emailResult['success']) {
                // Log email failure but don't reveal it to user for security
                $this->auditLogger->log(
                    'magic_link_email_failed',
                    'Failed to send magic link email: ' . $emailResult['error'],
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'error'
                );
            }

            // Always return success for security (don't reveal if email exists or sending failed)
            $this->sendSuccess([
                'message' => 'If the email exists in our system, a magic link has been sent',
                'email' => $email,
                'expires_in_minutes' => $options['expiration_minutes'] ?? 15,
                'check_spam' => 'Please check your spam folder if you don\'t see the email'
            ], 'Magic link request processed');

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_request_error',
                'Magic link request error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );
            $this->sendError('Failed to process magic link request', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Verify magic link token
     * GET /api/magic-link/verify?token=...
     */
    public function verifyMagicLink(): void
    {
        try {
            $token = $_GET['token'] ?? '';
            
            if (empty($token)) {
                $this->sendError('Magic link token is required', 400, 'TOKEN_REQUIRED');
                return;
            }

            // Validate the magic link
            $validateResult = $this->magicLinkHandler->validateMagicLink($token);

            if (!$validateResult['success']) {
                // For web requests, show user-friendly error page
                if ($this->isWebRequest()) {
                    $this->showErrorPage($validateResult['error'], $validateResult['code']);
                    return;
                } else {
                    $this->sendError($validateResult['error'], 400, $validateResult['code']);
                    return;
                }
            }

            $user = $validateResult['data']['user'];
            $magicLinkData = $validateResult['data']['magic_link'];

            // Create session
            $sessionResult = $this->sessionManager->createSession($user['id'], [
                'auth_method' => 'magic_link',
                'magic_link_id' => $magicLinkData['id'],
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);

            if (!$sessionResult['success']) {
                $this->sendError('Failed to create session', 500, 'SESSION_ERROR');
                return;
            }

            // Generate JWT tokens
            $tokenResult = $this->jwtManager->generateToken($user['id'], [
                'auth_method' => 'magic_link',
                'session_id' => $sessionResult['session_id']
            ]);

            if (!$tokenResult['success']) {
                $this->sendError('Failed to generate tokens', 500, 'TOKEN_ERROR');
                return;
            }

            // Send welcome email
            $this->emailService->sendWelcomeEmail($user['email'], [
                'name' => $user['email'],
                'dashboard_url' => $this->getRedirectUrl()
            ]);

            // Handle different response types
            if ($this->isWebRequest()) {
                $this->handleWebResponse($user, $sessionResult, $tokenResult);
            } elseif ($this->isMobileRequest()) {
                $this->handleMobileResponse($user, $sessionResult, $tokenResult);
            } else {
                $this->handleApiResponse($user, $sessionResult, $tokenResult);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_verify_error',
                'Magic link verification error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );
            
            if ($this->isWebRequest()) {
                $this->showErrorPage('An error occurred during authentication', 'VERIFICATION_ERROR');
            } else {
                $this->sendError('Failed to verify magic link', 500, 'INTERNAL_ERROR');
            }
        }
    }

    /**
     * Get magic link status
     * GET /api/magic-link/status
     */
    public function getMagicLinkStatus(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $stats = $this->magicLinkHandler->getUserMagicLinkStats($userId);

            if ($stats['success']) {
                $this->sendSuccess($stats['data'], 'Magic link statistics retrieved');
            } else {
                $this->sendError($stats['error'], 500, $stats['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_status_error',
                'Magic link status error: ' . $e->getMessage(),
                $userId ?? null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );
            $this->sendError('Failed to get magic link status', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Revoke active magic links
     * POST /api/magic-link/revoke
     */
    public function revokeMagicLinks(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $result = $this->magicLinkHandler->revokeUserMagicLinks($userId);

            if ($result['success']) {
                $this->sendSuccess([
                    'revoked_count' => $result['revoked_count']
                ], $result['message']);
            } else {
                $this->sendError($result['error'], 500, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_revoke_error',
                'Magic link revocation error: ' . $e->getMessage(),
                $userId ?? null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );
            $this->sendError('Failed to revoke magic links', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Handle web browser response
     */
    private function handleWebResponse(array $user, array $sessionResult, array $tokenResult): void
    {
        // Set session cookie
        session_start();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['session_id'] = $sessionResult['session_id'];
        $_SESSION['auth_method'] = 'magic_link';

        // Get redirect URL
        $redirectUrl = $this->getRedirectUrl();

        // Show success page with redirect
        $this->showSuccessPage($user, $redirectUrl);
    }

    /**
     * Handle mobile app response
     */
    private function handleMobileResponse(array $user, array $sessionResult, array $tokenResult): void
    {
        $appScheme = $_GET['app_scheme'] ?? 'app';
        $deepLinkUrl = $appScheme . '://auth/success?' . http_build_query([
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'],
            'user_id' => $user['id'],
            'session_id' => $sessionResult['session_id']
        ]);

        // Redirect to mobile app
        header('Location: ' . $deepLinkUrl);
        exit();
    }

    /**
     * Handle API response
     */
    private function handleApiResponse(array $user, array $sessionResult, array $tokenResult): void
    {
        $this->sendSuccess([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'is_verified' => (bool)$user['is_verified']
            ],
            'session' => [
                'id' => $sessionResult['session_id'],
                'expires_at' => $sessionResult['expires_at']
            ],
            'tokens' => [
                'access_token' => $tokenResult['access_token'],
                'refresh_token' => $tokenResult['refresh_token'],
                'expires_in' => $tokenResult['expires_in']
            ],
            'auth_method' => 'magic_link'
        ], 'Magic link authentication successful');
    }

    /**
     * Show success page for web requests
     */
    private function showSuccessPage(array $user, string $redirectUrl): void
    {
        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication Successful</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .button { display: inline-block; background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
    <script>
        setTimeout(function() {
            window.location.href = "' . htmlspecialchars($redirectUrl) . '";
        }, 3000);
    </script>
</head>
<body>
    <h1>🎉 Authentication Successful!</h1>
    
    <div class="success">
        <h2>Welcome back!</h2>
        <p>You have successfully signed in using your magic link.</p>
        <p><strong>Email:</strong> ' . htmlspecialchars($user['email']) . '</p>
    </div>
    
    <div class="info">
        <p>You will be automatically redirected in 3 seconds...</p>
        <a href="' . htmlspecialchars($redirectUrl) . '" class="button">Continue to Dashboard</a>
    </div>
    
    <p><small>If you are not redirected automatically, click the button above.</small></p>
</body>
</html>';

        echo $html;
    }

    /**
     * Show error page for web requests
     */
    private function showErrorPage(string $error, string $code): void
    {
        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication Error</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; text-align: center; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .button { display: inline-block; background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <h1>🔒 Authentication Error</h1>
    
    <div class="error">
        <h2>Unable to Sign In</h2>
        <p>' . htmlspecialchars($error) . '</p>
    </div>
    
    <div class="info">
        <p>This could happen if:</p>
        <ul style="text-align: left;">
            <li>The magic link has expired</li>
            <li>The link has already been used</li>
            <li>The link is invalid or corrupted</li>
        </ul>
    </div>
    
    <a href="/" class="button">Request New Magic Link</a>
    
    <p><small>If you continue to have problems, please contact support.</small></p>
</body>
</html>';

        echo $html;
    }

    /**
     * Check if request is from web browser
     */
    private function isWebRequest(): bool
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($acceptHeader, 'text/html') !== false;
    }

    /**
     * Check if request is from mobile app
     */
    private function isMobileRequest(): bool
    {
        return isset($_GET['mobile']) && $_GET['mobile'] === '1';
    }

    /**
     * Get redirect URL after successful authentication
     */
    private function getRedirectUrl(): string
    {
        // Check for redirect parameter
        if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
            $redirectUrl = $_GET['redirect'];
            if (filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                return $redirectUrl;
            }
        }

        // Default redirect
        return '/dashboard';
    }

    /**
     * Get authenticated user ID from session or JWT
     */
    private function getAuthenticatedUserId(): ?int
    {
        // Try to get from session first
        if (isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        // Try to get from JWT token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenResult = $this->jwtManager->validateToken($token);
            
            if ($tokenResult['success'] && isset($tokenResult['payload']['user_id'])) {
                return (int)$tokenResult['payload']['user_id'];
            }
        }

        return null;
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
    private function sendError(string $message, int $statusCode = 400, string $code = 'ERROR'): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ]);
    }
}