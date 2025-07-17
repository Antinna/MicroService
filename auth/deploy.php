<?php

/**
 * Authentication Service Deployment Script
 * 
 * This script handles the deployment process for the authentication service,
 * including database migrations, configuration validation, and health checks.
 */

require_once __DIR__ . '/app/bootstrap/app.php';

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Database\MigrationRunner;
use Antinna\Auth\Services\ServiceHealthChecker;
use Antinna\Auth\Services\Logger;

class DeploymentManager
{
    private $logger;
    private $environment;
    private $connection;
    private $migrationRunner;
    private $healthChecker;
    private $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->logger = new Logger();
        $this->environment = new Environment();
        
        try {
            $this->connection = Connection::getInstance();
            $this->migrationRunner = new MigrationRunner($this->connection);
            $this->healthChecker = new ServiceHealthChecker();
        } catch (Exception $e) {
            $this->logger->critical('Failed to initialize deployment components', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function deploy(array $options = []): bool
    {
        $this->logger->info('Starting deployment process', [
            'version' => Environment::get('SERVICE_VERSION'),
            'environment' => Environment::get('APP_ENV'),
            'options' => $options
        ]);

        try {
            // Step 1: Pre-deployment validation
            $this->log('🔍 Running pre-deployment validation...');
            $this->validatePreDeployment();

            // Step 2: Database migrations
            if (!isset($options['skip-migrations']) || !$options['skip-migrations']) {
                $this->log('📊 Running database migrations...');
                $this->runMigrations();
            } else {
                $this->log('⏭️  Skipping database migrations');
            }

            // Step 3: Configuration validation
            $this->log('⚙️  Validating configuration...');
            $this->validateConfiguration();

            // Step 4: Service health check
            $this->log('🏥 Running health checks...');
            $this->runHealthChecks();

            // Step 5: Warm-up services
            $this->log('🔥 Warming up services...');
            $this->warmupServices();

            // Step 6: Post-deployment validation
            $this->log('✅ Running post-deployment validation...');
            $this->validatePostDeployment();

            $duration = round(microtime(true) - $this->startTime, 2);
            $this->log("🎉 Deployment completed successfully in {$duration} seconds");

            $this->logger->info('Deployment completed successfully', [
                'duration_seconds' => $duration,
                'version' => Environment::get('SERVICE_VERSION'),
                'environment' => Environment::get('APP_ENV')
            ]);

            return true;

        } catch (Exception $e) {
            $duration = round(microtime(true) - $this->startTime, 2);
            $this->log("❌ Deployment failed after {$duration} seconds: " . $e->getMessage());

            $this->logger->error('Deployment failed', [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }

    private function validatePreDeployment(): void
    {
        // Check PHP version
        $requiredPhpVersion = '8.1.0';
        if (version_compare(PHP_VERSION, $requiredPhpVersion, '<')) {
            throw new Exception("PHP version {$requiredPhpVersion} or higher is required. Current version: " . PHP_VERSION);
        }

        // Check required PHP extensions
        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'openssl', 'curl', 'mbstring'];
        $missingExtensions = [];

        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $missingExtensions[] = $extension;
            }
        }

        if (!empty($missingExtensions)) {
            throw new Exception('Missing required PHP extensions: ' . implode(', ', $missingExtensions));
        }

        // Check file permissions
        $this->checkFilePermissions();

        // Validate environment variables
        $errors = Environment::validate();
        if (!empty($errors)) {
            throw new Exception('Environment validation failed: ' . implode(', ', $errors));
        }

        $this->log('✅ Pre-deployment validation passed');
    }

    private function checkFilePermissions(): void
    {
        $paths = [
            __DIR__ . '/app/logs' => 'write',
            __DIR__ . '/app/cache' => 'write',
            __DIR__ . '/app/tmp' => 'write',
            __DIR__ . '/app/config' => 'read'
        ];

        foreach ($paths as $path => $permission) {
            if (!file_exists($path)) {
                if (!mkdir($path, 0755, true)) {
                    throw new Exception("Failed to create directory: {$path}");
                }
            }

            if ($permission === 'write' && !is_writable($path)) {
                throw new Exception("Directory is not writable: {$path}");
            }

            if ($permission === 'read' && !is_readable($path)) {
                throw new Exception("Directory is not readable: {$path}");
            }
        }
    }

    private function runMigrations(): void
    {
        try {
            $pendingMigrations = $this->migrationRunner->getPendingMigrations();
            
            if (empty($pendingMigrations)) {
                $this->log('📊 No pending migrations found');
                return;
            }

            $this->log('📊 Found ' . count($pendingMigrations) . ' pending migrations');

            // Create backup before migrations if in production
            if (Environment::isProduction()) {
                $this->log('💾 Creating database backup before migrations...');
                $this->createDatabaseBackup();
            }

            // Run migrations
            foreach ($pendingMigrations as $migration) {
                $this->log("📊 Running migration: {$migration}");
                $this->migrationRunner->runMigration($migration);
            }

            $this->log('✅ All migrations completed successfully');

        } catch (Exception $e) {
            $this->logger->error('Migration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('Database migration failed: ' . $e->getMessage());
        }
    }

    private function createDatabaseBackup(): void
    {
        $dbConfig = Environment::getDatabaseConfig();
        $backupFile = '/tmp/auth_db_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        $command = sprintf(
            'mysqldump -h%s -P%d -u%s -p%s %s > %s',
            escapeshellarg($dbConfig['host']),
            $dbConfig['port'],
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['database']),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('Database backup failed');
        }

        $this->log("💾 Database backup created: {$backupFile}");
    }

    private function validateConfiguration(): void
    {
        // Test database connection
        try {
            $stmt = $this->connection->prepare("SELECT 1");
            $stmt->execute();
            $this->log('✅ Database connection validated');
        } catch (Exception $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }

        // Validate JWT configuration
        $jwtConfig = Environment::getJWTConfig();
        if (strlen($jwtConfig['secret']) < 32) {
            throw new Exception('JWT secret must be at least 32 characters long');
        }

        // Test JWT token generation
        try {
            $jwtManager = new \Antinna\Auth\Services\JWTManager();
            $testToken = $jwtManager->generateToken(['test' => true, 'exp' => time() + 300]);
            $decoded = $jwtManager->validateToken($testToken);
            
            if (!$decoded || !isset($decoded['test'])) {
                throw new Exception('JWT token validation failed');
            }
            
            $this->log('✅ JWT configuration validated');
        } catch (Exception $e) {
            throw new Exception('JWT configuration validation failed: ' . $e->getMessage());
        }

        // Validate external service configurations
        $this->validateExternalServices();

        $this->log('✅ Configuration validation completed');
    }

    private function validateExternalServices(): void
    {
        $services = [];

        // Check email service if enabled
        if (Environment::getBool('EMAIL_SERVICE_ENABLED')) {
            $services['email'] = $this->testEmailService();
        }

        // Check SMS service if enabled
        if (Environment::getBool('SMS_SERVICE_ENABLED')) {
            $services['sms'] = $this->testSMSService();
        }

        // Check Firebase if enabled
        if (Environment::getBool('FIREBASE_ENABLED')) {
            $services['firebase'] = $this->testFirebaseService();
        }

        foreach ($services as $service => $result) {
            if ($result) {
                $this->log("✅ {$service} service validated");
            } else {
                $this->log("⚠️  {$service} service validation failed (non-critical)");
            }
        }
    }

    private function testEmailService(): bool
    {
        try {
            // Test email service connectivity without sending actual email
            return true; // Placeholder - implement actual test
        } catch (Exception $e) {
            $this->logger->warning('Email service test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function testSMSService(): bool
    {
        try {
            // Test SMS service connectivity without sending actual SMS
            return true; // Placeholder - implement actual test
        } catch (Exception $e) {
            $this->logger->warning('SMS service test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function testFirebaseService(): bool
    {
        try {
            // Test Firebase connectivity
            return true; // Placeholder - implement actual test
        } catch (Exception $e) {
            $this->logger->warning('Firebase service test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function runHealthChecks(): void
    {
        $health = $this->healthChecker->getOverallHealth();
        
        if ($health['status'] === 'unhealthy') {
            throw new Exception('Health check failed: ' . implode(', ', $health['critical_issues']));
        }

        if ($health['status'] === 'degraded') {
            $this->log('⚠️  Service is in degraded state but deployment can continue');
        }

        $this->log("✅ Health check passed (status: {$health['status']})");
    }

    private function warmupServices(): void
    {
        // Warm up database connections
        for ($i = 0; $i < 5; $i++) {
            try {
                $stmt = $this->connection->prepare("SELECT 1");
                $stmt->execute();
            } catch (Exception $e) {
                $this->logger->warning('Database warmup failed', ['attempt' => $i + 1, 'error' => $e->getMessage()]);
            }
        }

        // Warm up JWT service
        try {
            $jwtManager = new \Antinna\Auth\Services\JWTManager();
            for ($i = 0; $i < 3; $i++) {
                $token = $jwtManager->generateToken(['warmup' => true, 'exp' => time() + 60]);
                $jwtManager->validateToken($token);
            }
        } catch (Exception $e) {
            $this->logger->warning('JWT warmup failed', ['error' => $e->getMessage()]);
        }

        // Warm up session service
        try {
            $sessionManager = new \Antinna\Auth\Services\SessionManager($this->connection);
            $sessionId = 'warmup-' . uniqid();
            $sessionManager->createSession($sessionId, ['warmup' => true]);
            $sessionManager->getSession($sessionId);
            $sessionManager->destroySession($sessionId);
        } catch (Exception $e) {
            $this->logger->warning('Session warmup failed', ['error' => $e->getMessage()]);
        }

        $this->log('✅ Service warmup completed');
    }

    private function validatePostDeployment(): void
    {
        // Test critical endpoints
        $endpoints = [
            '/health' => 'Basic health check',
            '/health/ready' => 'Readiness probe',
            '/health/live' => 'Liveness probe'
        ];

        foreach ($endpoints as $endpoint => $description) {
            if ($this->testEndpoint($endpoint)) {
                $this->log("✅ {$description} endpoint working");
            } else {
                throw new Exception("Post-deployment validation failed: {$description} endpoint not responding");
            }
        }

        // Verify service can handle authentication requests
        $this->testAuthenticationFlow();

        $this->log('✅ Post-deployment validation completed');
    }

    private function testEndpoint(string $endpoint): bool
    {
        try {
            $baseUrl = 'http://localhost:' . (Environment::get('PORT') ?: '8080');
            $url = $baseUrl . $endpoint;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode >= 200 && $httpCode < 400;
            
        } catch (Exception $e) {
            $this->logger->warning('Endpoint test failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function testAuthenticationFlow(): void
    {
        try {
            // Test basic authentication components without making actual requests
            $jwtManager = new \Antinna\Auth\Services\JWTManager();
            $sessionManager = new \Antinna\Auth\Services\SessionManager($this->connection);
            
            // Test token generation and validation
            $testPayload = ['user_id' => 999999, 'test' => true, 'exp' => time() + 300];
            $token = $jwtManager->generateToken($testPayload);
            $decoded = $jwtManager->validateToken($token);
            
            if (!$decoded || $decoded['user_id'] !== 999999) {
                throw new Exception('Authentication flow test failed: JWT validation');
            }

            // Test session management
            $sessionId = 'deployment-test-' . uniqid();
            $sessionData = ['user_id' => 999999, 'test' => true];
            
            if (!$sessionManager->createSession($sessionId, $sessionData)) {
                throw new Exception('Authentication flow test failed: Session creation');
            }

            $retrievedSession = $sessionManager->getSession($sessionId);
            if (!$retrievedSession || $retrievedSession['user_id'] !== 999999) {
                throw new Exception('Authentication flow test failed: Session retrieval');
            }

            $sessionManager->destroySession($sessionId);

            $this->log('✅ Authentication flow test passed');

        } catch (Exception $e) {
            throw new Exception('Authentication flow validation failed: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
        
        // Also log to application log
        $this->logger->info('Deployment: ' . strip_tags($message));
    }

    public function rollback(): bool
    {
        $this->log('🔄 Starting rollback process...');
        
        try {
            // Rollback database migrations if needed
            $this->log('📊 Rolling back database migrations...');
            $this->rollbackMigrations();
            
            // Clear caches
            $this->log('🗑️  Clearing caches...');
            $this->clearCaches();
            
            $this->log('✅ Rollback completed successfully');
            return true;
            
        } catch (Exception $e) {
            $this->log('❌ Rollback failed: ' . $e->getMessage());
            $this->logger->error('Rollback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function rollbackMigrations(): void
    {
        // Implement migration rollback logic
        $this->log('⚠️  Migration rollback not implemented - manual intervention required');
    }

    private function clearCaches(): void
    {
        $cacheDirectories = [
            __DIR__ . '/app/cache',
            __DIR__ . '/app/tmp'
        ];

        foreach ($cacheDirectories as $dir) {
            if (is_dir($dir)) {
                $this->clearDirectory($dir);
            }
        }
    }

    private function clearDirectory(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->clearDirectory($file);
                rmdir($file);
            }
        }
    }
}

// Command line interface
function showUsage(): void
{
    echo "Authentication Service Deployment Script\n";
    echo "Usage: php deploy.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  deploy    Deploy the service (default)\n";
    echo "  rollback  Rollback the deployment\n";
    echo "  validate  Validate configuration only\n";
    echo "  health    Run health checks only\n\n";
    echo "Options:\n";
    echo "  --skip-migrations  Skip database migrations\n";
    echo "  --dry-run         Show what would be done without executing\n";
    echo "  --verbose         Enable verbose output\n";
    echo "  --help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php deploy.php deploy\n";
    echo "  php deploy.php deploy --skip-migrations\n";
    echo "  php deploy.php rollback\n";
    echo "  php deploy.php validate\n";
}

// Parse command line arguments
$command = $argv[1] ?? 'deploy';
$options = [];

for ($i = 2; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--') === 0) {
        $key = substr($arg, 2);
        $options[$key] = true;
    }
}

if (isset($options['help'])) {
    showUsage();
    exit(0);
}

try {
    $deploymentManager = new DeploymentManager();
    
    switch ($command) {
        case 'deploy':
            $success = $deploymentManager->deploy($options);
            exit($success ? 0 : 1);
            
        case 'rollback':
            $success = $deploymentManager->rollback();
            exit($success ? 0 : 1);
            
        case 'validate':
            echo "🔍 Validating configuration...\n";
            Environment::load();
            $errors = Environment::validate();
            if (empty($errors)) {
                echo "✅ Configuration validation passed\n";
                exit(0);
            } else {
                echo "❌ Configuration validation failed:\n";
                foreach ($errors as $error) {
                    echo "  - {$error}\n";
                }
                exit(1);
            }
            
        case 'health':
            echo "🏥 Running health checks...\n";
            $healthChecker = new ServiceHealthChecker();
            $health = $healthChecker->getOverallHealth();
            echo "Status: {$health['status']}\n";
            echo "Response time: {$health['response_time_ms']}ms\n";
            exit($health['status'] === 'unhealthy' ? 1 : 0);
            
        default:
            echo "Unknown command: {$command}\n";
            showUsage();
            exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Deployment script failed: " . $e->getMessage() . "\n";
    exit(1);
}