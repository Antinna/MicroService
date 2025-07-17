<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Interfaces\TokenManagerInterface;
use Antinna\Auth\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Exception;

/**
 * JWT Token Manager implementation
 */
class JWTManager implements TokenManagerInterface
{
    private App $config;
    private UserRepository $userRepository;
    private TokenBlacklistService $blacklistService;

    public function __construct()
    {
        $this->config = App::getInstance();
        $this->userRepository = new UserRepository();
        $this->blacklistService = new TokenBlacklistService();
    }

    public function generateToken(int $userId, array $claims = []): string
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new Exception('User not found');
        }

        $now = time();
        $expiry = $now + $this->config->get('jwt.expiry', 3600);

        $payload = [
            'iss' => 'auth-service',
            'aud' => 'platform',
            'iat' => $now,
            'exp' => $expiry,
            'sub' => $userId,
            'user_id' => $userId,
            'email' => $user['email'],
            'role' => $user['role'],
            'permissions' => json_decode($user['permissions'] ?? '[]', true),
            'email_verified' => (bool)$user['email_verified'],
            'phone_verified' => (bool)$user['phone_verified'],
            'mfa_enabled' => (bool)$user['mfa_enabled'],
            'is_active' => (bool)$user['is_active'],
            'jti' => bin2hex(random_bytes(16)), // JWT ID for blacklisting
        ];

        // Add custom claims
        foreach ($claims as $key => $value) {
            if (!isset($payload[$key])) {
                $payload[$key] = $value;
            }
        }

        $secret = $this->config->get('jwt.secret');
        $algorithm = $this->config->get('jwt.algorithm', 'HS256');

        return JWT::encode($payload, $secret, $algorithm);
    }

    public function validateToken(string $token): array
    {
        try {
            $secret = $this->config->get('jwt.secret');
            $algorithm = $this->config->get('jwt.algorithm', 'HS256');

            $decoded = JWT::decode($token, new Key($secret, $algorithm));
            $payload = (array)$decoded;

            // Check if token is blacklisted
            if ($this->isTokenBlacklisted($token)) {
                throw new Exception('Token has been revoked');
            }

            // Verify user still exists and is active
            $user = $this->userRepository->find($payload['user_id']);
            if (!$user || !$user['is_active']) {
                throw new Exception('User account is inactive');
            }

            return [
                'valid' => true,
                'payload' => $payload,
                'user_id' => $payload['user_id'],
                'email' => $payload['email'],
                'role' => $payload['role'],
                'permissions' => $payload['permissions'] ?? [],
                'expires_at' => $payload['exp']
            ];

        } catch (ExpiredException $e) {
            return [
                'valid' => false,
                'error' => 'Token has expired',
                'code' => 'TOKEN_EXPIRED'
            ];
        } catch (SignatureInvalidException $e) {
            return [
                'valid' => false,
                'error' => 'Invalid token signature',
                'code' => 'INVALID_SIGNATURE'
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'code' => 'TOKEN_INVALID'
            ];
        }
    }

    public function refreshToken(string $token): string
    {
        $validation = $this->validateToken($token);
        
        if (!$validation['valid']) {
            // Allow refresh for expired tokens within grace period
            if ($validation['code'] !== 'TOKEN_EXPIRED') {
                throw new Exception('Cannot refresh invalid token');
            }
            
            // Try to decode expired token to get user info
            try {
                $secret = $this->config->get('jwt.secret');
                $algorithm = $this->config->get('jwt.algorithm', 'HS256');
                
                // Decode without verification to get payload
                $parts = explode('.', $token);
                if (count($parts) !== 3) {
                    throw new Exception('Invalid token format');
                }
                
                $payload = json_decode(base64_decode($parts[1]), true);
                $userId = $payload['user_id'] ?? null;
                
                if (!$userId) {
                    throw new Exception('Invalid token payload');
                }
                
                // Check if token expired within refresh window
                $refreshWindow = $this->config->get('jwt.refresh_expiry', 604800);
                $expiredAt = $payload['exp'] ?? 0;
                
                if (time() - $expiredAt > $refreshWindow) {
                    throw new Exception('Token refresh window expired');
                }
                
            } catch (Exception $e) {
                throw new Exception('Cannot refresh token: ' . $e->getMessage());
            }
        } else {
            $userId = $validation['user_id'];
        }

        // Blacklist the old token
        $this->revokeToken($token);

        // Generate new token
        return $this->generateToken($userId);
    }

    public function revokeToken(string $token): bool
    {
        try {
            // Extract JWT ID and expiration from token
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }
            
            $payload = json_decode(base64_decode($parts[1]), true);
            $jti = $payload['jti'] ?? null;
            $exp = $payload['exp'] ?? null;
            $userId = $payload['user_id'] ?? null;
            
            if (!$jti || !$exp) {
                return false;
            }

            return $this->blacklistService->addToBlacklist(
                hash('sha256', $token),
                $userId,
                'Token revoked',
                date('Y-m-d H:i:s', $exp)
            );

        } catch (Exception $e) {
            return false;
        }
    }

    public function isTokenBlacklisted(string $token): bool
    {
        $tokenHash = hash('sha256', $token);
        return $this->blacklistService->isBlacklisted($tokenHash);
    }

    /**
     * Generate service-to-service token
     */
    public function generateServiceToken(string $serviceName, array $permissions = []): string
    {
        $now = time();
        $expiry = $now + 3600; // 1 hour for service tokens

        $payload = [
            'iss' => 'auth-service',
            'aud' => 'platform',
            'iat' => $now,
            'exp' => $expiry,
            'sub' => 'service:' . $serviceName,
            'service_name' => $serviceName,
            'permissions' => $permissions,
            'type' => 'service',
            'jti' => bin2hex(random_bytes(16)),
        ];

        $secret = $this->config->get('jwt.secret');
        $algorithm = $this->config->get('jwt.algorithm', 'HS256');

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * Validate service token
     */
    public function validateServiceToken(string $token): array
    {
        $validation = $this->validateToken($token);
        
        if (!$validation['valid']) {
            return $validation;
        }

        $payload = $validation['payload'];
        
        if (($payload['type'] ?? '') !== 'service') {
            return [
                'valid' => false,
                'error' => 'Not a service token',
                'code' => 'INVALID_TOKEN_TYPE'
            ];
        }

        return [
            'valid' => true,
            'service_name' => $payload['service_name'],
            'permissions' => $payload['permissions'] ?? [],
            'expires_at' => $payload['exp']
        ];
    }

    /**
     * Get token payload without validation (for debugging)
     */
    public function decodeTokenPayload(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            
            return json_decode(base64_decode($parts[1]), true);
        } catch (Exception $e) {
            return null;
        }
    }
}