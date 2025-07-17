<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\SocialAuthController;

/**
 * Social Authentication Routes
 */
class SocialRoutes
{
    private SocialAuthController $controller;

    public function __construct()
    {
        $this->controller = new SocialAuthController();
    }

    /**
     * Handle social authentication routes
     */
    public function handleRequest(string $uri, string $method): bool
    {
        // Remove query string from URI
        $uri = parse_url($uri, PHP_URL_PATH);
        
        // Social authentication routes
        $routes = [
            // Initiate social authentication
            'GET /auth/social/providers' => [$this->controller, 'getSupportedProviders'],
            'GET /auth/social/statistics' => [$this->controller, 'getStatistics'],
            'GET /auth/social/accounts' => [$this->controller, 'getUserSocialAccounts'],
            
            // Link/unlink social accounts
            'POST /auth/social/link' => [$this->controller, 'linkAccount'],
            
            // Provider-specific routes (dynamic)
        ];

        // Check static routes first
        $routeKey = "{$method} {$uri}";
        if (isset($routes[$routeKey])) {
            call_user_func($routes[$routeKey]);
            return true;
        }

        // Handle dynamic provider routes
        if (preg_match('#^/auth/social/([a-zA-Z]+)$#', $uri, $matches)) {
            $provider = $matches[1];
            
            if ($method === 'GET') {
                // Initiate social authentication
                $this->controller->initiateAuth($provider);
                return true;
            }
        }

        if (preg_match('#^/auth/social/([a-zA-Z]+)/callback$#', $uri, $matches)) {
            $provider = $matches[1];
            
            if ($method === 'POST') {
                // Handle OAuth callback
                $this->controller->handleCallback($provider);
                return true;
            }
        }

        if (preg_match('#^/auth/social/([a-zA-Z]+)/unlink$#', $uri, $matches)) {
            $provider = $matches[1];
            
            if ($method === 'DELETE') {
                // Unlink social account
                $this->controller->unlinkAccount($provider);
                return true;
            }
        }

        return false;
    }

    /**
     * Get all social authentication routes for documentation
     */
    public static function getRoutes(): array
    {
        return [
            'GET /auth/social/providers' => 'Get supported social providers',
            'GET /auth/social/statistics' => 'Get social authentication statistics (admin)',
            'GET /auth/social/accounts' => 'Get user\'s linked social accounts',
            'POST /auth/social/link' => 'Link social account to current user',
            
            // Dynamic routes
            'GET /auth/social/{provider}' => 'Initiate social authentication',
            'POST /auth/social/{provider}/callback' => 'Handle OAuth callback',
            'DELETE /auth/social/{provider}/unlink' => 'Unlink social account',
        ];
    }

    /**
     * Get supported providers for route generation
     */
    public static function getSupportedProviders(): array
    {
        return ['google', 'facebook', 'apple', 'github', 'amazon', 'twitter', 'discord', 'microsoft'];
    }
}