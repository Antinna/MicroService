<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Database\Connection;
use Exception;

class ServiceHealthChecker
{
    private $connection;
    private $healthChecks = [];
    private $criticalServices = ['database', 'jwt', 'session'];
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->initializeHealthChecks();
    }

    private function initializeHealthChecks(): void
    {
        $this->healthChecks = [
            'database' => [$this, 'checkDatabase'],
            'jwt' => [$this, 'checkJWTService'],
            'session' => [$this, 'checkSessionService'],
            'rate_limiter' => [$this, 'checkRateLimiter'],
            'email_service' => [$this, 'checkEmailService'],
            'sms_service' => [$this, 'checkSMSService'],
            'firebase' => [$this, 'checkFirebaseService'],
            'disk_space' => [$this, 'checkDiskSpace'],
            'memory_usage' => [$this, 'checkMemoryUsage'],
            'external_apis' => [$this, 'checkExternalAPIs']
        ];
    }

    public function getOverallHealth(): array
    {
        $startTime = microtime(true);
        $results = [];
        $overallStatus = 'healthy';
        $criticalIssues = [];

        foreach ($this->healthChecks as $service => $checker) {
            $serviceStartTime = microtime(true);
            
            try {
                $result = call_user_func($checker);
                $result['response_time'] = round((microtime(true) - $serviceStartTime) * 1000, 2);
                $results[$service] = $result;

                // Check if critical service is unhealthy
                if (in_array($service, $this->criticalServices) && $result['status'] !== 'healthy') {
                    $overallStatus = 'unhealthy';
                    $criticalIssues[] = $service;
                }

                // Degraded status if non-critical services are down
                if (!in_array($service, $this->criticalServices) && $result['status'] === 'unhealthy' && $overallStatus === 'healthy') {
                    $overallStatus = 'degraded';
                }

            } catch (Exception $e) {
                $results[$service] = [
                    'status' => 'unhealthy',
                    'message' => 'Health check failed: ' . $e->getMessage(),
                    'response_time' => round((microtime(true) - $serviceStartTime) * 1000, 2),
                    'error' => true
                ];

                if (in_array($service, $this->criticalServices)) {
                    $overallStatus = 'unhealthy';
                    $criticalIssues[] = $service;
                }
            }
        }

        $totalResponseTime = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'response_time_ms' => $totalResponseTime,
            'services' => $results,
            'critical_issues' => $criticalIssues,
            'version' => $this->getServiceVersion(),
            'uptime' => $this->getUptime()
        ];
    }

    public function getDetailedHealth(): array
    {
        $health = $this->getOverallHealth();
        
        // Add additional system metrics
        $health['system_metrics'] = [
            'cpu_usage' => $this->getCPUUsage(),
            'memory_usage' => $this->getMemoryMetrics(),
            'disk_usage' => $this->getDiskMetrics(),
            'network_stats' => $this->getNetworkStats(),
            'process_info' => $this->getProcessInfo()
        ];

        // Add performance metrics
        $health['performance_metrics'] = [
            'active_connections' => $this->getActiveConnections(),
            'request_rate' => $this->getRequestRate(),
            'error_rate' => $this->getErrorRate(),
            'average_response_time' => $this->getAverageResponseTime()
        ];

        return $health;
    }

    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test basic connectivity
            $stmt = $this->connection->prepare("SELECT 1 as test");
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result['test'] !== 1) {
                throw new Exception('Database query returned unexpected result');
            }

            // Test write capability
            $stmt = $this->connection->prepare("
                INSERT INTO service_health_checks (service_name, check_time, status) 
                VALUES (?, NOW(), 'healthy')
                ON DUPLICATE KEY UPDATE check_time = NOW(), status = 'healthy'
            ");
            $stmt->execute(['database']);

            // Check connection pool
            $activeConnections = $this->getActiveConnections();
            
            $queryTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'message' => 'Database is accessible and responsive',
                'details' => [
                    'query_time_ms' => $queryTime,
                    'active_connections' => $activeConnections,
                    'connection_pool_status' => $activeConnections < 50 ? 'normal' : 'high'
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e),
                    'error_code' => $e->getCode()
                ]
            ];
        }
    }

    private function checkJWTService(): array
    {
        try {
            $jwtManager = new JWTManager();
            
            // Test token generation
            $testPayload = [
                'user_id' => 999999,
                'email' => 'health-check@example.com',
                'exp' => time() + 300
            ];
            
            $token = $jwtManager->generateToken($testPayload);
            
            if (empty($token)) {
                throw new Exception('Token generation failed');
            }

            // Test token validation
            $decoded = $jwtManager->validateToken($token);
            
            if ($decoded['user_id'] !== 999999) {
                throw new Exception('Token validation failed');
            }

            return [
                'status' => 'healthy',
                'message' => 'JWT service is functioning correctly',
                'details' => [
                    'token_generation' => 'success',
                    'token_validation' => 'success',
                    'algorithm' => 'HS256'
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'JWT service failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkSessionService(): array
    {
        try {
            $sessionManager = new SessionManager($this->connection);
            
            // Test session creation
            $testSessionId = 'health-check-' . uniqid();
            $sessionData = [
                'user_id' => 999999,
                'device_id' => 'health-check-device',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Health Check Agent'
            ];
            
            $created = $sessionManager->createSession($testSessionId, $sessionData);
            
            if (!$created) {
                throw new Exception('Session creation failed');
            }

            // Test session retrieval
            $retrieved = $sessionManager->getSession($testSessionId);
            
            if (!$retrieved || $retrieved['user_id'] !== 999999) {
                throw new Exception('Session retrieval failed');
            }

            // Clean up test session
            $sessionManager->destroySession($testSessionId);

            return [
                'status' => 'healthy',
                'message' => 'Session service is functioning correctly',
                'details' => [
                    'session_creation' => 'success',
                    'session_retrieval' => 'success',
                    'session_cleanup' => 'success'
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Session service failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkRateLimiter(): array
    {
        try {
            $rateLimiter = new RateLimiter($this->connection);
            
            $testKey = 'health-check-' . uniqid();
            
            // Test rate limiting functionality
            $allowed = $rateLimiter->isAllowed($testKey, 10, 60);
            
            if (!$allowed) {
                throw new Exception('Rate limiter unexpectedly blocked request');
            }

            // Test rate limit tracking
            for ($i = 0; $i < 5; $i++) {
                $rateLimiter->isAllowed($testKey, 10, 60);
            }

            $remaining = $rateLimiter->getRemainingAttempts($testKey, 10, 60);
            
            if ($remaining !== 5) {
                throw new Exception('Rate limiter counting is incorrect');
            }

            return [
                'status' => 'healthy',
                'message' => 'Rate limiter is functioning correctly',
                'details' => [
                    'rate_limiting' => 'success',
                    'attempt_tracking' => 'success',
                    'remaining_calculation' => 'success'
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Rate limiter failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkEmailService(): array
    {
        if (!getenv('EMAIL_SERVICE_ENABLED') || getenv('EMAIL_SERVICE_ENABLED') !== 'true') {
            return [
                'status' => 'disabled',
                'message' => 'Email service is disabled',
                'details' => ['enabled' => false]
            ];
        }

        try {
            $emailService = new EmailService();
            
            // Test email service connectivity (without actually sending)
            $testResult = $emailService->testConnection();
            
            if (!$testResult) {
                throw new Exception('Email service connection test failed');
            }

            return [
                'status' => 'healthy',
                'message' => 'Email service is accessible',
                'details' => [
                    'connection_test' => 'success',
                    'provider' => $emailService->getProvider()
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Email service failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkSMSService(): array
    {
        if (!getenv('SMS_SERVICE_ENABLED') || getenv('SMS_SERVICE_ENABLED') !== 'true') {
            return [
                'status' => 'disabled',
                'message' => 'SMS service is disabled',
                'details' => ['enabled' => false]
            ];
        }

        try {
            $smsHandler = new SMSMFAHandler();
            
            // Test SMS service connectivity (without actually sending)
            $testResult = $smsHandler->testConnection();
            
            if (!$testResult) {
                throw new Exception('SMS service connection test failed');
            }

            return [
                'status' => 'healthy',
                'message' => 'SMS service is accessible',
                'details' => [
                    'connection_test' => 'success',
                    'provider' => $smsHandler->getProvider()
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'SMS service failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkFirebaseService(): array
    {
        if (!getenv('FIREBASE_ENABLED') || getenv('FIREBASE_ENABLED') !== 'true') {
            return [
                'status' => 'disabled',
                'message' => 'Firebase service is disabled',
                'details' => ['enabled' => false]
            ];
        }

        try {
            $pushService = new PushNotificationService();
            
            // Test Firebase connectivity
            $testResult = $pushService->testConnection();
            
            if (!$testResult) {
                throw new Exception('Firebase connection test failed');
            }

            return [
                'status' => 'healthy',
                'message' => 'Firebase service is accessible',
                'details' => [
                    'connection_test' => 'success',
                    'project_id' => $pushService->getProjectId()
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Firebase service failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkDiskSpace(): array
    {
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            $diskUsed = $diskTotal - $diskFree;
            $diskUsagePercent = round(($diskUsed / $diskTotal) * 100, 2);

            $status = 'healthy';
            $message = 'Disk space is adequate';

            if ($diskUsagePercent > 90) {
                $status = 'unhealthy';
                $message = 'Disk space critically low';
            } elseif ($diskUsagePercent > 80) {
                $status = 'degraded';
                $message = 'Disk space is getting low';
            }

            return [
                'status' => $status,
                'message' => $message,
                'details' => [
                    'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($diskUsed / 1024 / 1024 / 1024, 2),
                    'usage_percent' => $diskUsagePercent
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Disk space check failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkMemoryUsage(): array
    {
        try {
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            
            $memoryUsagePercent = $memoryLimit > 0 ? round(($memoryUsage / $memoryLimit) * 100, 2) : 0;
            $memoryPeakPercent = $memoryLimit > 0 ? round(($memoryPeak / $memoryLimit) * 100, 2) : 0;

            $status = 'healthy';
            $message = 'Memory usage is normal';

            if ($memoryUsagePercent > 90) {
                $status = 'unhealthy';
                $message = 'Memory usage critically high';
            } elseif ($memoryUsagePercent > 80) {
                $status = 'degraded';
                $message = 'Memory usage is high';
            }

            return [
                'status' => $status,
                'message' => $message,
                'details' => [
                    'current_mb' => round($memoryUsage / 1024 / 1024, 2),
                    'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
                    'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
                    'usage_percent' => $memoryUsagePercent,
                    'peak_percent' => $memoryPeakPercent
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Memory usage check failed: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e)
                ]
            ];
        }
    }

    private function checkExternalAPIs(): array
    {
        $externalServices = [
            'google_oauth' => 'https://accounts.google.com/.well-known/openid_configuration',
            'facebook_oauth' => 'https://graph.facebook.com/v18.0/me',
            'github_oauth' => 'https://api.github.com/user'
        ];

        $results = [];
        $overallStatus = 'healthy';

        foreach ($externalServices as $service => $url) {
            try {
                $startTime = microtime(true);
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 400) {
                    $results[$service] = [
                        'status' => 'healthy',
                        'response_time_ms' => $responseTime,
                        'http_code' => $httpCode
                    ];
                } else {
                    $results[$service] = [
                        'status' => 'unhealthy',
                        'response_time_ms' => $responseTime,
                        'http_code' => $httpCode
                    ];
                    $overallStatus = 'degraded'; // External APIs are not critical
                }

            } catch (Exception $e) {
                $results[$service] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage()
                ];
                $overallStatus = 'degraded';
            }
        }

        return [
            'status' => $overallStatus,
            'message' => $overallStatus === 'healthy' ? 'All external APIs are accessible' : 'Some external APIs are unavailable',
            'details' => $results
        ];
    }

    private function getServiceVersion(): string
    {
        // This would typically read from a version file or environment variable
        return getenv('SERVICE_VERSION') ?: '1.0.0';
    }

    private function getUptime(): array
    {
        $uptimeSeconds = $this->getSystemUptime();
        
        return [
            'seconds' => $uptimeSeconds,
            'human_readable' => $this->formatUptime($uptimeSeconds)
        ];
    }

    private function getSystemUptime(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime = file_get_contents('/proc/uptime');
            return (int) floatval(explode(' ', $uptime)[0]);
        }
        
        // Fallback for other systems
        return time() - filemtime(__FILE__);
    }

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return "{$days}d {$hours}h {$minutes}m {$secs}s";
    }

    private function getCPUUsage(): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $load = sys_getloadavg();
            return [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2]
            ];
        }
        
        return ['unavailable' => true];
    }

    private function getMemoryMetrics(): array
    {
        return [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'limit' => $this->parseMemoryLimit(ini_get('memory_limit'))
        ];
    }

    private function getDiskMetrics(): array
    {
        return [
            'free_space' => disk_free_space('/'),
            'total_space' => disk_total_space('/')
        ];
    }

    private function getNetworkStats(): array
    {
        // This would typically integrate with system monitoring tools
        return [
            'connections' => $this->getActiveConnections(),
            'bandwidth_usage' => 'unavailable'
        ];
    }

    private function getProcessInfo(): array
    {
        return [
            'pid' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ];
    }

    private function getActiveConnections(): int
    {
        try {
            $stmt = $this->connection->prepare("SHOW STATUS LIKE 'Threads_connected'");
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) $result['Value'];
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getRequestRate(): float
    {
        // This would typically be calculated from metrics storage
        return 0.0;
    }

    private function getErrorRate(): float
    {
        // This would typically be calculated from error logs
        return 0.0;
    }

    private function getAverageResponseTime(): float
    {
        // This would typically be calculated from performance metrics
        return 0.0;
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