<?php

namespace Antinna\Multivendor\Tests;

use PHPUnit\Framework\TestCase;
use Antinna\Multivendor\Services\WebSocketHandler;
use Antinna\Multivendor\Services\Logger;

class WebSocketHandlerTest extends TestCase
{
    private WebSocketHandler $handler;
    private Logger $logger;
    private string $testLogPath;
    private string $testProgressFile;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/test_websocket_logs';
        $this->testProgressFile = $this->testLogPath . '/migration_progress.json';
        
        putenv('LOG_PATH=' . $this->testLogPath);
        
        $this->logger = $this->createMock(Logger::class);
        $this->handler = new WebSocketHandler($this->logger);
        
        // Clean up any existing test files
        if (is_dir($this->testLogPath)) {
            $this->cleanupTestFiles();
        }
        
        // Create test directory
        mkdir($this->testLogPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        putenv('LOG_PATH=');
    }

    private function cleanupTestFiles(): void
    {
        $files = glob($this->testLogPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testLogPath)) {
            rmdir($this->testLogPath);
        }
    }

    public function testGenerateClientScript(): void
    {
        $migrationId = 'test-migration-123';
        $script = $this->handler->generateClientScript($migrationId);
        
        $this->assertIsString($script);
        $this->assertStringContains('MigrationProgressClient', $script);
        $this->assertStringContains($migrationId, $script);
        $this->assertStringContains('EventSource', $script);
        $this->assertStringContains('progress', $script);
        $this->assertStringContains('finished', $script);
        $this->assertStringContains('heartbeat', $script);
    }

    public function testGenerateHealthClientScript(): void
    {
        $script = $this->handler->generateHealthClientScript();
        
        $this->assertIsString($script);
        $this->assertStringContains('HealthMonitoringClient', $script);
        $this->assertStringContains('EventSource', $script);
        $this->assertStringContains('health_update', $script);
        $this->assertStringContains('/admin/health/stream', $script);
    }

    public function testBroadcastMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Broadcasting WebSocket message', [
                'event' => 'test_event',
                'data' => ['message' => 'test']
            ]);

        $this->handler->broadcastMessage('test_event', ['message' => 'test']);
    }

    public function testGetProgressDataWithoutFile(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, 'non-existent-migration');
        $this->assertNull($result);
    }

    public function testGetProgressDataWithValidData(): void
    {
        // Create test progress data
        $migrationId = 'test-migration-123';
        $progressData = [
            $migrationId => [
                'id' => $migrationId,
                'status' => 'running',
                'started_at' => time() - 60,
                'total_services' => 3,
                'completed_services' => 1,
                'failed_services' => 0,
                'services' => [
                    'auth' => [
                        'status' => 'completed',
                        'started_at' => time() - 60,
                        'completed_at' => time() - 30
                    ],
                    'pay' => [
                        'status' => 'running',
                        'started_at' => time() - 30,
                        'completed_at' => null
                    ],
                    'social' => [
                        'status' => 'pending',
                        'started_at' => null,
                        'completed_at' => null
                    ]
                ]
            ]
        ];
        
        file_put_contents($this->testProgressFile, json_encode($progressData));
        
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, $migrationId);
        
        $this->assertNotNull($result);
        $this->assertEquals($migrationId, $result['id']);
        $this->assertEquals('running', $result['status']);
        $this->assertArrayHasKey('overall_progress', $result);
        
        // Check overall progress calculation
        $expectedProgress = (1 / 3) * 100; // 1 completed out of 3 total
        $this->assertEquals(round($expectedProgress, 2), $result['overall_progress']);
        
        // Check service progress
        $this->assertEquals(100, $result['services']['auth']['progress']);
        $this->assertGreaterThan(0, $result['services']['pay']['progress']);
        $this->assertEquals(0, $result['services']['social']['progress']);
    }

    public function testEstimateServiceProgress(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('estimateServiceProgress');
        $method->setAccessible(true);
        
        // Test with no start time
        $serviceData = ['started_at' => null];
        $result = $method->invoke($this->handler, $serviceData);
        $this->assertEquals(0, $result);
        
        // Test with recent start time
        $serviceData = ['started_at' => time() - 30]; // 30 seconds ago
        $result = $method->invoke($this->handler, $serviceData);
        $this->assertGreaterThan(0, $result);
        $this->assertLessThanOrEqual(95, $result); // Should be capped at 95%
        
        // Test with old start time
        $serviceData = ['started_at' => time() - 120]; // 2 minutes ago
        $result = $method->invoke($this->handler, $serviceData);
        $this->assertEquals(95, $result); // Should be capped at 95%
    }

    public function testProgressDataCalculations(): void
    {
        $migrationId = 'test-migration-456';
        $progressData = [
            $migrationId => [
                'id' => $migrationId,
                'status' => 'running',
                'total_services' => 5,
                'completed_services' => 2,
                'failed_services' => 1,
                'services' => [
                    'service1' => ['status' => 'completed'],
                    'service2' => ['status' => 'completed'],
                    'service3' => ['status' => 'failed'],
                    'service4' => ['status' => 'running', 'started_at' => time() - 15],
                    'service5' => ['status' => 'pending']
                ]
            ]
        ];
        
        file_put_contents($this->testProgressFile, json_encode($progressData));
        
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, $migrationId);
        
        // Overall progress should be (2 completed + 1 failed) / 5 total = 60%
        $this->assertEquals(60.0, $result['overall_progress']);
        
        // Check individual service progress
        $this->assertEquals(100, $result['services']['service1']['progress']);
        $this->assertEquals(100, $result['services']['service2']['progress']);
        $this->assertEquals(0, $result['services']['service3']['progress']);
        $this->assertGreaterThan(0, $result['services']['service4']['progress']);
        $this->assertEquals(0, $result['services']['service5']['progress']);
    }

    public function testClientScriptContainsRequiredMethods(): void
    {
        $script = $this->handler->generateClientScript('test-id');
        
        // Check for required methods
        $requiredMethods = [
            'connect()',
            'disconnect()',
            'on(',
            'trigger(',
            'EventSource'
        ];
        
        foreach ($requiredMethods as $method) {
            $this->assertStringContains($method, $script);
        }
        
        // Check for event handlers
        $requiredEvents = [
            'progress',
            'finished',
            'heartbeat',
            'connected',
            'error'
        ];
        
        foreach ($requiredEvents as $event) {
            $this->assertStringContains($event, $script);
        }
    }

    public function testHealthClientScriptContainsRequiredMethods(): void
    {
        $script = $this->handler->generateHealthClientScript();
        
        // Check for required methods
        $requiredMethods = [
            'connect()',
            'disconnect()',
            'on(',
            'trigger(',
            'EventSource'
        ];
        
        foreach ($requiredMethods as $method) {
            $this->assertStringContains($method, $script);
        }
        
        // Check for health-specific events
        $this->assertStringContains('health_update', $script);
        $this->assertStringContains('/admin/health/stream', $script);
    }

    public function testProgressDataWithInvalidJson(): void
    {
        // Create invalid JSON file
        file_put_contents($this->testProgressFile, 'invalid json content');
        
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, 'any-migration-id');
        $this->assertNull($result);
    }

    public function testProgressDataWithMissingMigration(): void
    {
        // Create valid JSON but without the requested migration
        $progressData = [
            'other-migration' => [
                'id' => 'other-migration',
                'status' => 'completed'
            ]
        ];
        
        file_put_contents($this->testProgressFile, json_encode($progressData));
        
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, 'requested-migration');
        $this->assertNull($result);
    }

    public function testProgressDataWithZeroServices(): void
    {
        $migrationId = 'empty-migration';
        $progressData = [
            $migrationId => [
                'id' => $migrationId,
                'status' => 'running',
                'total_services' => 0,
                'completed_services' => 0,
                'failed_services' => 0,
                'services' => []
            ]
        ];
        
        file_put_contents($this->testProgressFile, json_encode($progressData));
        
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getProgressData');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, $migrationId);
        
        $this->assertNotNull($result);
        $this->assertEquals(0, $result['overall_progress']);
    }

    public function testLoggingIntegration(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Broadcasting WebSocket message');

        $this->handler->broadcastMessage('test', ['data' => 'value']);
    }
}