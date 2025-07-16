<?php

namespace Antinna\MultiVendor\Config;

/**
 * Application configuration class
 */
class App
{
    private static ?App $instance = null;
    private array $config;

    private function __construct()
    {
        $this->config = [
            'name' => 'Multivendor Service',
            'version' => '1.0.0',
            'environment' => $_ENV['APP_ENV'] ?? 'production',
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
            
            // Admin panel configuration
            'admin' => [
                'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
                'password' => $_ENV['ADMIN_PASSWORD'] ?? 'admin',
            ],
            
            // External service URLs
            'services' => [
                'auth' => $_ENV['AUTH_SERVICE_URL'] ?? 'http://localhost:8001',
                'pay' => $_ENV['PAY_SERVICE_URL'] ?? 'http://localhost:8002',
                'social' => $_ENV['SOCIAL_SERVICE_URL'] ?? 'http://localhost:8003',
                'delivery' => $_ENV['DELIVERY_SERVICE_URL'] ?? 'http://localhost:8004',
            ],
            
            // Notification settings
            'notifications' => [
                'firebase_key' => $_ENV['FIREBASE_SERVER_KEY'] ?? '',
                'sms_api_key' => $_ENV['SMS_API_KEY'] ?? '',
                'email_smtp_host' => $_ENV['EMAIL_SMTP_HOST'] ?? '',
                'email_smtp_port' => $_ENV['EMAIL_SMTP_PORT'] ?? 587,
                'email_username' => $_ENV['EMAIL_USERNAME'] ?? '',
                'email_password' => $_ENV['EMAIL_PASSWORD'] ?? '',
            ]
        ];
    }

    public static function getInstance(): App
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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

    public function getAll(): array
    {
        return $this->config;
    }
}