<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Interfaces\SocialAuthInterface;
use Antinna\Auth\Services\AuditLogger;
use GuzzleHttp\Client;
use Exception;

/**
 * OAuth2 Handler for social authentication
 */
class OAuth2Handler implements SocialAuthInterface
{
    private Client $httpClient;
    private AuditLogger $auditLogger;

    // OAuth2 provider configurations
    private array $providers = [
        'google' => [
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'user_info_url' => 'https://www.googleapis.com/oauth2/v2/userinfo',
            'scopes' => ['openid', 'email', 'profile']
        ],
        'facebook' => [
            'auth_url' => 'https://www.facebook.com/v18.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v18.0/oauth/access_token',
            'user_info_url' => 'https://graph.facebook.com/v18.0/me',
            'scopes' => ['email', 'public_profile']
        ],
        'apple' => [
            'auth_url' => 'https://appleid.apple.com/auth/authorize',
            'token_url' => 'https://appleid.apple.com/auth/token',
            'user_info_url' => null, // Apple provides user info in the token response
            'scopes' => ['name', 'email']
        ],
        'github' => [
            'auth_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'user_info_url' => 'https://api.github.com/user',
            'scopes' => ['user:email']
        ],
        'amazon' => [
            'auth_url' => 'https://www.amazon.com/ap/oa',
            'token_url' => 'https://api.amazon.com/auth/o2/token',
            'user_info_url' => 'https://api.amazon.com/user/profile',
            'scopes' => ['profile']
        ],
        'twitter' => [
            'auth_url' => 'https://twitter.com/i/oauth2/authorize',
            'token_url' => 'https://api.twitter.com/2/oauth2/token',
            'user_info_url' => 'https://api.twitter.com/2/users/me',
            'scopes' => ['tweet.read', 'users.read']
        ],
        'discord' => [
            'auth_url' => 'https://discord.com/api/oauth2/authorize',
            'token_url' => 'https://discord.com/api/oauth2/token',
            'user_info_url' => 'https://discord.com/api/users/@me',
            'scopes' => ['identify', 'email']
        ],
        'microsoft' => [
            'auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'user_info_url' => 'https://graph.microsoft.com/v1.0/me',
            'scopes' => ['openid', 'profile', 'email']
        ]
    ];

    public function __construct()
    {
        $this->httpClient = new Client(['timeout' => 30]);
        $this->auditLogger = new AuditLogger();
    }

    public function getAuthorizationUrl(string $provider, array $scopes = []): string
    {
        if (!$this->isProviderSupported($provider)) {
            throw new Exception("Unsupported OAuth2 provider: {$provider}");
        }

        $providerConfig = $this->providers[$provider];
        $clientId = $this->getClientId($provider);
        
        if (empty($clientId)) {
            throw new Exception("Client ID not configured for provider: {$provider}");
        }

        // Use provided scopes or default ones
        $requestedScopes = !empty($scopes) ? $scopes : $providerConfig['scopes'];
        
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $this->getRedirectUri($provider),
            'scope' => implode(' ', $requestedScopes),
            'response_type' => 'code',
            'state' => $this->generateState($provider)
        ];

        // Provider-specific parameters
        switch ($provider) {
            case 'apple':
                $params['response_mode'] = 'form_post';
                break;
            case 'microsoft':
                $params['prompt'] = 'select_account';
                break;
        }

        $queryString = http_build_query($params);
        return $providerConfig['auth_url'] . '?' . $queryString;
    }

    public function handleCallback(string $provider, string $code): array
    {
        if (!$this->isProviderSupported($provider)) {
            return [
                'success' => false,
                'error' => "Unsupported OAuth2 provider: {$provider}",
                'code' => 'UNSUPPORTED_PROVIDER'
            ];
        }

        try {
            // Exchange authorization code for access token
            $tokenData = $this->exchangeCodeForToken($provider, $code);
            
            if (!$tokenData['success']) {
                return $tokenData;
            }

            // Get user profile information
            $userProfile = $this->getUserProfile($provider, $tokenData['access_token']);
            
            if (!$userProfile['success']) {
                return $userProfile;
            }

            $this->auditLogger->log('oauth2_callback_success', "OAuth2 callback successful for {$provider}", null, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

            return [
                'success' => true,
                'provider' => $provider,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_in' => $tokenData['expires_in'] ?? null,
                'user_profile' => $userProfile['profile']
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('oauth2_callback_error', "OAuth2 callback error for {$provider}: " . $e->getMessage(), null, $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'OAuth2 callback failed: ' . $e->getMessage(),
                'code' => 'OAUTH2_CALLBACK_ERROR'
            ];
        }
    }

    public function getUserProfile(string $provider, string $accessToken): array
    {
        if (!$this->isProviderSupported($provider)) {
            return [
                'success' => false,
                'error' => "Unsupported OAuth2 provider: {$provider}"
            ];
        }

        try {
            $providerConfig = $this->providers[$provider];
            
            // Apple provides user info in the token response, not via API
            if ($provider === 'apple') {
                return $this->getAppleUserProfile($accessToken);
            }

            $response = $this->httpClient->get($providerConfig['user_info_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json'
                ]
            ]);

            $userData = json_decode($response->getBody()->getContents(), true);
            
            if (!$userData) {
                return [
                    'success' => false,
                    'error' => 'Failed to decode user profile data'
                ];
            }

            // Normalize user profile data across providers
            $normalizedProfile = $this->normalizeUserProfile($provider, $userData);

            return [
                'success' => true,
                'profile' => $normalizedProfile,
                'raw_data' => $userData
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get user profile: ' . $e->getMessage()
            ];
        }
    }

    public function linkAccount(int $userId, string $provider, array $providerData): bool
    {
        // This will be implemented in SocialAccountManager
        return false;
    }

    public function unlinkAccount(int $userId, string $provider): bool
    {
        // This will be implemented in SocialAccountManager
        return false;
    }

    public function getSupportedProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Exchange authorization code for access token
     */
    private function exchangeCodeForToken(string $provider, string $code): array
    {
        $providerConfig = $this->providers[$provider];
        
        $params = [
            'client_id' => $this->getClientId($provider),
            'client_secret' => $this->getClientSecret($provider),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->getRedirectUri($provider)
        ];

        try {
            $response = $this->httpClient->post($providerConfig['token_url'], [
                'form_params' => $params,
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            if (!$tokenData || !isset($tokenData['access_token'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid token response from provider'
                ];
            }

            return [
                'success' => true,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_in' => $tokenData['expires_in'] ?? null,
                'token_type' => $tokenData['token_type'] ?? 'Bearer'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Token exchange failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Normalize user profile data across different providers
     */
    private function normalizeUserProfile(string $provider, array $userData): array
    {
        $normalized = [
            'provider' => $provider,
            'provider_id' => null,
            'email' => null,
            'name' => null,
            'first_name' => null,
            'last_name' => null,
            'avatar_url' => null,
            'profile_url' => null,
            'verified' => false
        ];

        switch ($provider) {
            case 'google':
                $normalized['provider_id'] = $userData['id'] ?? null;
                $normalized['email'] = $userData['email'] ?? null;
                $normalized['name'] = $userData['name'] ?? null;
                $normalized['first_name'] = $userData['given_name'] ?? null;
                $normalized['last_name'] = $userData['family_name'] ?? null;
                $normalized['avatar_url'] = $userData['picture'] ?? null;
                $normalized['verified'] = $userData['verified_email'] ?? false;
                break;

            case 'facebook':
                $normalized['provider_id'] = $userData['id'] ?? null;
                $normalized['email'] = $userData['email'] ?? null;
                $normalized['name'] = $userData['name'] ?? null;
                $normalized['first_name'] = $userData['first_name'] ?? null;
                $normalized['last_name'] = $userData['last_name'] ?? null;
                $normalized['avatar_url'] = isset($userData['id']) ? "https://graph.facebook.com/{$userData['id']}/picture?type=large" : null;
                break;

            case 'github':
                $normalized['provider_id'] = $userData['id'] ?? null;
                $normalized['email'] = $userData['email'] ?? null;
                $normalized['name'] = $userData['name'] ?? $userData['login'] ?? null;
                $normalized['avatar_url'] = $userData['avatar_url'] ?? null;
                $normalized['profile_url'] = $userData['html_url'] ?? null;
                break;

            case 'discord':
                $normalized['provider_id'] = $userData['id'] ?? null;
                $normalized['email'] = $userData['email'] ?? null;
                $normalized['name'] = $userData['username'] ?? null;
                $normalized['avatar_url'] = isset($userData['id'], $userData['avatar']) 
                    ? "https://cdn.discordapp.com/avatars/{$userData['id']}/{$userData['avatar']}.png" 
                    : null;
                $normalized['verified'] = $userData['verified'] ?? false;
                break;

            case 'microsoft':
                $normalized['provider_id'] = $userData['id'] ?? null;
                $normalized['email'] = $userData['mail'] ?? $userData['userPrincipalName'] ?? null;
                $normalized['name'] = $userData['displayName'] ?? null;
                $normalized['first_name'] = $userData['givenName'] ?? null;
                $normalized['last_name'] = $userData['surname'] ?? null;
                break;

            case 'twitter':
                $normalized['provider_id'] = $userData['data']['id'] ?? null;
                $normalized['name'] = $userData['data']['name'] ?? $userData['data']['username'] ?? null;
                $normalized['avatar_url'] = $userData['data']['profile_image_url'] ?? null;
                break;

            case 'amazon':
                $normalized['provider_id'] = $userData['user_id'] ?? null;
                $normalized['email'] = $userData['email'] ?? null;
                $normalized['name'] = $userData['name'] ?? null;
                break;
        }

        return $normalized;
    }

    /**
     * Get Apple user profile (special handling)
     */
    private function getAppleUserProfile(string $accessToken): array
    {
        // Apple provides user info in the ID token, not via API
        // This would require JWT decoding of the ID token
        return [
            'success' => true,
            'profile' => [
                'provider' => 'apple',
                'provider_id' => null, // Would be extracted from ID token
                'email' => null, // Would be extracted from ID token
                'name' => null, // Would be extracted from ID token
                'verified' => true // Apple emails are always verified
            ]
        ];
    }

    /**
     * Generate secure state parameter for OAuth2 flow
     */
    private function generateState(string $provider): string
    {
        $state = [
            'provider' => $provider,
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(16))
        ];

        return base64_encode(json_encode($state));
    }

    /**
     * Validate state parameter
     */
    public function validateState(string $state, string $expectedProvider): bool
    {
        try {
            $decodedState = json_decode(base64_decode($state), true);
            
            if (!$decodedState || !isset($decodedState['provider'], $decodedState['timestamp'])) {
                return false;
            }

            // Check provider matches
            if ($decodedState['provider'] !== $expectedProvider) {
                return false;
            }

            // Check timestamp (state should not be older than 10 minutes)
            if (time() - $decodedState['timestamp'] > 600) {
                return false;
            }

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if provider is supported
     */
    private function isProviderSupported(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    /**
     * Get client ID for provider
     */
    private function getClientId(string $provider): string
    {
        Environment::load();
        
        return match($provider) {
            'google' => Environment::get('GOOGLE_CLIENT_ID', ''),
            'facebook' => Environment::get('FACEBOOK_APP_ID', ''),
            'apple' => Environment::get('APPLE_CLIENT_ID', ''),
            'github' => Environment::get('GITHUB_CLIENT_ID', ''),
            'amazon' => Environment::get('AMAZON_CLIENT_ID', ''),
            'twitter' => Environment::get('TWITTER_CLIENT_ID', ''),
            'discord' => Environment::get('DISCORD_CLIENT_ID', ''),
            'microsoft' => Environment::get('MICROSOFT_CLIENT_ID', ''),
            default => ''
        };
    }

    /**
     * Get client secret for provider
     */
    private function getClientSecret(string $provider): string
    {
        Environment::load();
        
        return match($provider) {
            'google' => Environment::get('GOOGLE_CLIENT_SECRET', ''),
            'facebook' => Environment::get('FACEBOOK_APP_SECRET', ''),
            'apple' => Environment::get('APPLE_PRIVATE_KEY', ''),
            'github' => Environment::get('GITHUB_CLIENT_SECRET', ''),
            'amazon' => Environment::get('AMAZON_CLIENT_SECRET', ''),
            'twitter' => Environment::get('TWITTER_CLIENT_SECRET', ''),
            'discord' => Environment::get('DISCORD_CLIENT_SECRET', ''),
            'microsoft' => Environment::get('MICROSOFT_CLIENT_SECRET', ''),
            default => ''
        };
    }

    /**
     * Get redirect URI for provider
     */
    private function getRedirectUri(string $provider): string
    {
        Environment::load();
        $baseUrl = Environment::get('APP_URL', 'http://localhost:8000');
        return "{$baseUrl}/auth/social/{$provider}/callback";
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $provider, string $refreshToken): array
    {
        if (!$this->isProviderSupported($provider)) {
            return [
                'success' => false,
                'error' => "Unsupported OAuth2 provider: {$provider}"
            ];
        }

        $providerConfig = $this->providers[$provider];
        
        $params = [
            'client_id' => $this->getClientId($provider),
            'client_secret' => $this->getClientSecret($provider),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ];

        try {
            $response = $this->httpClient->post($providerConfig['token_url'], [
                'form_params' => $params,
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            
            if (!$tokenData || !isset($tokenData['access_token'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid token refresh response'
                ];
            }

            return [
                'success' => true,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? $refreshToken,
                'expires_in' => $tokenData['expires_in'] ?? null
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Token refresh failed: ' . $e->getMessage()
            ];
        }
    }
}