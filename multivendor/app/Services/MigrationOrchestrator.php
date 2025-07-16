<?php

namespace Antinna\Multivendor\Services;

use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Exceptions\ValidationException;

class MigrationOrchestrator
{
    private Logger $logger;
    private array $services;
    private array $migrationProgress;
    private string $progressFile;

    public function __construct(Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->progressFile = __DIR__ . '/../../logs/migration_progress.json';
        
        $this->services = [
            'auth' => [
                'name' => 'Authentication Service',
                'url' => getenv('AUTH_SERVICE_URL') ?: 'http://localhost:8001',
                'migration_endpoint' => '/migrate',
                'health_endpoint' => '/health'
            ],
            'pay' => [
                'name' => 'Payment Service',
                'url' => getenv('PAY_SERVICE_URL') ?: 'http://localhost:8002',
                'migration_endpoint' => '/migrate',
                'health_endpoint' => '/health'
            ],
            'social' => [
                'name' => 'Social Service',
                'url' => getenv('SOCIAL_SERVICE_URL') ?: 'http://localhost:8003',
                'migration_endpoint' => '/migrate',
                'health_endpoint' => '/health'
            ],
            'delivery' => [
                'name' => 'Delivery Service',
                'url' => getenv('DELIVERY_SERVICE_URL') ?: 'http://localhost:8004',
                'migration_endpoint' => '/migrate',
                'health_endpoint' => '/health'
            ],
            'multivendor' => [
                'name' => 'Multivendor Service',
                'url' => 'local',
                'migration_endpoint' => '/migrate',
                'health_endpoint' => '/health'
            ]
        ];

        $this->initializeProgress();
    }

    /**
     * Initialize migration progress tracking
     */
    private function initializeProgress(): void
    {
        if (file_exists($this->progressFile)) {
            $content = file_get_contents($this->progressFile);
            $this->migrationProgress = json_decode($content, true) ?: [];
        } else {
            $this->migrationProgress = [];
        }
    }

    /**
     * Save migration progress to file
     */
    private function saveProgress(): void
    {
        $dir = dirname($this->progressFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->progressFile, json_encode($this->migrationProgress, JSON_PRETTY_PRINT));
    }

    /**
     * Start migration across all services
     */
    public function startMigration(array $options = []): array
    {
        $migrationId = uniqid('migration_', true);
        $timestamp = time();
        
        $this->logger->info('Starting migration orchestration', [
            'migration_id' => $migrationId,
            'services' => array_keys($this->services),
            'options' => $options
        ]);

        // Initialize migration progress
        $this->migrationProgress[$migrationId] = [
            'id' => $migrationId,
            'status' => 'running',
            'started_at' => $timestamp,
            'services' => [],
            'options' => $options,
            'total_services' => count($this->services),
            'completed_services' => 0,
            'failed_services' => 0
        ];

        // Initialize service statuses
        foreach ($this->services as $serviceKey => $serviceConfig) {
            $this->migrationProgress[$migrationId]['services'][$serviceKey] = [
                'name' => $serviceConfig['name'],
                'status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'error' => null,
                'progress' => 0
            ];
        }

        $this->saveProgress();

        // Start migration process in background
        $this->executeMigration($migrationId, $options);

        return [
            'success' => true,
            'migration_id' => $migrationId,
            'message' => 'Migration started successfully',
            'progress_url' => "/admin/migration/progress/{$migrationId}"
        ];
    }

    /**
     * Execute migration across all services
     */
    private function executeMigration(string $migrationId, array $options): void
    {
        try {
            $parallel = $options['parallel'] ?? false;
            
            if ($parallel) {
                $this->executeParallelMigration($migrationId, $options);
            } else {
                $this->executeSequentialMigration($migrationId, $options);
            }
            
            $this->completeMigration($migrationId);
            
        } catch (\Exception $e) {
            $this->failMigration($migrationId, $e->getMessage());
        }
    }

    /**
     * Execute migrations sequentially
     */
    private function executeSequentialMigration(string $migrationId, array $options): void
    {
        foreach ($this->services as $serviceKey => $serviceConfig) {
            $this->updateServiceStatus($migrationId, $serviceKey, 'running');
            
            try {
                $result = $this->migrateService($serviceKey, $serviceConfig, $options);
                
                if ($result['success']) {
                    $this->updateServiceStatus($migrationId, $serviceKey, 'completed');
                    $this->migrationProgress[$migrationId]['completed_services']++;
                } else {
                    $this->updateServiceStatus($migrationId, $serviceKey, 'failed', $result['error']);
                    $this->migrationProgress[$migrationId]['failed_services']++;
                    
                    if (!($options['continue_on_error'] ?? false)) {
                        throw new \Exception("Migration failed for service: {$serviceKey}");
                    }
                }
                
            } catch (\Exception $e) {
                $this->updateServiceStatus($migrationId, $serviceKey, 'failed', $e->getMessage());
                $this->migrationProgress[$migrationId]['failed_services']++;
                
                if (!($options['continue_on_error'] ?? false)) {
                    throw $e;
                }
            }
            
            $this->saveProgress();
        }
    }

    /**
     * Execute migrations in parallel (simplified implementation)
     */
    private function executeParallelMigration(string $migrationId, array $options): void
    {
        $processes = [];
        
        foreach ($this->services as $serviceKey => $serviceConfig) {
            $this->updateServiceStatus($migrationId, $serviceKey, 'running');
            
            // In a real implementation, you would use proper process management
            // For now, we'll simulate parallel execution
            try {
                $result = $this->migrateService($serviceKey, $serviceConfig, $options);
                
                if ($result['success']) {
                    $this->updateServiceStatus($migrationId, $serviceKey, 'completed');
                    $this->migrationProgress[$migrationId]['completed_services']++;
                } else {
                    $this->updateServiceStatus($migrationId, $serviceKey, 'failed', $result['error']);
                    $this->migrationProgress[$migrationId]['failed_services']++;
                }
                
            } catch (\Exception $e) {
                $this->updateServiceStatus($migrationId, $serviceKey, 'failed', $e->getMessage());
                $this->migrationProgress[$migrationId]['failed_services']++;
            }
        }
        
        $this->saveProgress();
    }

    /**
     * Migrate a single service
     */
    private function migrateService(string $serviceKey, array $serviceConfig, array $options): array
    {
        $this->logger->info('Starting migration for service', [
            'service' => $serviceKey,
            'name' => $serviceConfig['name']
        ]);

        if ($serviceKey === 'multivendor') {
            return $this->migrateLocalService($options);
        }

        return $this->migrateRemoteService($serviceKey, $serviceConfig, $options);
    }

    /**
     * Migrate local multivendor service
     */
    private function migrateLocalService(array $options): array
    {
        try {
            // Run local migrations
            $migrationPath = __DIR__ . '/../Database/Migrations';
            
            if (!is_dir($migrationPath)) {
                return [
                    'success' => false,
                    'error' => 'Migration directory not found'
                ];
            }

            $migrations = glob($migrationPath . '/*.php');
            sort($migrations);

            foreach ($migrations as $migrationFile) {
                $migrationClass = $this->getMigrationClassName($migrationFile);
                
                if (class_exists($migrationClass)) {
                    $migration = new $migrationClass();
                    
                    if (method_exists($migration, 'up')) {
                        $migration->up();
                        $this->logger->info('Executed migration', [
                            'file' => basename($migrationFile),
                            'class' => $migrationClass
                        ]);
                    }
                }
            }

            return [
                'success' => true,
                'message' => 'Local migrations completed successfully',
                'migrations_count' => count($migrations)
            ];

        } catch (\Exception $e) {
            $this->logger->error('Local migration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Migrate remote service via HTTP API
     */
    private function migrateRemoteService(string $serviceKey, array $serviceConfig, array $options): array
    {
        try {
            $url = $serviceConfig['url'] . $serviceConfig['migration_endpoint'];
            
            $postData = json_encode([
                'action' => 'migrate',
                'options' => $options
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($postData)
                    ],
                    'content' => $postData,
                    'timeout' => 300 // 5 minutes timeout
                ]
            ]);

            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                throw new \Exception("Failed to connect to service: {$serviceKey}");
            }

            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON response from service: {$serviceKey}");
            }

            $this->logger->info('Remote migration completed', [
                'service' => $serviceKey,
                'response' => $result
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Remote migration failed', [
                'service' => $serviceKey,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get migration class name from file path
     */
    private function getMigrationClassName(string $filePath): string
    {
        $filename = basename($filePath, '.php');
        $parts = explode('_', $filename, 2);
        
        if (count($parts) >= 2) {
            $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $parts[1])));
            return "Antinna\\Multivendor\\Database\\Migrations\\{$className}";
        }
        
        return '';
    }

    /**
     * Update service status in migration progress
     */
    private function updateServiceStatus(string $migrationId, string $serviceKey, string $status, ?string $error = null): void
    {
        if (!isset($this->migrationProgress[$migrationId])) {
            return;
        }

        $this->migrationProgress[$migrationId]['services'][$serviceKey]['status'] = $status;
        
        if ($status === 'running') {
            $this->migrationProgress[$migrationId]['services'][$serviceKey]['started_at'] = time();
        } elseif (in_array($status, ['completed', 'failed'])) {
            $this->migrationProgress[$migrationId]['services'][$serviceKey]['completed_at'] = time();
        }
        
        if ($error) {
            $this->migrationProgress[$migrationId]['services'][$serviceKey]['error'] = $error;
        }
    }

    /**
     * Complete migration
     */
    private function completeMigration(string $migrationId): void
    {
        $this->migrationProgress[$migrationId]['status'] = 'completed';
        $this->migrationProgress[$migrationId]['completed_at'] = time();
        
        $this->logger->info('Migration orchestration completed', [
            'migration_id' => $migrationId,
            'completed_services' => $this->migrationProgress[$migrationId]['completed_services'],
            'failed_services' => $this->migrationProgress[$migrationId]['failed_services']
        ]);
        
        $this->saveProgress();
    }

    /**
     * Fail migration
     */
    private function failMigration(string $migrationId, string $error): void
    {
        $this->migrationProgress[$migrationId]['status'] = 'failed';
        $this->migrationProgress[$migrationId]['completed_at'] = time();
        $this->migrationProgress[$migrationId]['error'] = $error;
        
        $this->logger->error('Migration orchestration failed', [
            'migration_id' => $migrationId,
            'error' => $error
        ]);
        
        $this->saveProgress();
    }

    /**
     * Get migration progress
     */
    public function getProgress(string $migrationId): ?array
    {
        return $this->migrationProgress[$migrationId] ?? null;
    }

    /**
     * Get all migration history
     */
    public function getHistory(): array
    {
        return array_values($this->migrationProgress);
    }

    /**
     * Check service health
     */
    public function checkServiceHealth(string $serviceKey): array
    {
        if (!isset($this->services[$serviceKey])) {
            return [
                'service' => $serviceKey,
                'status' => 'unknown',
                'error' => 'Service not configured'
            ];
        }

        $serviceConfig = $this->services[$serviceKey];
        
        if ($serviceKey === 'multivendor') {
            return [
                'service' => $serviceKey,
                'name' => $serviceConfig['name'],
                'status' => 'healthy',
                'url' => 'local'
            ];
        }

        try {
            $url = $serviceConfig['url'] . $serviceConfig['health_endpoint'];
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10
                ]
            ]);

            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                return [
                    'service' => $serviceKey,
                    'name' => $serviceConfig['name'],
                    'status' => 'unhealthy',
                    'error' => 'Connection failed',
                    'url' => $serviceConfig['url']
                ];
            }

            $healthData = json_decode($response, true);
            
            return [
                'service' => $serviceKey,
                'name' => $serviceConfig['name'],
                'status' => 'healthy',
                'url' => $serviceConfig['url'],
                'response' => $healthData
            ];

        } catch (\Exception $e) {
            return [
                'service' => $serviceKey,
                'name' => $serviceConfig['name'],
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'url' => $serviceConfig['url']
            ];
        }
    }

    /**
     * Check health of all services
     */
    public function checkAllServicesHealth(): array
    {
        $results = [];
        
        foreach ($this->services as $serviceKey => $serviceConfig) {
            $results[$serviceKey] = $this->checkServiceHealth($serviceKey);
        }
        
        return $results;
    }

    /**
     * Get service configuration
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Cancel running migration
     */
    public function cancelMigration(string $migrationId): array
    {
        if (!isset($this->migrationProgress[$migrationId])) {
            return [
                'success' => false,
                'error' => 'Migration not found'
            ];
        }

        if ($this->migrationProgress[$migrationId]['status'] !== 'running') {
            return [
                'success' => false,
                'error' => 'Migration is not running'
            ];
        }

        $this->migrationProgress[$migrationId]['status'] = 'cancelled';
        $this->migrationProgress[$migrationId]['completed_at'] = time();
        
        $this->logger->info('Migration cancelled', [
            'migration_id' => $migrationId
        ]);
        
        $this->saveProgress();

        return [
            'success' => true,
            'message' => 'Migration cancelled successfully'
        ];
    }
}