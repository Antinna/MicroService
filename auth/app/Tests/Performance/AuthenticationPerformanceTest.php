<?php

namespace Antinna\Auth\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\UserAuthenticator;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\SessionManager;
use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Repositories\UserRepository;

class AuthenticationPerformanceTest extends TestCase
{
    private $connection;
    private $userAuthenticator;
    private $jwtManager;
    private $sessionManager;
    private $rateLimiter;
    private $userRepository;
    private $baseUrl;
    private $testUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->connection = Connection::getInstance();
        $this->connection->beginTransaction();
        
        $this->userRepository = new UserRepository($this->connection);
        $this->jwtManager = new JWTManager();
        $this->sessionManager = new SessionManager($this->connection);
        $this->rateLimiter = new RateLimiter($this->connection);
        $this->userAuthenticator = new UserAuthenticator(
            $this->userRepository,
            $this->jwtManager,
            $this->sessionManager
        );
        
        $this->baseUrl = 'http://localhost:8080/auth';
        
        $this->createTestUsers();
    }

    protected function tearDown(): void
    {
        $this->connection->rollback();
        parent::tearDown();
    }

    private function createTestUsers(int $count = 100): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $userData = [
                'email' => "perftest{$i}@example.com",
                'password' => password_hash('password123', PASSWORD_DEFAULT),
                'name' => "Performance Test User {$i}",
                'phone_number' => "+123456789{$i}",
                'is_active' => 1,
                'email_verified' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $userId = $this->userRepository->create($userData);
            $this->testUsers[] = [
                'id' => $userId,
                'email' => $userData['email'],
                'password' => 'password123'
            ];
        }
    }

    public function testLoginPerformance()
    {
        $iterations = 100;
        $startTime = microtime(true);
        $successfulLogins = 0;
        $failedLogins = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $user = $this->testUsers[$i % count($this->testUsers)];
            
            $loginData = [
                'email' => $user['email'],
                'password' => $user['password'],
                'device_info' => [
                    'device_id' => "perf-test-device-{$i}",
                    'device_name' => 'Performance Test Device',
                    'app_version' => '1.0.0'
                ]
            ];

            $response = $this->makeApiRequest('POST', '/api/auth/login', $loginData);
            
            if ($response['status'] === 200) {
                $successfulLogins++;
            } else {
                $failedLogins++;
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $averageTime = $totalTime / $iterations;
        $requestsPerSecond = $iterations / $totalTime;

        // Performance assertions
        $this->assertLessThan(0.5, $averageTime, 'Average login time should be less than 500ms');
        $this->assertGreaterThan(10, $requestsPerSecond, 'Should handle at least 10 login requests per second');
        $this->assertEquals($iterations, $successfulLogins, 'All login attempts should succeed');
        $this->assertEquals(0, $failedLogins, 'No login attempts should fail');

        echo "\nLogin Performance Results:\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average time per request: " . round($averageTime * 1000, 2) . " ms\n";
        echo "Requests per second: " . round($requestsPerSecond, 2) . "\n";
        echo "Successful logins: {$successfulLogins}\n";
        echo "Failed logins: {$failedLogins}\n";
    }

    public function testTokenValidationPerformance()
    {
        // First, create some valid tokens
        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $user = $this->testUsers[$i];
            $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
                'email' => $user['email'],
                'password' => $user['password'],
                'device_info' => [
                    'device_id' => "token-test-device-{$i}",
                    'device_name' => 'Token Test Device',
                    'app_version' => '1.0.0'
                ]
            ]);
            
            if ($loginResponse['status'] === 200) {
                $tokens[] = $loginResponse['body']['data']['access_token'];
            }
        }

        $iterations = 1000;
        $startTime = microtime(true);
        $successfulValidations = 0;
        $failedValidations = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $token = $tokens[$i % count($tokens)];
            
            $response = $this->makeApiRequest('GET', '/api/users/profile', [], [
                'Authorization: Bearer ' . $token
            ]);
            
            if ($response['status'] === 200) {
                $successfulValidations++;
            } else {
                $failedValidations++;
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $averageTime = $totalTime / $iterations;
        $requestsPerSecond = $iterations / $totalTime;

        // Performance assertions
        $this->assertLessThan(0.1, $averageTime, 'Average token validation time should be less than 100ms');
        $this->assertGreaterThan(50, $requestsPerSecond, 'Should handle at least 50 token validations per second');
        $this->assertEquals($iterations, $successfulValidations, 'All token validations should succeed');

        echo "\nToken Validation Performance Results:\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average time per request: " . round($averageTime * 1000, 2) . " ms\n";
        echo "Requests per second: " . round($requestsPerSecond, 2) . "\n";
        echo "Successful validations: {$successfulValidations}\n";
        echo "Failed validations: {$failedValidations}\n";
    }

    public function testConcurrentLoginPerformance()
    {
        $concurrentUsers = 20;
        $requestsPerUser = 5;
        $totalRequests = $concurrentUsers * $requestsPerUser;

        $startTime = microtime(true);
        
        // Create concurrent processes
        $processes = [];
        for ($i = 0; $i < $concurrentUsers; $i++) {
            $user = $this->testUsers[$i % count($this->testUsers)];
            
            for ($j = 0; $j < $requestsPerUser; $j++) {
                $loginData = [
                    'email' => $user['email'],
                    'password' => $user['password'],
                    'device_info' => [
                        'device_id' => "concurrent-device-{$i}-{$j}",
                        'device_name' => 'Concurrent Test Device',
                        'app_version' => '1.0.0'
                    ]
                ];
                
                $processes[] = $this->makeAsyncApiRequest('POST', '/api/auth/login', $loginData);
            }
        }

        // Wait for all processes to complete and collect results
        $responses = [];
        foreach ($processes as $process) {
            $responses[] = $this->getAsyncResponse($process);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $averageTime = $totalTime / $totalRequests;
        $requestsPerSecond = $totalRequests / $totalTime;

        // Analyze results
        $successfulLogins = 0;
        $failedLogins = 0;
        foreach ($responses as $response) {
            if ($response['status'] === 200) {
                $successfulLogins++;
            } else {
                $failedLogins++;
            }
        }

        // Performance assertions
        $this->assertLessThan(2.0, $totalTime, 'Concurrent logins should complete within 2 seconds');
        $this->assertGreaterThan(5, $requestsPerSecond, 'Should handle at least 5 concurrent requests per second');
        $this->assertGreaterThanOrEqual($totalRequests * 0.9, $successfulLogins, 'At least 90% of concurrent logins should succeed');

        echo "\nConcurrent Login Performance Results:\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average time per request: " . round($averageTime * 1000, 2) . " ms\n";
        echo "Requests per second: " . round($requestsPerSecond, 2) . "\n";
        echo "Successful logins: {$successfulLogins}/{$totalRequests}\n";
        echo "Failed logins: {$failedLogins}/{$totalRequests}\n";
    }

    public function testDatabaseConnectionPoolPerformance()
    {
        $iterations = 200;
        $startTime = microtime(true);
        
        $connectionTimes = [];
        $queryTimes = [];

        for ($i = 0; $i < $iterations; $i++) {
            $connStart = microtime(true);
            $connection = Connection::getInstance();
            $connEnd = microtime(true);
            $connectionTimes[] = $connEnd - $connStart;

            $queryStart = microtime(true);
            $stmt = $connection->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1");
            $stmt->execute();
            $result = $stmt->fetch();
            $queryEnd = microtime(true);
            $queryTimes[] = $queryEnd - $queryStart;
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        $avgConnectionTime = array_sum($connectionTimes) / count($connectionTimes);
        $avgQueryTime = array_sum($queryTimes) / count($queryTimes);
        $maxConnectionTime = max($connectionTimes);
        $maxQueryTime = max($queryTimes);

        // Performance assertions
        $this->assertLessThan(0.01, $avgConnectionTime, 'Average connection time should be less than 10ms');
        $this->assertLessThan(0.05, $avgQueryTime, 'Average query time should be less than 50ms');
        $this->assertLessThan(0.1, $maxConnectionTime, 'Max connection time should be less than 100ms');
        $this->assertLessThan(0.2, $maxQueryTime, 'Max query time should be less than 200ms');

        echo "\nDatabase Performance Results:\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average connection time: " . round($avgConnectionTime * 1000, 2) . " ms\n";
        echo "Average query time: " . round($avgQueryTime * 1000, 2) . " ms\n";
        echo "Max connection time: " . round($maxConnectionTime * 1000, 2) . " ms\n";
        echo "Max query time: " . round($maxQueryTime * 1000, 2) . " ms\n";
    }

    public function testMemoryUsagePerformance()
    {
        $initialMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        // Perform memory-intensive operations
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $user = $this->testUsers[$i % count($this->testUsers)];
            $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
                'email' => $user['email'],
                'password' => $user['password'],
                'device_info' => [
                    'device_id' => "memory-test-device-{$i}",
                    'device_name' => 'Memory Test Device',
                    'app_version' => '1.0.0'
                ]
            ]);
            
            if ($loginResponse['status'] === 200) {
                $tokens[] = $loginResponse['body']['data']['access_token'];
            }
        }

        // Validate all tokens
        foreach ($tokens as $token) {
            $this->makeApiRequest('GET', '/api/users/profile', [], [
                'Authorization: Bearer ' . $token
            ]);
        }

        $finalMemory = memory_get_usage(true);
        $finalPeakMemory = memory_get_peak_usage(true);
        
        $memoryIncrease = $finalMemory - $initialMemory;
        $peakMemoryIncrease = $finalPeakMemory - $peakMemory;

        // Memory usage assertions (in MB)
        $memoryIncreaseMB = $memoryIncrease / 1024 / 1024;
        $peakMemoryIncreaseMB = $peakMemoryIncrease / 1024 / 1024;

        $this->assertLessThan(50, $memoryIncreaseMB, 'Memory increase should be less than 50MB');
        $this->assertLessThan(100, $peakMemoryIncreaseMB, 'Peak memory increase should be less than 100MB');

        echo "\nMemory Usage Results:\n";
        echo "Initial memory: " . round($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "Final memory: " . round($finalMemory / 1024 / 1024, 2) . " MB\n";
        echo "Memory increase: " . round($memoryIncreaseMB, 2) . " MB\n";
        echo "Peak memory increase: " . round($peakMemoryIncreaseMB, 2) . " MB\n";
    }

    public function testRateLimitingPerformance()
    {
        $iterations = 50;
        $startTime = microtime(true);
        
        $allowedRequests = 0;
        $blockedRequests = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $user = $this->testUsers[0]; // Use same user to trigger rate limiting
            
            $loginData = [
                'email' => $user['email'],
                'password' => 'wrongpassword', // Intentionally wrong to trigger rate limiting
                'device_info' => [
                    'device_id' => "rate-limit-device-{$i}",
                    'device_name' => 'Rate Limit Test Device',
                    'app_version' => '1.0.0'
                ]
            ];

            $response = $this->makeApiRequest('POST', '/api/auth/login', $loginData);
            
            if ($response['status'] === 429) {
                $blockedRequests++;
            } else {
                $allowedRequests++;
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $averageTime = $totalTime / $iterations;

        // Rate limiting should kick in after a certain number of attempts
        $this->assertGreaterThan(0, $blockedRequests, 'Some requests should be rate limited');
        $this->assertLessThan(0.1, $averageTime, 'Rate limiting check should be fast (< 100ms)');

        echo "\nRate Limiting Performance Results:\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average time per request: " . round($averageTime * 1000, 2) . " ms\n";
        echo "Allowed requests: {$allowedRequests}\n";
        echo "Blocked requests: {$blockedRequests}\n";
    }

    public function testLoadTestScenario()
    {
        $duration = 10; // seconds
        $concurrentUsers = 10;
        $endTime = time() + $duration;
        
        $totalRequests = 0;
        $successfulRequests = 0;
        $failedRequests = 0;
        $responseTimes = [];

        echo "\nRunning load test for {$duration} seconds with {$concurrentUsers} concurrent users...\n";

        while (time() < $endTime) {
            $processes = [];
            
            // Create concurrent requests
            for ($i = 0; $i < $concurrentUsers; $i++) {
                $user = $this->testUsers[$i % count($this->testUsers)];
                
                $loginData = [
                    'email' => $user['email'],
                    'password' => $user['password'],
                    'device_info' => [
                        'device_id' => "load-test-device-{$i}-" . time(),
                        'device_name' => 'Load Test Device',
                        'app_version' => '1.0.0'
                    ]
                ];
                
                $requestStart = microtime(true);
                $processes[] = [
                    'process' => $this->makeAsyncApiRequest('POST', '/api/auth/login', $loginData),
                    'start_time' => $requestStart
                ];
            }

            // Collect responses
            foreach ($processes as $processData) {
                $response = $this->getAsyncResponse($processData['process']);
                $responseTime = microtime(true) - $processData['start_time'];
                
                $totalRequests++;
                $responseTimes[] = $responseTime;
                
                if ($response['status'] === 200) {
                    $successfulRequests++;
                } else {
                    $failedRequests++;
                }
            }
        }

        $averageResponseTime = array_sum($responseTimes) / count($responseTimes);
        $maxResponseTime = max($responseTimes);
        $minResponseTime = min($responseTimes);
        $requestsPerSecond = $totalRequests / $duration;
        $successRate = ($successfulRequests / $totalRequests) * 100;

        // Load test assertions
        $this->assertGreaterThan(50, $requestsPerSecond, 'Should handle at least 50 requests per second under load');
        $this->assertGreaterThan(95, $successRate, 'Success rate should be at least 95%');
        $this->assertLessThan(1.0, $averageResponseTime, 'Average response time should be less than 1 second');

        echo "\nLoad Test Results:\n";
        echo "Duration: {$duration} seconds\n";
        echo "Total requests: {$totalRequests}\n";
        echo "Successful requests: {$successfulRequests}\n";
        echo "Failed requests: {$failedRequests}\n";
        echo "Success rate: " . round($successRate, 2) . "%\n";
        echo "Requests per second: " . round($requestsPerSecond, 2) . "\n";
        echo "Average response time: " . round($averageResponseTime * 1000, 2) . " ms\n";
        echo "Min response time: " . round($minResponseTime * 1000, 2) . " ms\n";
        echo "Max response time: " . round($maxResponseTime * 1000, 2) . " ms\n";
    }

    private function makeApiRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status' => $httpCode,
            'body' => json_decode($response, true)
        ];
    }

    private function makeAsyncApiRequest(string $method, string $endpoint, array $data = []): resource
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        return $ch;
    }

    private function getAsyncResponse($ch): array
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status' => $httpCode,
            'body' => json_decode($response, true)
        ];
    }
}