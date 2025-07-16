<?php

namespace Antinna\Multivendor\Services;

use Antinna\Multivendor\Services\Logger;

class ServiceHealthChecker
{
    private Logger $logger;
    private array $services;
    private array $healthCache;
    private int $cacheTimeout;

    public function __construct(Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->healthCache = [];
        $this->cacheTimeout = 30; // 30 seconds cache
        
        $this->services = [
            'auth' => [
                'name' => 'Authentication Service',
                'url' => getenv('AUTH_SERVICE_URL') ?: 'http://localhost:8001',
                'health_endpoint' => '/health',
                'timeout' => 10,
                'critical' => true
            ],
            'pay' => [
                'name' => 'Payment Service',
                'url' => getenv('PAY_SERVICE_URL') ?: 'http://localhost:8002',
                'health_endpoint' => '/health',
                'timeout' => 10,
                'critical' => true
            ],
            'social' => [
                'name' => 'Social Service',
                'url' => getenv('SOCIAL_SERVICE_URL') ?: 'http://localhost:8003',
                'health_endpoint' => '/health',
                'timeout' => 10,
                'critical' => false
            ],
            'delivery' => [
                'name' => 'Delivery Service',
                'url' => getenv('DELIVERY_SERVICE_URL') ?: 'http://localhost:8004',
                'health_endpoint' => '/health',
                'timeout' => 10,
                'critical' => true
            ],
            'multivendor' => [
                'name' => 'Multivendor Service',
                'url' => 'local',
                'health_endpoint' => '/health',
                'timeout' => 5,
                'critical' => true
            ]
        ];
    }

    /**
     * Check health of a specific service
     */
    public function checkServiceHealth(string $serviceKey, bool $useCache = true): array
    {
        // Check cache first
        if ($useCache && $this->isCacheValid($serviceKey)) {
            return $this->healthCache[$serviceKey]['data'];
        }

        if (!isset($this->services[$serviceKey])) {
            return [
                'service' => $serviceKey,
                'status' => 'unknown',
                'error' => 'Service not configured',
                'timestamp' => time()
            ];
        }

        $serviceConfig = $this->services[$serviceKey];
        $startTime = microtime(true);

        try {
            if ($serviceKey === 'multivendor') {
                $result = $this->checkLocalHealth();
            } else {
                $result = $this->checkRemoteHealth($serviceKey, $serviceConfig);
            }

            $result['response_time'] = round((microtime(true) - $startTime) * 1000, 2); // ms
            $result['timestamp'] = time();
            $result['critical'] = $serviceConfig['critical'];

            // Cache the result
            $this->cacheHealthResult($serviceKey, $result);

            return $result;

        } catch (\Exception $e) {
            $result = [
                'service' => $serviceKey,
                'name' => $serviceConfig['name'],
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => round((microtime(true) - $startTime) * 1000, 2),
                'timestamp' => time(),
                'critical' => $serviceConfig['critical'],
                'url' => $serviceConfig['url']
            ];

            $this->logger->warning('Service health check failed', [
                'service' => $serviceKey,
                'error' => $e->getMessage(),
                'response_time' => $result['response_time']
            ]);

            // Cache the result
            $this->cacheHealthResult($serviceKey, $result);

            return $result;
        }
    }

    /**
     * Check local service health
     */
    private function checkLocalHealth(): array
    {
        // Check basic PHP functionality and database connection
        $checks = [
            'php' => $this->checkPhpHealth(),
            'database' => $this->checkDatabaseHealth(),
            'filesystem' => $this->checkFilesystemHealth(),
            'memory' => $this->checkMemoryHealth()
        ];

        $allHealthy = array_reduce($checks, function($carry, $check) {
            return $carry && $check['status'] === 'healthy';
        }, true);

        return [
            'service' => 'multivendor',
            'name' => 'Multivendor Service',
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'url' => 'local',
            'checks' => $checks,
            'version' => '1.0.0',
            'uptime' => $this->getUptime()
        ];
    }

    /**
     * Check remote service health
     */
    private function checkRemoteHealth(string $serviceKey, array $serviceConfig): array
    {
        $url = $serviceConfig['url'] . $serviceConfig['health_endpoint'];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $serviceConfig['timeout'],
                'header' => [
                    'User-Agent: Multivendor-HealthChecker/1.0',
                    'Accept: application/json'
                ]
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new \Exception('Connection failed or timeout');
        }

        // Parse response headers to get HTTP status
        $httpStatus = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $httpStatus = (int)$matches[1];
                    break;
                }
            }
        }

        if ($httpStatus >= 400) {
            throw new \Exception("HTTP {$httpStatus} response");
        }

        $healthData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response');
        }

        return [
            'service' => $serviceKey,
            'name' => $serviceConfig['name'],
            'status' => $this->determineStatusFromResponse($healthData),
            'url' => $serviceConfig['url'],
            'http_status' => $httpStatus,
            'response' => $healthData
        ];
    }

    /**
     * Determine service status from health response
     */
    private function determineStatusFromResponse(array $response): string
    {
        // Common health check response patterns
        if (isset($response['status'])) {
            $status = strtolower($response['status']);
            if (in_array($status, ['healthy', 'ok', 'up', 'running'])) {
                return 'healthy';
            }
            if (in_array($status, ['unhealthy', 'down', 'error', 'failed'])) {
                return 'unhealthy';
            }
        }

        // If no explicit status, assume healthy if we got a valid response
        return 'healthy';
    }

    /**
     * Check PHP health
     */
    private function checkPhpHealth(): array
    {
        return [
            'name' => 'PHP Runtime',
            'status' => 'healthy',
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
    }

    /**
     * Check database health
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $db = new \Antinna\Multivendor\Database\Connection();
            $pdo = $db->getConnection();
            
            // Simple query to test connection
            $stmt = $pdo->query('SELECT 1');
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'name' => 'Database Connection',
                    'status' => 'healthy',
                    'driver' => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
                    'server_version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)
                ];
            } else {
                return [
                    'name' => 'Database Connection',
                    'status' => 'unhealthy',
                    'error' => 'Query failed'
                ];
            }

        } catch (\Exception $e) {
            return [
                'name' => 'Database Connection',
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check filesystem health
     */
    private function checkFilesystemHealth(): array
    {
        $logPath = __DIR__ . '/../../logs';
        
        try {
            // Check if log directory is writable
            if (!is_dir($logPath)) {
                mkdir($logPath, 0755, true);
            }
            
            $testFile = $logPath . '/health_check_' . time() . '.tmp';
            $written = file_put_contents($testFile, 'health check');
            
            if ($written === false) {
                throw new \Exception('Cannot write to log directory');
            }
            
            unlink($testFile);
            
            return [
                'name' => 'Filesystem',
                'status' => 'healthy',
                'log_path' => $logPath,
                'writable' => true,
                'free_space' => $this->formatBytes(disk_free_space($logPath))
            ];

        } catch (\Exception $e) {
            return [
                'name' => 'Filesystem',
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'log_path' => $logPath
            ];
        }
    }

    /**
     * Check memory health
     */
    private function checkMemoryHealth(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryPercent = $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0;
        
        $status = 'healthy';
        if ($memoryPercent > 90) {
            $status = 'critical';
        } elseif ($memoryPercent > 75) {
            $status = 'warning';
        }

        return [
            'name' => 'Memory Usage',
            'status' => $status,
            'usage' => $this->formatBytes($memoryUsage),
            'limit' => $this->formatBytes($memoryLimit),
            'percentage' => round($memoryPercent, 2),
            'peak_usage' => $this->formatBytes(memory_get_peak_usage(true))
        ];
    }

    /**
     * Get system uptime (simplified)
     */
    private function getUptime(): string
    {
        // This is a simplified uptime - in production you might want to track actual service start time
        return 'N/A (local service)';
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0; // No limit
        }
        
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if cached result is still valid
     */
    private function isCacheValid(string $serviceKey): bool
    {
        if (!isset($this->healthCache[$serviceKey])) {
            return false;
        }
        
        $cacheAge = time() - $this->healthCache[$serviceKey]['timestamp'];
        return $cacheAge < $this->cacheTimeout;
    }

    /**
     * Cache health result
     */
    private function cacheHealthResult(string $serviceKey, array $result): void
    {
        $this->healthCache[$serviceKey] = [
            'data' => $result,
            'timestamp' => time()
        ];
    }

    /**
     * Check health of all services
     */
    public function checkAllServicesHealth(bool $useCache = true): array
    {
        $results = [];
        $summary = [
            'total_services' => count($this->services),
            'healthy_services' => 0,
            'unhealthy_services' => 0,
            'critical_services_down' => 0,
            'overall_status' => 'healthy',
            'last_check' => time()
        ];

        foreach ($this->services as $serviceKey => $serviceConfig) {
            $health = $this->checkServiceHealth($serviceKey, $useCache);
            $results[$serviceKey] = $health;

            if ($health['status'] === 'healthy') {
                $summary['healthy_services']++;
            } else {
                $summary['unhealthy_services']++;
                if ($serviceConfig['critical']) {
                    $summary['critical_services_down']++;
                }
            }
        }

        // Determine overall status
        if ($summary['critical_services_down'] > 0) {
            $summary['overall_status'] = 'critical';
        } elseif ($summary['unhealthy_services'] > 0) {
            $summary['overall_status'] = 'degraded';
        }

        return [
            'services' => $results,
            'summary' => $summary
        ];
    }

    /**
     * Get service configuration
     */
    public function getServiceConfiguration(): array
    {
        return $this->services;
    }

    /**
     * Clear health cache
     */
    public function clearCache(): void
    {
        $this->healthCache = [];
        $this->logger->info('Health check cache cleared');
    }

    /**
     * Set cache timeout
     */
    public function setCacheTimeout(int $seconds): void
    {
        $this->cacheTimeout = $seconds;
    }

    /**
     * Get detailed system information
     */
    public function getSystemInfo(): array
    {
        return [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS,
                'architecture' => php_uname('m')
            ],
            'server' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'Unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown'
            ],
            'memory' => [
                'current_usage' => $this->formatBytes(memory_get_usage(true)),
                'peak_usage' => $this->formatBytes(memory_get_peak_usage(true)),
                'limit' => ini_get('memory_limit')
            ],
            'extensions' => [
                'pdo' => extension_loaded('pdo'),
                'json' => extension_loaded('json'),
                'curl' => extension_loaded('curl'),
                'openssl' => extension_loaded('openssl')
            ]
        ];
    }
}