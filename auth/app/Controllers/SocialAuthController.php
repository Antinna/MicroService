<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\OAuth2Handler;
use Antinna\Auth\Services\SocialAccountManager;
use Antinna\Auth\Services\UserAuthenticator;
use Antinna\Auth\Services\AuditLogger;

/**
 * Social Authentication Controller
 */
class SocialAuthController
{
    private OAuth2Handler $oauth2Handler;
    private SocialAccountManager $socialAccountManager;
    private UserAuthenticator $userAuthenticator;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->oauth2Handler = new OAuth2Handler();
        $this->socialAccountManager = new SocialAccountManager();
        $this->userAuthenticator = new UserAuthenticator();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Initiate social authentication
     * GET /auth/social/{provider}
     */
    public function initiateAuth(string $provider): void
    {
        try {
            // Validate provider
            if (!in_array($provider, $this->oauth2Handler->getSupportedProviders())) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Unsupported social provider',
                    'code' => 'UNSUPPORTED_PROVIDER'
                ], 400);
                return;
            }

            // Get authorization URL
            $authUrl = $this->oauth2Handler->getAuthorizationUrl($provider);

            $this->auditLogger->log('social_auth_initiated', "Social auth initiated: {$provider}", null, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

            $this->sendJsonResponse([
                'success' => true,
                'provider' => $provider,
                'auth_url' => $authUrl,
                'message' => 'Redirect user to auth_url to complete authentication'
            ]);

        } catch (\Exception $e) {
            $this->auditLogger->log('social_auth_init_error', "Social auth init error: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'error');

            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Failed to initiate social authentication',
                'code' => 'SOCIAL_AUTH_INIT_ERROR'
            ], 500);
        }
    }

    /**
     * Handle OAuth callback
     * POST /auth/social/{provider}/callback
     */
    public function handleCallback(string $provider): void
    {
        try {
            // Get request data
            $requestData = $this->getRequestData();
            
            // Validate required parameters
            if (empty($requestData['code'])) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Authorization code is required',
                    'code' => 'CODE_REQUIRED'
                ], 400);
                return;
            }

            // Validate state parameter if provided
            if (!empty($requestData['state'])) {
                if (!$this->oauth2Handler->validateState($requestData['state'], $provider)) {
                    $this->sendJsonResponse([
                        'success' => false,
                        'error' => 'Invalid state parameter',
                        'code' => 'INVALID_STATE'
                    ], 400);
                    return;
                }
            }

            // Handle OAuth callback
            $callbackResult = $this->oauth2Handler->handleCallback($provider, $requestData['code']);

            if (!$callbackResult['success']) {
                $this->sendJsonResponse($callbackResult, 400);
                return;
            }

            // Authenticate or create user with social account
            $authResult = $this->socialAccountManager->authenticateWithSocial(
                $provider,
                $callbackResult['user_profile']
            );

            if (!$authResult['success']) {
                $this->sendJsonResponse($authResult, 400);
                return;
            }

            // Complete authentication and create session
            $sessionResult = $this->userAuthenticator->completeAuthentication(
                $authResult['user'],
                [
                    'session_type' => 'web',
                    'claims' => [
                        'social_provider' => $provider,
                        'social_auth' => true
                    ]
                ]
            );

            if (!$sessionResult['success']) {
                $this->sendJsonResponse($sessionResult, 500);
                return;
            }

            $this->sendJsonResponse([
                'success' => true,
                'action' => $authResult['action'],
                'token' => $sessionResult['token'],
                'user' => $sessionResult['user'],
                'expires_in' => $sessionResult['expires_in'],
                'provider' => $provider
            ]);

        } catch (\Exception $e) {
            $this->auditLogger->log('social_callback_error', "Social callback error: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'error');

            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Social authentication callback failed',
                'code' => 'SOCIAL_CALLBACK_ERROR'
            ], 500);
        }
    }

    /**
     * Link social account to existing user
     * POST /auth/social/link
     */
    public function linkAccount(): void
    {
        try {
            // Get authenticated user (this would typically come from middleware)
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Authentication required',
                    'code' => 'AUTH_REQUIRED'
                ], 401);
                return;
            }

            $requestData = $this->getRequestData();

            // Validate required parameters
            if (empty($requestData['provider']) || empty($requestData['code'])) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Provider and authorization code are required',
                    'code' => 'MISSING_PARAMETERS'
                ], 400);
                return;
            }

            $provider = $requestData['provider'];

            // Handle OAuth callback to get user profile
            $callbackResult = $this->oauth2Handler->handleCallback($provider, $requestData['code']);

            if (!$callbackResult['success']) {
                $this->sendJsonResponse($callbackResult, 400);
                return;
            }

            // Link social account to user
            $linkResult = $this->socialAccountManager->linkAccount(
                $userId,
                $provider,
                $callbackResult['user_profile']
            );

            $this->sendJsonResponse($linkResult, $linkResult['success'] ? 200 : 400);

        } catch (\Exception $e) {
            $this->auditLogger->log('social_link_error', "Social link error: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'error');

            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Failed to link social account',
                'code' => 'SOCIAL_LINK_ERROR'
            ], 500);
        }
    }

    /**
     * Unlink social account from user
     * DELETE /auth/social/{provider}/unlink
     */
    public function unlinkAccount(string $provider): void
    {
        try {
            // Get authenticated user
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Authentication required',
                    'code' => 'AUTH_REQUIRED'
                ], 401);
                return;
            }

            // Unlink social account
            $unlinkResult = $this->socialAccountManager->unlinkAccount($userId, $provider);

            $this->sendJsonResponse($unlinkResult, $unlinkResult['success'] ? 200 : 400);

        } catch (\Exception $e) {
            $this->auditLogger->log('social_unlink_error', "Social unlink error: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'error');

            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Failed to unlink social account',
                'code' => 'SOCIAL_UNLINK_ERROR'
            ], 500);
        }
    }

    /**
     * Get user's linked social accounts
     * GET /auth/social/accounts
     */
    public function getUserSocialAccounts(): void
    {
        try {
            // Get authenticated user
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Authentication required',
                    'code' => 'AUTH_REQUIRED'
                ], 401);
                return;
            }

            // Get user's social accounts
            $socialAccounts = $this->socialAccountManager->getUserSocialAccounts($userId);

            // Remove sensitive data from response
            $publicAccounts = array_map(function($account) {
                return [
                    'id' => $account['id'],
                    'provider' => $account['provider'],
                    'provider_email' => $account['provider_email'],
                    'linked_at' => $account['linked_at'],
                    'profile_name' => $account['provider_data']['name'] ?? null,
                    'profile_avatar' => $account['provider_data']['avatar_url'] ?? null
                ];
            }, $socialAccounts);

            $this->sendJsonResponse([
                'success' => true,
                'social_accounts' => $publicAccounts,
                'count' => count($publicAccounts)
            ]);

        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Failed to get social accounts',
                'code' => 'GET_SOCIAL_ACCOUNTS_ERROR'
            ], 500);
        }
    }

    /**
     * Get supported social providers
     * GET /auth/social/providers
     */
    public function getSupportedProviders(): void
    {
        try {
            $providers = $this->oauth2Handler->getSupportedProviders();

            $this->sendJsonResponse([
                'success' => true,
                'providers' => $providers,
                'count' => count($providers)
            ]);

        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Failed to get supported providers',
                'code' => 'GET_PROVIDERS_ERROR'
            ], 500);
        }
    }

    /**
     * Get social authentication statistics (admin only)
     * GET /auth/social/statistics
     */
    public function getStatistics(): void
    {
        try {
            // This would typically check for admin role
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Authentication required',
                    'code' => 'AUTH_REQUIRED'
                ], 401);
                return;
            }

            $statistics = $this->socialAccountManager->getSocialAccountStatistics();

            $this->sendJsonResponse([
                'success' => true,
                'statistics' => $statistics
            ]);

        } catch (\Exception $e) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Failed to get statistics',
                'code' => 'GET_STATISTICS_ERROR'
            ], 500);
        }
    }

    /**
     * Send JSON response
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Get request data from POST body or query parameters
     */
    private function getRequestData(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            return json_decode($input, true) ?: [];
        }
        
        return array_merge($_GET, $_POST);
    }

    /**
     * Get authenticated user ID (placeholder - would be implemented with JWT middleware)
     */
    private function getAuthenticatedUserId(): ?int
    {
        // This is a placeholder - in a real implementation, this would:
        // 1. Extract JWT token from Authorization header
        // 2. Validate the token using JWTManager
        // 3. Return the user ID from the token payload
        
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        // Extract token and validate (simplified for demo)
        // In real implementation, use JWTManager to validate
        return 1; // Placeholder user ID
    }
}