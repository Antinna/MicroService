<?php

namespace Antinna\Auth\Config;

class Environment
{
    private static $config = null;
    private static $requiredVars = [
        'DB_HOST',
        'DB_PORT', 
        'DB_NAME',
        'DB_USERNAME',
        'DB_PASSWORD',
        'JWT_SECRET',
        'ENCRYPTION_KEY'
    ];

    public static function load(): void
    {
        if (self::$config !== null) {
            return;
        }

        // Load environment variables from .env file if it exists
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            self::loadFromFile($envFile);
        }

        // Validate required environment variables
        self::validateRequired();

        // Set default values for optional variables
        self::setDefaults();

        self::$config = $_ENV;
    }

    private static function loadFromFile(string $filePath): void
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                    $value = $matches[2];
                }

                // Set environment variable if not already set
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }

    private static function validateRequired(): void
    {
        $missing = [];
        
        foreach (self::$requiredVars as $var) {
            if (!isset($_ENV[$var]) || empty($_ENV[$var])) {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
    }

    private static function setDefaults(): void
    {
        $defaults = [
            // Application settings
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'SERVICE_VERSION' => '1.0.0',
            'SERVICE_NAME' => 'auth-service',
            
            // Database settings
            'DB_CHARSET' => 'utf8mb4',
            'DB_COLLATION' => 'utf8mb4_unicode_ci',
            'DB_POOL_SIZE' => '10',
            'DB_TIMEOUT' => '30',
            
            // JWT settings
            'JWT_ALGORITHM' => 'HS256',
            'JWT_EXPIRY' => '3600',
            'JWT_REFRESH_EXPIRY' => '86400',
            'JWT_ISSUER' => 'auth-service',
            
            // Session settings
            'SESSION_LIFETIME' => '3600',
            'SESSION_CLEANUP_INTERVAL' => '300',
            
            // Rate limiting
            'RATE_LIMIT_ENABLED' => 'true',
            'RATE_LIMIT_MAX_ATTEMPTS' => '10',
            'RATE_LIMIT_WINDOW' => '60',
            'RATE_LIMIT_LOCKOUT_DURATION' => '300',
            
            // Password policy
            'PASSWORD_MIN_LENGTH' => '8',
            'PASSWORD_REQUIRE_UPPERCASE' => 'true',
            'PASSWORD_REQUIRE_LOWERCASE' => 'true',
            'PASSWORD_REQUIRE_NUMBERS' => 'true',
            'PASSWORD_REQUIRE_SPECIAL' => 'true',
            'PASSWORD_MAX_AGE_DAYS' => '90',
            'PASSWORD_HISTORY_COUNT' => '5',
            
            // MFA settings
            'MFA_CODE_EXPIRY' => '300',
            'MFA_BACKUP_CODES_COUNT' => '5',
            'TOTP_WINDOW' => '1',
            'TOTP_PERIOD' => '30',
            
            // Magic link settings
            'MAGIC_LINK_ENABLED' => 'true',
            'MAGIC_LINK_EXPIRY' => '900',
            'MAGIC_LINK_MAX_USES' => '1',
            
            // Biometric settings
            'BIOMETRIC_ENABLED' => 'true',
            'BIOMETRIC_TIMEOUT' => '60',
            'BIOMETRIC_MAX_DEVICES' => '5',
            
            // Passkey settings
            'PASSKEY_ENABLED' => 'true',
            'PASSKEY_TIMEOUT' => '60',
            'PASSKEY_MAX_DEVICES' => '10',
            'PASSKEY_USER_VERIFICATION' => 'preferred',
            
            // Social authentication
            'SOCIAL_AUTH_ENABLED' => 'true',
            'OAUTH_TIMEOUT' => '30',
            
            // External services
            'EMAIL_SERVICE_ENABLED' => 'false',
            'SMS_SERVICE_ENABLED' => 'false',
            'FIREBASE_ENABLED' => 'false',
            
            // Push notifications
            'PUSH_NOTIFICATIONS_ENABLED' => 'false',
            'FIREBASE_PROJECT_ID' => '',
            'FIREBASE_PRIVATE_KEY' => '',
            'FIREBASE_CLIENT_EMAIL' => '',
            
            // Offline authentication
            'OFFLINE_AUTH_ENABLED' => 'true',
            'OFFLINE_TOKEN_EXPIRY' => '86400',
            'OFFLINE_SYNC_INTERVAL' => '300',
            
            // Logging
            'LOG_LEVEL' => 'INFO',
            'LOG_FILE' => '/var/log/auth-service/application.log',
            'LOG_MAX_SIZE' => '100MB',
            'LOG_MAX_FILES' => '10',
            
            // Audit logging
            'AUDIT_LOG_ENABLED' => 'true',
            'AUDIT_LOG_FILE' => '/var/log/auth-service/audit.log',
            'AUDIT_LOG_RETENTION_DAYS' => '365',
            
            // Security monitoring
            'SECURITY_MONITORING_ENABLED' => 'true',
            'SECURITY_ALERT_THRESHOLD' => '5',
            'SECURITY_LOCKOUT_THRESHOLD' => '10',
            
            // Performance settings
            'MAX_CONCURRENT_REQUESTS' => '100',
            'REQUEST_TIMEOUT' => '30',
            'MEMORY_LIMIT' => '256M',
            'MAX_EXECUTION_TIME' => '30',
            
            // Health check settings
            'HEALTH_CHECK_ENABLED' => 'true',
            'HEALTH_CHECK_TIMEOUT' => '5',
            'HEALTH_CHECK_INTERVAL' => '30',
            
            // Metrics and monitoring
            'METRICS_ENABLED' => 'true',
            'METRICS_RETENTION_DAYS' => '30',
            'PROMETHEUS_ENABLED' => 'false',
            
            // CORS settings
            'CORS_ENABLED' => 'true',
            'CORS_ALLOWED_ORIGINS' => '*',
            'CORS_ALLOWED_METHODS' => 'GET,POST,PUT,DELETE,OPTIONS',
            'CORS_ALLOWED_HEADERS' => 'Content-Type,Authorization,X-Requested-With',
            'CORS_MAX_AGE' => '86400',
            
            // SSL/TLS settings
            'SSL_VERIFY_PEER' => 'true',
            'SSL_VERIFY_HOST' => 'true',
            'SSL_CAFILE' => '',
            
            // Timezone
            'TIMEZONE' => 'UTC'
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    public static function get(string $key, $default = null)
    {
        if (self::$config === null) {
            self::load();
        }

        return $_ENV[$key] ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        
        if (is_bool($value)) {
            return $value;
        }
        
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    public static function getArray(string $key, array $default = []): array
    {
        $value = self::get($key);
        
        if ($value === null) {
            return $default;
        }
        
        if (is_array($value)) {
            return $value;
        }
        
        // Parse comma-separated values
        return array_map('trim', explode(',', $value));
    }

    public static function isDevelopment(): bool
    {
        return self::get('APP_ENV') === 'development';
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }

    public static function isTesting(): bool
    {
        return self::get('APP_ENV') === 'testing';
    }

    public static function isDebugEnabled(): bool
    {
        return self::getBool('APP_DEBUG');
    }

    public static function getServiceInfo(): array
    {
        return [
            'name' => self::get('SERVICE_NAME'),
            'version' => self::get('SERVICE_VERSION'),
            'environment' => self::get('APP_ENV'),
            'debug' => self::isDebugEnabled(),
            'timezone' => self::get('TIMEZONE')
        ];
    }

    public static function getDatabaseConfig(): array
    {
        return [
            'host' => self::get('DB_HOST'),
            'port' => self::getInt('DB_PORT', 3306),
            'database' => self::get('DB_NAME'),
            'username' => self::get('DB_USERNAME'),
            'password' => self::get('DB_PASSWORD'),
            'charset' => self::get('DB_CHARSET'),
            'collation' => self::get('DB_COLLATION'),
            'pool_size' => self::getInt('DB_POOL_SIZE'),
            'timeout' => self::getInt('DB_TIMEOUT')
        ];
    }

    public static function getJWTConfig(): array
    {
        return [
            'secret' => self::get('JWT_SECRET'),
            'algorithm' => self::get('JWT_ALGORITHM'),
            'expiry' => self::getInt('JWT_EXPIRY'),
            'refresh_expiry' => self::getInt('JWT_REFRESH_EXPIRY'),
            'issuer' => self::get('JWT_ISSUER')
        ];
    }

    public static function getSecurityConfig(): array
    {
        return [
            'rate_limit_enabled' => self::getBool('RATE_LIMIT_ENABLED'),
            'rate_limit_max_attempts' => self::getInt('RATE_LIMIT_MAX_ATTEMPTS'),
            'rate_limit_window' => self::getInt('RATE_LIMIT_WINDOW'),
            'rate_limit_lockout_duration' => self::getInt('RATE_LIMIT_LOCKOUT_DURATION'),
            'password_min_length' => self::getInt('PASSWORD_MIN_LENGTH'),
            'password_require_uppercase' => self::getBool('PASSWORD_REQUIRE_UPPERCASE'),
            'password_require_lowercase' => self::getBool('PASSWORD_REQUIRE_LOWERCASE'),
            'password_require_numbers' => self::getBool('PASSWORD_REQUIRE_NUMBERS'),
            'password_require_special' => self::getBool('PASSWORD_REQUIRE_SPECIAL'),
            'password_max_age_days' => self::getInt('PASSWORD_MAX_AGE_DAYS'),
            'password_history_count' => self::getInt('PASSWORD_HISTORY_COUNT'),
            'security_monitoring_enabled' => self::getBool('SECURITY_MONITORING_ENABLED'),
            'audit_log_enabled' => self::getBool('AUDIT_LOG_ENABLED')
        ];
    }

    public static function getFeatureFlags(): array
    {
        return [
            'magic_link_enabled' => self::getBool('MAGIC_LINK_ENABLED'),
            'biometric_enabled' => self::getBool('BIOMETRIC_ENABLED'),
            'passkey_enabled' => self::getBool('PASSKEY_ENABLED'),
            'social_auth_enabled' => self::getBool('SOCIAL_AUTH_ENABLED'),
            'push_notifications_enabled' => self::getBool('PUSH_NOTIFICATIONS_ENABLED'),
            'offline_auth_enabled' => self::getBool('OFFLINE_AUTH_ENABLED'),
            'email_service_enabled' => self::getBool('EMAIL_SERVICE_ENABLED'),
            'sms_service_enabled' => self::getBool('SMS_SERVICE_ENABLED'),
            'firebase_enabled' => self::getBool('FIREBASE_ENABLED'),
            'metrics_enabled' => self::getBool('METRICS_ENABLED'),
            'prometheus_enabled' => self::getBool('PROMETHEUS_ENABLED')
        ];
    }

    public static function validate(): array
    {
        $errors = [];

        // Validate JWT secret strength
        $jwtSecret = self::get('JWT_SECRET');
        if (strlen($jwtSecret) < 32) {
            $errors[] = 'JWT_SECRET must be at least 32 characters long';
        }

        // Validate encryption key
        $encryptionKey = self::get('ENCRYPTION_KEY');
        if (strlen($encryptionKey) !== 32) {
            $errors[] = 'ENCRYPTION_KEY must be exactly 32 characters long';
        }

        // Validate database configuration
        $dbConfig = self::getDatabaseConfig();
        if (empty($dbConfig['host'])) {
            $errors[] = 'DB_HOST cannot be empty';
        }
        if ($dbConfig['port'] < 1 || $dbConfig['port'] > 65535) {
            $errors[] = 'DB_PORT must be between 1 and 65535';
        }

        // Validate password policy
        $minLength = self::getInt('PASSWORD_MIN_LENGTH');
        if ($minLength < 6 || $minLength > 128) {
            $errors[] = 'PASSWORD_MIN_LENGTH must be between 6 and 128';
        }

        // Validate rate limiting
        $maxAttempts = self::getInt('RATE_LIMIT_MAX_ATTEMPTS');
        if ($maxAttempts < 1 || $maxAttempts > 1000) {
            $errors[] = 'RATE_LIMIT_MAX_ATTEMPTS must be between 1 and 1000';
        }

        // Validate token expiry times
        $jwtExpiry = self::getInt('JWT_EXPIRY');
        if ($jwtExpiry < 300 || $jwtExpiry > 86400) {
            $errors[] = 'JWT_EXPIRY must be between 300 (5 minutes) and 86400 (24 hours)';
        }

        return $errors;
    }

    public static function export(): array
    {
        if (self::$config === null) {
            self::load();
        }

        // Return non-sensitive configuration for debugging
        $config = self::$config;
        
        // Remove sensitive values
        $sensitive = [
            'DB_PASSWORD',
            'JWT_SECRET',
            'ENCRYPTION_KEY',
            'FIREBASE_PRIVATE_KEY',
            'OAUTH_CLIENT_SECRET',
            'SMS_API_KEY',
            'EMAIL_API_KEY'
        ];

        foreach ($sensitive as $key) {
            if (isset($config[$key])) {
                $config[$key] = '[REDACTED]';
            }
        }

        return $config;
    }
}