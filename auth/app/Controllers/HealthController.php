<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\ServiceHealthChecker;
use Antinna\Auth\Services\Logger;
use Exception;

class HealthController
{
    private $healthChecker;
    private $logger;

    public function __construct()
    {
        $this->healthChecker = new ServiceHealthChecker();
        $this->logger = new Logger();
    }

    /**
     * Basic health check endpoint
     * Returns simple status for load balancers
     */
    public function basic(): void
    {
        try {
            $health = $this->healthChecker->getOverallHealth();
            
            $statusCode = $this->getHttpStatusCode($health['status']);
            
            http_response_code($statusCode);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            
            echo json_encode([
                'status' => $health['status'],
                'timestamp' => $health['timestamp'],
                'version' => $health['version']
            ], JSON_PRETTY_PRINT);

        } catch (Exception $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(503);
            header('Content-Type: application/json');
            
            echo json_encode([
                'status' => 'unhealthy',
                'timestamp' => date('c'),
                'error' => 'Health check system failure'
            ], JSON_PRETTY_PRINT);
        }
    }

    /**
     * Detailed health check endpoint
     * Returns comprehensive health information
     */
    public function detailed(): void
    {
        try {
            $health = $this->healthChecker->getDetailedHealth();
            
            $statusCode = $this->getHttpStatusCode($health['status']);
            
            http_response_code($statusCode);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            
            echo json_encode($health, JSON_PRETTY_PRINT);

        } catch (Exception $e) {
            $this->logger->error('Detailed health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(503);
            header('Content-Type: application/json');
            
            echo json_encode([
                'status' => 'unhealthy',
                'timestamp' => date('c'),
                'error' => 'Health check system failure',
                'services' => [],
                'system_metrics' => [],
                'performance_metrics' => []
            ], JSON_PRETTY_PRINT);
        }
    }

    /**
     * Readiness probe endpoint
     * Checks if service is ready to accept traffic
     */
    public function readiness(): void
    {
        try {
            $health = $this->healthChecker->getOverallHealth();
            
            // Service is ready if critical services are healthy
            $isReady = $health['status'] !== 'unhealthy';
            
            $statusCode = $isReady ? 200 : 503;
            
            http_response_code($statusCode);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            
            echo json_encode([
                'ready' => $isReady,
                'status' => $health['status'],
                'timestamp' => $health['timestamp'],
                'critical_issues' => $health['critical_issues'],
                'response_time_ms' => $health['response_time_ms']
            ], JSON_PRETTY_PRINT);

        } catch (Exception $e) {
            $this->logger->error('Readiness check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(503);
            header('Content-Type: application/json');
            
            echo json_encode([
                'ready' => false,
                'status' => 'unhealthy',
                'timestamp' => date('c'),
                'error' => 'Readiness check system failure'
            ], JSON_PRETTY_PRINT);
        }
    }

    /**
     * Liveness probe endpoint
     * Checks if service is alive and should not be restarted
     */
    public function liveness(): void
    {
        try {
            // Simple check to ensure the application is responsive
            $startTime = microtime(true);
            
            // Basic database connectivity test
            $dbHealthy = $this->quickDatabaseCheck();
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Service is alive if it can respond and database is accessible
            $isAlive = $dbHealthy && $responseTime < 5000; // 5 second timeout
            
            $statusCode = $isAlive ? 200 : 503;
            
            http_response_code($statusCode);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            
            echo json_encode([
                'alive' => $isAlive,
                'timestamp' => date('c'),
                'response_time_ms' => $responseTime,
                'database_accessible' => $dbHealthy
            ], JSON_PRETTY_PRINT);

        } catch (Exception $e) {
            $this->logger->error('Liveness check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(503);
            header('Content-Type: application/json');
            
            echo json_encode([
                'alive' => false,
                'timestamp' => date('c'),
                'error' => 'Liveness check system failure'
            ], JSON_PRETTY_PRINT);
        }
    }

    /**
     * Service metrics endpoint
     * Returns performance and operational metrics
     */
    public function metrics(): void
    {
        try {
            $metrics = $this->collectMetrics();
            
            http_response_code(200);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            
            echo json_encode($metrics, JSON_PRETTY_PRINT);

        } catch (Exception $e) {
            $this->logger->error('Metrics collection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(500);
            header('Content-Type: application/json');
            
            echo json_encode([
                'error' => 'Metrics collection failed',
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
    }

    /**
     * Service information endpoint
     * Returns service metadata and configuration
     */
    public function info(): void
    {
        try {
            $info = [
                'service' => [
                    'name' => 'Authentication Service',
                    'version' => getenv('SERVICE_VERSION') ?: '1.0.0',
                    'environment' => getenv('APP_ENV') ?: 'production',
                    'build_date' => getenv('BUILD_DATE') ?: 'unknown',
                    'commit_hash' => getenv('COMMIT_HASH') ?: 'unknown'
                ],
                'runtime' => [
                    'php_version' => PHP_VERSION,
                    'php_sapi' => PHP_SAPI,
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'timezone' => date_default_timezone_get()
                ],
                'features' => [
                    'jwt_authentication' => true,
                    'mfa_support' => true,
                    'biometric_auth' => getenv('BIOMETRIC_ENABLED') === 'true',
                    'passkey_auth' => getenv('PASSKEY_ENABLED') === 'true',
                    'magic_link_auth' => getenv('MAGIC_LINK_ENABLED') === 'true',
                    'social_auth' => getenv('SOCIAL_AUTH_ENABLED') === 'true',
                    'push_notifications' => getenv('PUSH_NOTIFICATIONS_ENABLED') === 'true',
                    'offline_auth' => getenv('OFFLINE_AUTH_ENABLED') === 'true',
                    'rate_limiting' => getenv('RATE_LIMIT_ENABLED') === 'true',
                    'audit_logging' => getenv('AUDIT_LOG_ENABLED') === 'true',
                    'security_monitoring' => getenv('SECURITY_MONITORING_ENABLED') === 'true'
                ],
                'endpoints' => [
                    'health' => '/health',
                    'health_detailed' => '/health/detailed',
                    'readiness' => '/health/ready',
                    'liveness' => '/health/live',
                    'metrics' => '/health/metrics',
                    'info' => '/health/info'
                ],
                'timestamp' => date('c')
            ];

            http_response_code(200);
            header('Content-Type: application/json');
            header('Cache-Control: public, max-age=300'); // Cache for 5 minutes
            
            echo json_encode($info, JSON_PRETTY_PRINT);

        } catch (Exception $e) {
            $this->logger->error('Service info collection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(500);
            header('Content-Type: application/json');
            
            echo json_encode([
                'error' => 'Service info collection failed',
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
    }

    private function getHttpStatusCode(string $status): int
    {
        switch ($status) {
            case 'healthy':
                return 200;
            case 'degraded':
                return 200; // Still operational
            case 'unhealthy':
                return 503;
            default:
                return 500;
        }
    }

    private function quickDatabaseCheck(): bool
    {
        try {
            $connection = \Antinna\Auth\Database\Connection::getInstance();
            $stmt = $connection->prepare("SELECT 1");
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function collectMetrics(): array
    {
        $startTime = microtime(true);
        
        $metrics = [
            'timestamp' => date('c'),
            'collection_time_ms' => 0, // Will be set at the end
            'authentication' => $this->getAuthenticationMetrics(),
            'performance' => $this->getPerformanceMetrics(),
            'security' => $this->getSecurityMetrics(),
            'system' => $this->getSystemMetrics(),
            'database' => $this->getDatabaseMetrics(),
            'errors' => $this->getErrorMetrics()
        ];

        $metrics['collection_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
        
        return $metrics;
    }

    private function getAuthenticationMetrics(): array
    {
        try {
            $connection = \Antinna\Auth\Database\Connection::getInstance();
            
            // Get authentication statistics for the last 24 hours
            $stmt = $connection->prepare("
                SELECT 
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_logins,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_logins,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM audit_logs 
                WHERE event_type = 'login_attempt' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $stats = $stmt->fetch();

            // Get MFA usage statistics
            $stmt = $connection->prepare("
                SELECT 
                    COUNT(*) as mfa_attempts,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as mfa_successful
                FROM audit_logs 
                WHERE event_type = 'mfa_verification' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $mfaStats = $stmt->fetch();

            // Get active sessions count
            $stmt = $connection->prepare("
                SELECT COUNT(*) as active_sessions 
                FROM sessions 
                WHERE expires_at > NOW()
            ");
            $stmt->execute();
            $sessionStats = $stmt->fetch();

            return [
                'total_login_attempts_24h' => (int) $stats['total_attempts'],
                'successful_logins_24h' => (int) $stats['successful_logins'],
                'failed_logins_24h' => (int) $stats['failed_logins'],
                'success_rate_24h' => $stats['total_attempts'] > 0 ? 
                    round(($stats['successful_logins'] / $stats['total_attempts']) * 100, 2) : 0,
                'unique_users_24h' => (int) $stats['unique_users'],
                'unique_ips_24h' => (int) $stats['unique_ips'],
                'mfa_attempts_24h' => (int) $mfaStats['mfa_attempts'],
                'mfa_success_rate_24h' => $mfaStats['mfa_attempts'] > 0 ? 
                    round(($mfaStats['mfa_successful'] / $mfaStats['mfa_attempts']) * 100, 2) : 0,
                'active_sessions' => (int) $sessionStats['active_sessions']
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to collect authentication metrics',
                'details' => $e->getMessage()
            ];
        }
    }

    private function getPerformanceMetrics(): array
    {
        return [
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_limit_mb' => round($this->parseMemoryLimit(ini_get('memory_limit')) / 1024 / 1024, 2),
            'execution_time_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false,
            'opcache_hit_rate' => $this->getOpcacheHitRate()
        ];
    }

    private function getSecurityMetrics(): array
    {
        try {
            $connection = \Antinna\Auth\Database\Connection::getInstance();
            
            // Get security events for the last 24 hours
            $stmt = $connection->prepare("
                SELECT 
                    COUNT(*) as total_security_events,
                    SUM(CASE WHEN event_type = 'suspicious_activity' THEN 1 ELSE 0 END) as suspicious_activities,
                    SUM(CASE WHEN event_type = 'rate_limit_exceeded' THEN 1 ELSE 0 END) as rate_limit_violations,
                    SUM(CASE WHEN event_type = 'account_locked' THEN 1 ELSE 0 END) as account_lockouts
                FROM audit_logs 
                WHERE event_type IN ('suspicious_activity', 'rate_limit_exceeded', 'account_locked')
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $securityStats = $stmt->fetch();

            return [
                'security_events_24h' => (int) $securityStats['total_security_events'],
                'suspicious_activities_24h' => (int) $securityStats['suspicious_activities'],
                'rate_limit_violations_24h' => (int) $securityStats['rate_limit_violations'],
                'account_lockouts_24h' => (int) $securityStats['account_lockouts']
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to collect security metrics',
                'details' => $e->getMessage()
            ];
        }
    }

    private function getSystemMetrics(): array
    {
        $metrics = [
            'php_version' => PHP_VERSION,
            'server_time' => date('c'),
            'timezone' => date_default_timezone_get(),
            'load_average' => null
        ];

        if (PHP_OS_FAMILY === 'Linux' && function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $metrics['load_average'] = [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2]
            ];
        }

        return $metrics;
    }

    private function getDatabaseMetrics(): array
    {
        try {
            $connection = \Antinna\Auth\Database\Connection::getInstance();
            
            // Get database status
            $stmt = $connection->prepare("SHOW STATUS LIKE 'Threads_connected'");
            $stmt->execute();
            $threadsConnected = $stmt->fetch();

            $stmt = $connection->prepare("SHOW STATUS LIKE 'Queries'");
            $stmt->execute();
            $queries = $stmt->fetch();

            $stmt = $connection->prepare("SHOW STATUS LIKE 'Uptime'");
            $stmt->execute();
            $uptime = $stmt->fetch();

            return [
                'connections' => (int) $threadsConnected['Value'],
                'total_queries' => (int) $queries['Value'],
                'uptime_seconds' => (int) $uptime['Value']
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to collect database metrics',
                'details' => $e->getMessage()
            ];
        }
    }

    private function getErrorMetrics(): array
    {
        try {
            $connection = \Antinna\Auth\Database\Connection::getInstance();
            
            // Get error statistics for the last 24 hours
            $stmt = $connection->prepare("
                SELECT 
                    COUNT(*) as total_errors,
                    SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as errors,
                    SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warnings,
                    SUM(CASE WHEN level = 'critical' THEN 1 ELSE 0 END) as critical_errors
                FROM error_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $errorStats = $stmt->fetch();

            return [
                'total_errors_24h' => (int) $errorStats['total_errors'],
                'errors_24h' => (int) $errorStats['errors'],
                'warnings_24h' => (int) $errorStats['warnings'],
                'critical_errors_24h' => (int) $errorStats['critical_errors']
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to collect error metrics',
                'details' => $e->getMessage()
            ];
        }
    }

    private function getOpcacheHitRate(): ?float
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = opcache_get_status();
        if (!$status || !isset($status['opcache_statistics'])) {
            return null;
        }

        $stats = $status['opcache_statistics'];
        $hits = $stats['hits'];
        $misses = $stats['misses'];
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : null;
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }
}