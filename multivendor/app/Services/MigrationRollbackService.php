<?php

namespace Antinna\Multivendor\Services;

use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Services\MigrationHistoryTracker;
use Antinna\Multivendor\Services\ServiceHealthChecker;

class MigrationRollbackService
{
    private Logger $logger;
    private MigrationHistoryTracker $historyTracker;
    private ServiceHealthChecker $healthChecker;
    private array $services;

    public function __construct(Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->historyTracker = new MigrationHistoryTracker($this->logger);
        $this->healthChecker = new ServiceHealthChecker($this->logger);
        
        $this->services = [
            'auth' => [
                'name' => 'Authentication Service',
                'url' => getenv('AUTH_SERVICE_URL') ?: 'http://localhost:8001',
                'rollback_endpoint' => '/migrate/rollback'
            ],
            'pay' => [
                'name' => 'Payment Service',
                'url' => getenv('PAY_SERVICE_URL') ?: 'http://localhost:8002',
                'rollback_endpoint' => '/migrate/rollback'
            ],
            'social' => [
                'name' => 'Social Service',
                'url' => getenv('SOCIAL_SERVICE_URL') ?: 'http://localhost:8003',
                'rollback_endpoint' => '/migrate/rollback'
            ],
            'delivery' => [
                'name' => 'Delivery Service',
                'url' => getenv('DELIVERY_SERVICE_URL') ?: 'http://localhost:8004',
                'rollback_endpoint' => '/migrate/rollback'
            ],
            'multivendor' => [
                'name' => 'Multivendor Service',
                'url' => 'local',
                'rollback_endpoint' => '/migrate/rollback'
            ]
        ];
    }

    /**
     * Rollback a specific migration
     */
    public function rollbackMigration(string $migrationId, string $reason, array $options = []): array
    {
        $rollbackId = uniqid('rollback_', true);
        
        $this->logger->info('Starting migration rollback', [
            'rollback_id' => $rollbackId,
            'migration_id' => $migrationId,
            'reason' => $reason,
            'options' => $options
        ]);

        try {
            // Get migration history to determine what needs to be rolled back
            $migrationHistory = $this->historyTracker->getMigrationHistory($migrationId);
            
            if (empty($migrationHistory)) {
                return [
                    'success' => false,
                    'error' => 'Migration not found in history',
                    'migration_id' => $migrationId
                ];
            }

            // Filter for completed migrations that can be rolled back
            $rollbackTargets = array_filter($migrationHistory, function($record) {
                return $record['status'] === 'completed';
            });

            if (empty($rollbackTargets)) {
                return [
                    'success' => false,
                    'error' => 'No completed migrations found to rollback',
                    'migration_id' => $migrationId
                ];
            }

            // Check service health before rollback
            $healthCheck = $this->performPreRollbackHealthCheck($rollbackTargets);
            if (!$healthCheck['can_proceed']) {
                return [
                    'success' => false,
                    'error' => 'Pre-rollback health check failed',
                    'health_issues' => $healthCheck['issues'],
                    'migration_id' => $migrationId
                ];
            }

            // Execute rollback for each service
            $rollbackResults = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($rollbackTargets as $target) {
                $serviceKey = $this->getServiceKeyFromName($target['service_name']);
                if (!$serviceKey) {
                    continue;
                }

                $result = $this->rollbackServiceMigration(
                    $serviceKey, 
                    $migrationId, 
                    $target['migration_name'], 
                    $reason,
                    $options
                );

                $rollbackResults[$serviceKey] = $result;

                if ($result['success']) {
                    $successCount++;
                    $this->historyTracker->recordMigrationRollback(
                        $migrationId, 
                        $target['service_name'], 
                        $reason
                    );
                } else {
                    $failureCount++;
                }
            }

            // Determine overall success
            $overallSuccess = $failureCount === 0;

            $this->logger->info('Migration rollback completed', [
                'rollback_id' => $rollbackId,
                'migration_id' => $migrationId,
                'success' => $overallSuccess,
                'successful_services' => $successCount,
                'failed_services' => $failureCount
            ]);

            return [
                'success' => $overallSuccess,
                'rollback_id' => $rollbackId,
                'migration_id' => $migrationId,
                'reason' => $reason,
                'results' => $rollbackResults,
                'summary' => [
                    'total_services' => count($rollbackTargets),
                    'successful_rollbacks' => $successCount,
                    'failed_rollbacks' => $failureCount
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('Migration rollback failed', [
                'rollback_id' => $rollbackId,
                'migration_id' => $migrationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Rollback process failed: ' . $e->getMessage(),
                'rollback_id' => $rollbackId,
                'migration_id' => $migrationId
            ];
        }
    }

    /**
     * Rollback migration for a specific service
     */
    private function rollbackServiceMigration(string $serviceKey, string $migrationId, ?string $migrationName, string $reason, array $options): array
    {
        $this->logger->info('Rolling back service migration', [
            'service' => $serviceKey,
            'migration_id' => $migrationId,
            'migration_name' => $migrationName
        ]);

        try {
            if ($serviceKey === 'multivendor') {
                return $this->rollbackLocalMigration($migrationId, $migrationName, $reason, $options);
            } else {
                return $this->rollbackRemoteMigration($serviceKey, $migrationId, $migrationName, $reason, $options);
            }

        } catch (\Exception $e) {
            $this->logger->error('Service migration rollback failed', [
                'service' => $serviceKey,
                'migration_id' => $migrationId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'service' => $serviceKey,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Rollback local migration
     */
    private function rollbackLocalMigration(string $migrationId, ?string $migrationName, string $reason, array $options): array
    {
        try {
            // In a real implementation, you would:
            // 1. Find the migration file
            // 2. Execute the 'down' method if it exists
            // 3. Handle rollback logic specific to each migration
            
            $migrationPath = __DIR__ . '/../Database/Migrations';
            
            if ($migrationName) {
                $migrationFile = $this->findMigrationFile($migrationPath, $migrationName);
                
                if ($migrationFile) {
                    $migrationClass = $this->getMigrationClassName($migrationFile);
                    
                    if (class_exists($migrationClass)) {
                        $migration = new $migrationClass();
                        
                        if (method_exists($migration, 'down')) {
                            $migration->down();
                            
                            $this->logger->info('Local migration rolled back successfully', [
                                'migration_name' => $migrationName,
                                'file' => $migrationFile
                            ]);

                            return [
                                'success' => true,
                                'service' => 'multivendor',
                                'migration_name' => $migrationName,
                                'message' => 'Local migration rolled back successfully'
                            ];
                        } else {
                            return [
                                'success' => false,
                                'service' => 'multivendor',
                                'error' => 'Migration does not support rollback (no down method)'
                            ];
                        }
                    }
                }
            }

            // If specific migration not found, return success (might have been manual rollback)
            return [
                'success' => true,
                'service' => 'multivendor',
                'message' => 'Local rollback completed (migration file not found or already rolled back)'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'service' => 'multivendor',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Rollback remote migration
     */
    private function rollbackRemoteMigration(string $serviceKey, string $migrationId, ?string $migrationName, string $reason, array $options): array
    {
        if (!isset($this->services[$serviceKey])) {
            throw new \Exception("Service {$serviceKey} not configured");
        }

        $serviceConfig = $this->services[$serviceKey];
        $url = $serviceConfig['url'] . $serviceConfig['rollback_endpoint'];

        $postData = json_encode([
            'migration_id' => $migrationId,
            'migration_name' => $migrationName,
            'reason' => $reason,
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

        $this->logger->info('Remote migration rollback completed', [
            'service' => $serviceKey,
            'response' => $result
        ]);

        return array_merge($result, ['service' => $serviceKey]);
    }

    /**
     * Perform pre-rollback health check
     */
    private function performPreRollbackHealthCheck(array $rollbackTargets): array
    {
        $issues = [];
        $canProceed = true;

        // Check if services are healthy
        $healthResults = $this->healthChecker->checkAllServicesHealth(false); // Force fresh check

        foreach ($rollbackTargets as $target) {
            $serviceKey = $this->getServiceKeyFromName($target['service_name']);
            
            if ($serviceKey && isset($healthResults['services'][$serviceKey])) {
                $health = $healthResults['services'][$serviceKey];
                
                if ($health['status'] !== 'healthy') {
                    $issues[] = [
                        'service' => $serviceKey,
                        'issue' => 'Service is not healthy',
                        'status' => $health['status'],
                        'error' => $health['error'] ?? 'Unknown error'
                    ];
                    
                    // Only block rollback for critical services
                    if ($health['critical'] ?? false) {
                        $canProceed = false;
                    }
                }
            }
        }

        return [
            'can_proceed' => $canProceed,
            'issues' => $issues
        ];
    }

    /**
     * Get service key from service name
     */
    private function getServiceKeyFromName(string $serviceName): ?string
    {
        foreach ($this->services as $key => $config) {
            if ($config['name'] === $serviceName || $key === $serviceName) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Find migration file by name
     */
    private function findMigrationFile(string $migrationPath, string $migrationName): ?string
    {
        if (!is_dir($migrationPath)) {
            return null;
        }

        $files = glob($migrationPath . '/*.php');
        
        foreach ($files as $file) {
            $filename = basename($file, '.php');
            if (strpos($filename, $migrationName) !== false) {
                return $file;
            }
        }

        return null;
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
     * Get rollback candidates for a migration
     */
    public function getRollbackCandidates(string $migrationId): array
    {
        $migrationHistory = $this->historyTracker->getMigrationHistory($migrationId);
        
        $candidates = array_filter($migrationHistory, function($record) {
            return $record['status'] === 'completed';
        });

        return array_map(function($candidate) {
            return [
                'service_name' => $candidate['service_name'],
                'migration_name' => $candidate['migration_name'],
                'completed_at' => $candidate['completed_at'],
                'execution_time' => $candidate['execution_time_seconds']
            ];
        }, $candidates);
    }

    /**
     * Check if migration can be rolled back
     */
    public function canRollback(string $migrationId): array
    {
        $candidates = $this->getRollbackCandidates($migrationId);
        
        if (empty($candidates)) {
            return [
                'can_rollback' => false,
                'reason' => 'No completed migrations found',
                'candidates' => []
            ];
        }

        // Check service health
        $healthCheck = $this->performPreRollbackHealthCheck(
            $this->historyTracker->getMigrationHistory($migrationId)
        );

        return [
            'can_rollback' => $healthCheck['can_proceed'],
            'reason' => $healthCheck['can_proceed'] ? 'Ready for rollback' : 'Health check failed',
            'candidates' => $candidates,
            'health_issues' => $healthCheck['issues']
        ];
    }

    /**
     * Get rollback history
     */
    public function getRollbackHistory(int $limit = 20): array
    {
        // Get migrations that have been rolled back
        $allHistory = $this->historyTracker->getAllMigrationHistory(1, $limit * 2); // Get more to filter
        
        $rollbackHistory = array_filter($allHistory['data'], function($record) {
            return $record['status'] === 'rolled_back';
        });

        return array_slice($rollbackHistory, 0, $limit);
    }
}