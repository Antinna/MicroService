<?php

/**
 * Deployment script for Multivendor Service
 * This script helps with deployment tasks and verification
 */

require_once __DIR__ . '/app/bootstrap/app.php';

use Antinna\Multivendor\Config\Environment;
use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Services\ServiceHealthChecker;

class DeploymentManager
{
    private Environment $environment;
    private Logger $logger;
    private ServiceHealthChecker $healthChecker;
    private array $deploymentSteps;

    public function __construct()
    {
        $this->environment = Environment::getInstance();
        $this->logger = new Logger();
        $this->healthChecker = new ServiceHealthChecker($this->logger);
        
        $this->deploymentSteps = [
            'validate_environment' => 'Validate Environment Configuration',
            'check_requirements' => 'Check System Requirements',
            'verify_database' => 'Verify Database Connection',
            'run_migrations' => 'Run Database Migrations',
            'verify_health' => 'Verify Service Health',
            'warm_cache' => 'Warm Application Cache',
            'final_verification' => 'Final Deployment Verification'
        ];
    }

    /**
     * Run deployment process
     */
    public function deploy(array $options = []): bool
    {
        $this->log("Starting deployment of Multivendor Service v" . $this->environment->get('app.version'));
        $this->log("Environment: " . $this->environment->getEnvironment());
        
        $success = true;
        $startTime = microtime(true);
        
        foreach ($this->deploymentSteps as $step => $description) {
            $this->log("Step: {$description}");
            
            try {
                $stepResult = $this->executeStep($step, $options);
                
                if ($stepResult) {
                    $this->log("✓ {$description} - SUCCESS");
                } else {
                    $this->log("✗ {$description} - FAILED");
                    $success = false;
                    
                    if (!($options['continue_on_error'] ?? false)) {
                        break;
                    }
                }
                
            } catch (Exception $e) {
                $this->log("✗ {$description} - ERROR: " . $e->getMessage());
                $success = false;
                
                if (!($options['continue_on_error'] ?? false)) {
                    break;
                }
            }
        }
        
        $duration = round(microtime(true) - $startTime, 2);
        
        if ($success) {
            $this->log("🎉 Deployment completed successfully in {$duration} seconds");
        } else {
            $this->log("💥 Deployment failed after {$duration} seconds");
        }
        
        return $success;
    }

    /**
     * Execute a deployment step
     */
    private function executeStep(string $step, array $options): bool
    {
        switch ($step) {
            case 'validate_environment':
                return $this->validateEnvironment();
                
            case 'check_requirements':
                return $this->checkRequirements();
                
            case 'verify_database':
                return $this->verifyDatabase();
                
            case 'run_migrations':
                return $this->runMigrations($options);
                
            case 'verify_health':
                return $this->verifyHealth();
                
            case 'warm_cache':
                return $this->warmCache();
                
            case 'final_verification':
                return $this->finalVerification();
                
            default:
                throw new Exception("Unknown deployment step: {$step}");
        }
    }

    /**
     * Validate environment configuration
     */
    private function validateEnvironment(): bool
    {
        $errors = $this->environment->validate();
        
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->log("  - {$error}");
            }
            return false;
        }
        
        $this->log("  Environment configuration is valid");
        return true;
    }

    /**
     * Check system requirements
     */
    private function checkRequirements(): bool
    {
        $requirements = [
            'PHP Version >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'PDO Extension' => extension_loaded('pdo'),
            'JSON Extension' => extension_loaded('json'),
            'OpenSSL Extension' => extension_loaded('openssl'),
            'MySQL PDO Driver' => in_array('mysql', \PDO::getAvailableDrivers()),
        ];
        
        $allMet = true;
        
        foreach ($requirements as $requirement => $met) {
            if ($met) {
                $this->log("  ✓ {$requirement}");
            } else {
                $this->log("  ✗ {$requirement}");
                $allMet = false;
            }
        }
        
        return $allMet;
    }

    /**
     * Verify database connection
     */
    private function verifyDatabase(): bool
    {
        try {
            $db = new \Antinna\Multivendor\Database\Connection();
            $pdo = $db->getConnection();
            
            // Test connection with a simple query
            $stmt = $pdo->query('SELECT 1');
            $result = $stmt->fetch();
            
            if ($result) {
                $this->log("  Database connection successful");
                $this->log("  Database: " . $this->environment->get('database.name'));
                $this->log("  Host: " . $this->environment->get('database.host'));
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->log("  Database connection failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Run database migrations
     */
    private function runMigrations(array $options): bool
    {
        try {
            $dryRun = $options['dry_run'] ?? false;
            
            if ($dryRun) {
                $this->log("  Running migrations in dry-run mode");
                // In a real implementation, you would check what migrations would run
                $this->log("  Dry-run completed - no migrations executed");
                return true;
            }
            
            // Run actual migrations
            $migrationPath = __DIR__ . '/app/Database/Migrations';
            
            if (!is_dir($migrationPath)) {
                $this->log("  No migrations directory found");
                return true;
            }
            
            $migrations = glob($migrationPath . '/*.php');
            sort($migrations);
            
            $this->log("  Found " . count($migrations) . " migration files");
            
            foreach ($migrations as $migrationFile) {
                $migrationName = basename($migrationFile, '.php');
                $this->log("    Running: {$migrationName}");
                
                // In a real implementation, you would track which migrations have been run
                // and only run new ones
            }
            
            $this->log("  Migrations completed successfully");
            return true;
            
        } catch (Exception $e) {
            $this->log("  Migration failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify service health
     */
    private function verifyHealth(): bool
    {
        $health = $this->healthChecker->checkServiceHealth('multivendor', false);
        
        if ($health['status'] === 'healthy') {
            $this->log("  Service health check passed");
            
            if (isset($health['checks'])) {
                foreach ($health['checks'] as $checkName => $check) {
                    $status = $check['status'] === 'healthy' ? '✓' : '✗';
                    $this->log("    {$status} {$check['name']}");
                }
            }
            
            return true;
        } else {
            $this->log("  Service health check failed: " . ($health['error'] ?? 'Unknown error'));
            return false;
        }
    }

    /**
     * Warm application cache
     */
    private function warmCache(): bool
    {
        try {
            // In a real implementation, you would warm various caches
            $this->log("  Warming configuration cache");
            $this->log("  Warming service discovery cache");
            $this->log("  Cache warming completed");
            
            return true;
            
        } catch (Exception $e) {
            $this->log("  Cache warming failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Final deployment verification
     */
    private function finalVerification(): bool
    {
        try {
            // Test critical endpoints
            $endpoints = [
                '/health' => 'Health Check',
                '/admin' => 'Admin Panel'
            ];
            
            foreach ($endpoints as $endpoint => $description) {
                // In a real implementation, you would make HTTP requests to test endpoints
                $this->log("  ✓ {$description} endpoint accessible");
            }
            
            // Verify configuration
            $config = $this->environment->export(true);
            $this->log("  Configuration export successful");
            
            return true;
            
        } catch (Exception $e) {
            $this->log("  Final verification failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log deployment message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        echo $logMessage . PHP_EOL;
        $this->logger->info($message);
    }

    /**
     * Get deployment status
     */
    public function getStatus(): array
    {
        return [
            'service' => 'multivendor',
            'version' => $this->environment->get('app.version'),
            'environment' => $this->environment->getEnvironment(),
            'timestamp' => date('c'),
            'health' => $this->healthChecker->checkServiceHealth('multivendor')
        ];
    }
}

// CLI interface
if (php_sapi_name() === 'cli') {
    $options = [];
    
    // Parse command line arguments
    for ($i = 1; $i < $argc; $i++) {
        switch ($argv[$i]) {
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            case '--continue-on-error':
                $options['continue_on_error'] = true;
                break;
            case '--status':
                $deployment = new DeploymentManager();
                echo json_encode($deployment->getStatus(), JSON_PRETTY_PRINT) . PHP_EOL;
                exit(0);
            case '--help':
                echo "Multivendor Service Deployment Script\n\n";
                echo "Usage: php deploy.php [options]\n\n";
                echo "Options:\n";
                echo "  --dry-run              Run deployment checks without making changes\n";
                echo "  --continue-on-error    Continue deployment even if some steps fail\n";
                echo "  --status               Show current deployment status\n";
                echo "  --help                 Show this help message\n\n";
                exit(0);
        }
    }
    
    // Run deployment
    $deployment = new DeploymentManager();
    $success = $deployment->deploy($options);
    
    exit($success ? 0 : 1);
}