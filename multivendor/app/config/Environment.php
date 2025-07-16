<?php

namespace Antinna\Multivendor\Config;

class Environment
{
    private static ?Environment $instance = null;
    private array $config = [];
    private string $environment;

    private function __construct()
    {
        $this->environment = $this->detectEnvironment();
        $this->loadConfiguration();
    }

    public static function getInstance(): Environment
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detect the current environment
     */
    private function detectEnvironment(): string
    {
        $env = getenv('APP_ENV');
        
        if ($env) {
            return strtolower($env);
        }
        
        // Auto-detect based on various indicators
        if (isset($_SERVER['SERVER_NAME'])) {
            $serverName = $_SERVER['SERVER_NAME'];
            
            if (strpos($serverName, 'localhost') !== false || 
                strpos($serverName, '127.0.0.1') !== false ||
                strpos($serverName, '.local') !== false) {
                return 'development';
            }
            
            if (strpos($serverName, 'staging') !== false ||
                strpos($serverName, 'test') !== false) {
                return 'staging';
            }
        }
        
        // Default to production for safety
        return 'production';
    }

    /**
     * Load environment-specific configuration
     */
    private function loadConfiguration(): void
    {
        // Base configuration
        $this->config = [
            'app' => [
                'name' => getenv('APP_NAME') ?: 'Multivendor Service',
                'version' => getenv('APP_VERSION') ?: '1.0.0',
                'debug' => $this->getBooleanEnv('APP_DEBUG', false),
                'environment' => $this->environment,
                'timezone' => getenv('APP_TIMEZONE') ?: 'UTC'
            ],
            
            'database' => [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'name' => getenv('DB_NAME') ?: 'multivendor_db',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'options' => [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            ],
            
            'admin' => [
                'username' => getenv('ADMIN_USERNAME') ?: 'admin',
                'password' => getenv('ADMIN_PASSWORD') ?: 'admin',
                'session_timeout' => (int)(getenv('ADMIN_SESSION_TIMEOUT') ?: 3600)
            ],
            
            'services' => [
                'auth' => getenv('AUTH_SERVICE_URL') ?: 'http://localhost:8001',
                'pay' => getenv('PAY_SERVICE_URL') ?: 'http://localhost:8002',
                'social' => getenv('SOCIAL_SERVICE_URL') ?: 'http://localhost:8003',
                'delivery' => getenv('DELIVERY_SERVICE_URL') ?: 'http://localhost:8004'
            ],
            
            'logging' => [
                'level' => getenv('LOG_LEVEL') ?: ($this->environment === 'production' ? 'INFO' : 'DEBUG'),
                'path' => getenv('LOG_PATH') ?: __DIR__ . '/../../logs',
                'max_files' => (int)(getenv('LOG_MAX_FILES') ?: 30),
                'max_size' => getenv('LOG_MAX_SIZE') ?: '10MB'
            ],
            
            'security' => [
                'session_secure' => $this->getBooleanEnv('SESSION_SECURE', $this->environment === 'production'),
                'session_httponly' => $this->getBooleanEnv('SESSION_HTTPONLY', true),
                'session_samesite' => getenv('SESSION_SAMESITE') ?: 'Strict',
                'csrf_protection' => $this->getBooleanEnv('CSRF_PROTECTION', true)
            ],
            
            'performance' => [
                'memory_limit' => getenv('MEMORY_LIMIT') ?: '256M',
                'max_execution_time' => (int)(getenv('MAX_EXECUTION_TIME') ?: 300),
                'cache_enabled' => $this->getBooleanEnv('CACHE_ENABLED', true),
                'cache_ttl' => (int)(getenv('CACHE_TTL') ?: 3600)
            ],
            
            'external' => [
                'firebase' => [
                    'project_id' => getenv('FIREBASE_PROJECT_ID'),
                    'private_key' => getenv('FIREBASE_PRIVATE_KEY')
                ],
                'sms' => [
                    'api_key' => getenv('SMS_API_KEY'),
                    'provider' => getenv('SMS_PROVIDER') ?: 'twilio'
                ],
                'email' => [
                    'smtp_host' => getenv('EMAIL_SMTP_HOST'),
                    'smtp_port' => (int)(getenv('EMAIL_SMTP_PORT') ?: 587),
                    'smtp_username' => getenv('EMAIL_SMTP_USERNAME'),
                    'smtp_password' => getenv('EMAIL_SMTP_PASSWORD'),
                    'from_address' => getenv('EMAIL_FROM_ADDRESS') ?: 'noreply@multivendor.com',
                    'from_name' => getenv('EMAIL_FROM_NAME') ?: 'Multivendor Service'
                ]
            ]
        ];

        // Apply environment-specific overrides
        $this->applyEnvironmentOverrides();
    }

    /**
     * Apply environment-specific configuration overrides
     */
    private function applyEnvironmentOverrides(): void
    {
        switch ($this->environment) {
            case 'development':
                $this->config['app']['debug'] = true;
                $this->config['logging']['level'] = 'DEBUG';
                $this->config['security']['session_secure'] = false;
                $this->config['performance']['cache_enabled'] = false;
                break;
                
            case 'staging':
                $this->config['app']['debug'] = true;
                $this->config['logging']['level'] = 'DEBUG';
                break;
                
            case 'production':
                $this->config['app']['debug'] = false;
                $this->config['logging']['level'] = 'INFO';
                $this->config['security']['session_secure'] = true;
                $this->config['performance']['cache_enabled'] = true;
                break;
        }
    }

    /**
     * Get configuration value
     */
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

    /**
     * Set configuration value
     */
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

    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }
        
        return true;
    }

    /**
     * Get all configuration
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Get current environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Check if current environment matches
     */
    public function is(string $environment): bool
    {
        return $this->environment === strtolower($environment);
    }

    /**
     * Check if running in production
     */
    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    /**
     * Check if running in development
     */
    public function isDevelopment(): bool
    {
        return $this->environment === 'development';
    }

    /**
     * Check if running in staging
     */
    public function isStaging(): bool
    {
        return $this->environment === 'staging';
    }

    /**
     * Get boolean environment variable
     */
    private function getBooleanEnv(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Validate configuration
     */
    public function validate(): array
    {
        $errors = [];
        
        // Validate required database configuration
        if (empty($this->config['database']['host'])) {
            $errors[] = 'Database host is required';
        }
        
        if (empty($this->config['database']['name'])) {
            $errors[] = 'Database name is required';
        }
        
        if (empty($this->config['database']['username'])) {
            $errors[] = 'Database username is required';
        }
        
        // Validate admin configuration
        if (empty($this->config['admin']['username'])) {
            $errors[] = 'Admin username is required';
        }
        
        if (empty($this->config['admin']['password'])) {
            $errors[] = 'Admin password is required';
        }
        
        // Validate logging configuration
        if (!is_dir(dirname($this->config['logging']['path']))) {
            $errors[] = 'Log directory parent does not exist: ' . dirname($this->config['logging']['path']);
        }
        
        // Validate service URLs in production
        if ($this->isProduction()) {
            foreach ($this->config['services'] as $service => $url) {
                if (strpos($url, 'localhost') !== false) {
                    $errors[] = "Service URL for {$service} should not use localhost in production";
                }
            }
        }
        
        return $errors;
    }

    /**
     * Export configuration for debugging
     */
    public function export(bool $hideSensitive = true): array
    {
        $config = $this->config;
        
        if ($hideSensitive) {
            // Hide sensitive information
            $config['database']['password'] = '[HIDDEN]';
            $config['admin']['password'] = '[HIDDEN]';
            $config['external']['firebase']['private_key'] = '[HIDDEN]';
            $config['external']['sms']['api_key'] = '[HIDDEN]';
            $config['external']['email']['smtp_password'] = '[HIDDEN]';
        }
        
        return $config;
    }
}