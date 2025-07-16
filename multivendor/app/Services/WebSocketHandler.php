<?php

namespace Antinna\Multivendor\Services;

use Antinna\Multivendor\Services\Logger;

class WebSocketHandler
{
    private Logger $logger;
    private array $connections;
    private string $progressFile;

    public function __construct(Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->connections = [];
        $this->progressFile = __DIR__ . '/../../logs/migration_progress.json';
    }

    /**
     * Handle WebSocket connection for migration progress
     */
    public function handleProgressConnection(string $migrationId): void
    {
        // Set headers for Server-Sent Events (SSE) as a WebSocket alternative
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');

        $this->logger->info('WebSocket connection established for migration progress', [
            'migration_id' => $migrationId
        ]);

        // Send initial connection message
        $this->sendSSEMessage('connected', [
            'migration_id' => $migrationId,
            'timestamp' => time()
        ]);

        $lastModified = 0;
        $maxDuration = 300; // 5 minutes max connection time
        $startTime = time();

        while (connection_status() === CONNECTION_NORMAL && (time() - $startTime) < $maxDuration) {
            $progress = $this->getProgressData($migrationId);
            
            if ($progress) {
                $currentModified = filemtime($this->progressFile);
                
                // Send update if progress file was modified
                if ($currentModified > $lastModified) {
                    $this->sendSSEMessage('progress', $progress);
                    $lastModified = $currentModified;
                    
                    // Close connection if migration is completed or failed
                    if (in_array($progress['status'], ['completed', 'failed', 'cancelled'])) {
                        $this->sendSSEMessage('finished', [
                            'status' => $progress['status'],
                            'message' => 'Migration process finished'
                        ]);
                        break;
                    }
                }
            }

            // Send heartbeat every 30 seconds
            if ((time() - $startTime) % 30 === 0) {
                $this->sendSSEMessage('heartbeat', [
                    'timestamp' => time(),
                    'connection_duration' => time() - $startTime
                ]);
            }

            sleep(2); // Check every 2 seconds
        }

        $this->logger->info('WebSocket connection closed', [
            'migration_id' => $migrationId,
            'duration' => time() - $startTime
        ]);
    }

    /**
     * Send Server-Sent Event message
     */
    private function sendSSEMessage(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Get progress data for migration
     */
    private function getProgressData(string $migrationId): ?array
    {
        if (!file_exists($this->progressFile)) {
            return null;
        }

        $content = file_get_contents($this->progressFile);
        $allProgress = json_decode($content, true);

        if (!$allProgress || !isset($allProgress[$migrationId])) {
            return null;
        }

        $progress = $allProgress[$migrationId];
        
        // Calculate overall progress percentage
        $totalServices = $progress['total_services'];
        $completedServices = $progress['completed_services'];
        $failedServices = $progress['failed_services'];
        
        $overallProgress = $totalServices > 0 
            ? (($completedServices + $failedServices) / $totalServices) * 100 
            : 0;

        $progress['overall_progress'] = round($overallProgress, 2);
        
        // Add service progress details
        foreach ($progress['services'] as $serviceKey => &$serviceData) {
            if ($serviceData['status'] === 'running') {
                // Simulate progress for running services (in real implementation, 
                // services would report their own progress)
                $serviceData['progress'] = $this->estimateServiceProgress($serviceData);
            } elseif ($serviceData['status'] === 'completed') {
                $serviceData['progress'] = 100;
            } elseif ($serviceData['status'] === 'failed') {
                $serviceData['progress'] = 0;
            }
        }

        return $progress;
    }

    /**
     * Estimate service progress based on elapsed time
     */
    private function estimateServiceProgress(array $serviceData): int
    {
        if (!$serviceData['started_at']) {
            return 0;
        }

        $elapsed = time() - $serviceData['started_at'];
        $estimatedDuration = 60; // Assume 60 seconds average migration time
        
        $progress = ($elapsed / $estimatedDuration) * 100;
        
        // Cap at 95% until actually completed
        return min(95, max(0, round($progress)));
    }

    /**
     * Broadcast message to all connected clients (simplified implementation)
     */
    public function broadcastMessage(string $event, array $data): void
    {
        $this->logger->info('Broadcasting WebSocket message', [
            'event' => $event,
            'data' => $data
        ]);

        // In a real implementation, you would maintain active connections
        // and send messages to all connected clients
        // For now, we'll just log the broadcast
    }

    /**
     * Handle service health monitoring WebSocket
     */
    public function handleHealthMonitoring(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');

        $this->logger->info('Health monitoring WebSocket connection established');

        $this->sendSSEMessage('connected', [
            'type' => 'health_monitoring',
            'timestamp' => time()
        ]);

        $orchestrator = new MigrationOrchestrator($this->logger);
        $maxDuration = 600; // 10 minutes max connection time
        $startTime = time();

        while (connection_status() === CONNECTION_NORMAL && (time() - $startTime) < $maxDuration) {
            $healthData = $orchestrator->checkAllServicesHealth();
            
            $this->sendSSEMessage('health_update', [
                'services' => $healthData,
                'timestamp' => time(),
                'healthy_count' => count(array_filter($healthData, fn($s) => $s['status'] === 'healthy')),
                'total_count' => count($healthData)
            ]);

            sleep(10); // Check every 10 seconds
        }

        $this->logger->info('Health monitoring WebSocket connection closed', [
            'duration' => time() - $startTime
        ]);
    }

    /**
     * Generate JavaScript client code for WebSocket connection
     */
    public function generateClientScript(string $migrationId): string
    {
        return "
        class MigrationProgressClient {
            constructor(migrationId) {
                this.migrationId = migrationId;
                this.eventSource = null;
                this.callbacks = {};
            }
            
            connect() {
                const url = `/admin/migration/progress/$migrationId/stream`;
                this.eventSource = new EventSource(url);
                
                this.eventSource.onopen = () => {
                    console.log('Migration progress connection opened');
                    this.trigger('connected', { migrationId: this.migrationId });
                };
                
                this.eventSource.addEventListener('progress', (event) => {
                    const data = JSON.parse(event.data);
                    this.trigger('progress', data);
                });
                
                this.eventSource.addEventListener('finished', (event) => {
                    const data = JSON.parse(event.data);
                    this.trigger('finished', data);
                    this.disconnect();
                });
                
                this.eventSource.addEventListener('heartbeat', (event) => {
                    const data = JSON.parse(event.data);
                    this.trigger('heartbeat', data);
                });
                
                this.eventSource.onerror = (error) => {
                    console.error('Migration progress connection error:', error);
                    this.trigger('error', error);
                };
            }
            
            disconnect() {
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
            }
            
            on(event, callback) {
                if (!this.callbacks[event]) {
                    this.callbacks[event] = [];
                }
                this.callbacks[event].push(callback);
            }
            
            trigger(event, data) {
                if (this.callbacks[event]) {
                    this.callbacks[event].forEach(callback => callback(data));
                }
            }
        }
        
        // Usage example:
        // const client = new MigrationProgressClient('{$migrationId}');
        // client.on('progress', (data) => console.log('Progress:', data));
        // client.on('finished', (data) => console.log('Finished:', data));
        // client.connect();
        ";
    }

    /**
     * Generate health monitoring client script
     */
    public function generateHealthClientScript(): string
    {
        return "
        class HealthMonitoringClient {
            constructor() {
                this.eventSource = null;
                this.callbacks = {};
            }
            
            connect() {
                const url = '/admin/health/stream';
                this.eventSource = new EventSource(url);
                
                this.eventSource.onopen = () => {
                    console.log('Health monitoring connection opened');
                    this.trigger('connected', {});
                };
                
                this.eventSource.addEventListener('health_update', (event) => {
                    const data = JSON.parse(event.data);
                    this.trigger('health_update', data);
                });
                
                this.eventSource.onerror = (error) => {
                    console.error('Health monitoring connection error:', error);
                    this.trigger('error', error);
                };
            }
            
            disconnect() {
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
            }
            
            on(event, callback) {
                if (!this.callbacks[event]) {
                    this.callbacks[event] = [];
                }
                this.callbacks[event].push(callback);
            }
            
            trigger(event, data) {
                if (this.callbacks[event]) {
                    this.callbacks[event].forEach(callback => callback(data));
                }
            }
        }
        
        // Usage example:
        // const healthClient = new HealthMonitoringClient();
        // healthClient.on('health_update', (data) => console.log('Health:', data));
        // healthClient.connect();
        ";
    }
}