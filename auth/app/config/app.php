<?php

namespace Antinna\Auth\Config;

/**
 * Application configuration management
 */
class App
{
    private static ?App $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->loadEnvironmentVariables();
        $this->loadConfiguration();
    }

    public static function getInstance(): App
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadEnvironmentVariables(): void
    {
        if (file_exists(__DIR__ . '/../../.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
            $dotenv->load();
        }
    }

    private function loadConfiguration(): void
    {
        $this->config = [
            'database' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => $_ENV['DB_PORT'] ?? 3306,
                'name' => $_ENV['DB_NAME'] ?? 'auth_service',
                'username' => $_ENV['DB_USERNAME'] ?? 'auth_user',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ],
            'jwt' => [
                'secret' => $_ENV['JWT_SECRET'] ?? 'default_secret_change_in_production',
                'expiry' => (int)($_ENV['JWT_EXPIRY'] ?? 3600),
                'refresh_expiry' => (int)($_ENV['JWT_REFRESH_EXPIRY'] ?? 604800),
                'algorithm' => 'HS256',
            ],
            'security' => [
                'rate_limit_requests' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 100),
                'rate_limit_window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 3600),
                'account_lockout_attempts' => (int)($_ENV['ACCOUNT_LOCKOUT_ATTEMPTS'] ?? 5),
                'account_lockout_duration' => (int)($_ENV['ACCOUNT_LOCKOUT_DURATION'] ?? 1800),
                'password_min_length' => 8,
                'password_require_special' => true,
            ],
            'external_services' => [
                'google' => [
                    'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                ],
                'facebook' => [
                    'app_id' => $_ENV['FACEBOOK_APP_ID'] ?? '',
                    'app_secret' => $_ENV['FACEBOOK_APP_SECRET'] ?? '',
                ],
                'apple' => [
                    'client_id' => $_ENV['APPLE_CLIENT_ID'] ?? '',
                    'private_key' => $_ENV['APPLE_PRIVATE_KEY'] ?? '',
                ],
                'sms' => [
                    'api_key' => $_ENV['SMS_PROVIDER_API_KEY'] ?? '',
                ],
                'email' => [
                    'api_key' => $_ENV['EMAIL_SERVICE_API_KEY'] ?? '',
                ],
            ],
        ];
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    public function all(): array
    {
        return $this->config;
    }
}