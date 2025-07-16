<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Cron job management system for automated tasks
 */
class CronJobManager
{
    private PDO $db;
    private array $registeredJobs = [];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->createJobTables();
        $this->registerDefaultJobs();
    }

    /**
     * Register a cron job
     */
    public function registerJob(array $jobConfig): array
    {
        try {
            // Validate job configuration
            $validation = $this->validateJobConfig($jobConfig);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check if job already exists
            $existingJob = $this->getJobByName($jobConfig['name']);
            if ($existingJob) {
                return [
                    'success' => false,
                    'error' => 'Job with this name already exists'
                ];
            }

            // Create job record
            $jobData = [
                'name' => $jobConfig['name'],
                'description' => $jobConfig['description'] ?? '',
                'schedule' => $jobConfig['schedule'],
                'command' => $jobConfig['command'],
                'parameters' => isset($jobConfig['parameters']) ? json_encode($jobConfig['parameters']) : null,
                'enabled' => $jobConfig['enabled'] ?? true,
                'max_execution_time' => $jobConfig['max_execution_time'] ?? 300,
                'retry_attempts' => $jobConfig['retry_attempts'] ?? 3,
                'retry_delay' => $jobConfig['retry_delay'] ?? 60,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $jobId = $this->createJob($jobData);

            if ($jobId) {
                // Calculate next run time
                $nextRun = $this->calculateNextRun($jobConfig['schedule']);
                $this->updateJobNextRun($jobId, $nextRun);

                return [
                    'success' => true,
                    'message' => 'Job registered successfully',
                    'job_id' => $jobId,
                    'next_run' => $nextRun
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create job record'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job registration failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute pending jobs
     */
    public function executePendingJobs(): array
    {
        try {
            $pendingJobs = $this->getPendingJobs();
            $results = [
                'total_jobs' => count($pendingJobs),
                'successful' => 0,
                'failed' => 0,
                'skipped' => 0,
                'job_results' => []
            ];

            foreach ($pendingJobs as $job) {
                $jobResult = $this->executeJob($job);
                $results['job_results'][] = $jobResult;

                if ($jobResult['success']) {
                    $results['successful']++;
                } elseif ($jobResult['skipped']) {
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                }
            }

            return [
                'success' => true,
                'execution_summary' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job execution failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute a specific job
     */
    public function executeJob(array $job): array
    {
        $startTime = microtime(true);
        $jobId = $job['id'];

        try {
            // Check if job is already running
            if ($this->isJobRunning($jobId)) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'job_id' => $jobId,
                    'job_name' => $job['name'],
                    'message' => 'Job is already running'
                ];
            }

            // Mark job as running
            $this->markJobAsRunning($jobId);

            // Log job start
            $executionId = $this->logJobExecution($jobId, 'started');

            // Execute the job command
            $result = $this->runJobCommand($job);

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000); // milliseconds

            if ($result['success']) {
                // Job succeeded
                $this->updateJobExecution($executionId, 'completed', $result['output'], $executionTime);
                $this->updateJobLastRun($jobId, date('Y-m-d H:i:s'));
                $this->updateJobNextRun($jobId, $this->calculateNextRun($job['schedule']));
                $this->markJobAsIdle($jobId);

                return [
                    'success' => true,
                    'job_id' => $jobId,
                    'job_name' => $job['name'],
                    'execution_time_ms' => $executionTime,
                    'output' => $result['output'],
                    'next_run' => $this->calculateNextRun($job['schedule'])
                ];
            } else {
                // Job failed
                $this->updateJobExecution($executionId, 'failed', $result['error'], $executionTime);
                $this->handleJobFailure($job, $result['error']);

                return [
                    'success' => false,
                    'job_id' => $jobId,
                    'job_name' => $job['name'],
                    'execution_time_ms' => $executionTime,
                    'error' => $result['error'],
                    'retry_scheduled' => $this->shouldRetryJob($job)
                ];
            }

        } catch (Exception $e) {
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000);

            // Handle unexpected errors
            if (isset($executionId)) {
                $this->updateJobExecution($executionId, 'error', $e->getMessage(), $executionTime);
            }
            $this->markJobAsIdle($jobId);

            return [
                'success' => false,
                'job_id' => $jobId,
                'job_name' => $job['name'],
                'execution_time_ms' => $executionTime,
                'error' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get job status and statistics
     */
    public function getJobStatus(?int $jobId = null): array
    {
        try {
            if ($jobId) {
                // Get specific job status
                $job = $this->getJobById($jobId);
                if (!$job) {
                    return [
                        'success' => false,
                        'error' => 'Job not found'
                    ];
                }

                $executions = $this->getJobExecutions($jobId, 10);
                $statistics = $this->getJobStatistics($jobId);

                return [
                    'success' => true,
                    'job' => $job,
                    'recent_executions' => $executions,
                    'statistics' => $statistics
                ];
            } else {
                // Get all jobs status
                $jobs = $this->getAllJobs();
                $summary = $this->getJobsSummary();

                return [
                    'success' => true,
                    'jobs' => $jobs,
                    'summary' => $summary
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get job status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Enable or disable a job
     */
    public function toggleJob(int $jobId, bool $enabled): array
    {
        try {
            $job = $this->getJobById($jobId);
            if (!$job) {
                return [
                    'success' => false,
                    'error' => 'Job not found'
                ];
            }

            $sql = "UPDATE cron_jobs SET enabled = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$enabled, $jobId]);

            if ($success) {
                // Update next run time if enabling
                if ($enabled) {
                    $nextRun = $this->calculateNextRun($job['schedule']);
                    $this->updateJobNextRun($jobId, $nextRun);
                }

                return [
                    'success' => true,
                    'message' => $enabled ? 'Job enabled' : 'Job disabled',
                    'job_id' => $jobId,
                    'enabled' => $enabled,
                    'next_run' => $enabled ? $this->calculateNextRun($job['schedule']) : null
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update job status'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job toggle failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete a job
     */
    public function deleteJob(int $jobId): array
    {
        try {
            $job = $this->getJobById($jobId);
            if (!$job) {
                return [
                    'success' => false,
                    'error' => 'Job not found'
                ];
            }

            // Check if job is currently running
            if ($this->isJobRunning($jobId)) {
                return [
                    'success' => false,
                    'error' => 'Cannot delete a running job'
                ];
            }

            // Delete job executions first
            $sql = "DELETE FROM cron_job_executions WHERE job_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$jobId]);

            // Delete the job
            $sql = "DELETE FROM cron_jobs WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$jobId]);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Job deleted successfully',
                    'job_id' => $jobId
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to delete job'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job deletion failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get job execution history
     */
    public function getJobHistory(int $jobId, int $limit = 50): array
    {
        try {
            $job = $this->getJobById($jobId);
            if (!$job) {
                return [
                    'success' => false,
                    'error' => 'Job not found'
                ];
            }

            $executions = $this->getJobExecutions($jobId, $limit);
            $statistics = $this->getJobStatistics($jobId);

            return [
                'success' => true,
                'job_id' => $jobId,
                'job_name' => $job['name'],
                'executions' => $executions,
                'statistics' => $statistics
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get job history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate job configuration
     */
    private function validateJobConfig(array $config): array
    {
        $errors = [];

        // Required fields
        if (empty($config['name'])) {
            $errors['name'] = 'Job name is required';
        }

        if (empty($config['schedule'])) {
            $errors['schedule'] = 'Job schedule is required';
        } elseif (!$this->isValidCronExpression($config['schedule'])) {
            $errors['schedule'] = 'Invalid cron expression';
        }

        if (empty($config['command'])) {
            $errors['command'] = 'Job command is required';
        }

        // Optional field validation
        if (isset($config['max_execution_time']) && (!is_numeric($config['max_execution_time']) || $config['max_execution_time'] < 1)) {
            $errors['max_execution_time'] = 'Max execution time must be a positive number';
        }

        if (isset($config['retry_attempts']) && (!is_numeric($config['retry_attempts']) || $config['retry_attempts'] < 0)) {
            $errors['retry_attempts'] = 'Retry attempts must be a non-negative number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Calculate next run time based on cron expression
     */
    private function calculateNextRun(string $cronExpression): string
    {
        // This is a simplified cron parser
        // In production, you'd use a proper cron expression library
        
        $now = new DateTime();
        
        // Handle common patterns
        switch ($cronExpression) {
            case '* * * * *': // Every minute
                $now->modify('+1 minute');
                break;
            case '0 * * * *': // Every hour
                $now->modify('+1 hour')->setTime($now->format('H'), 0);
                break;
            case '0 0 * * *': // Daily at midnight
                $now->modify('+1 day')->setTime(0, 0);
                break;
            case '0 2 * * *': // Daily at 2 AM
                $now->modify('+1 day')->setTime(2, 0);
                break;
            case '0 0 * * 0': // Weekly on Sunday
                $now->modify('next sunday')->setTime(0, 0);
                break;
            case '0 0 1 * *': // Monthly on 1st
                $now->modify('first day of next month')->setTime(0, 0);
                break;
            default:
                // Default to next hour for unknown patterns
                $now->modify('+1 hour');
                break;
        }

        return $now->format('Y-m-d H:i:s');
    }

    /**
     * Check if cron expression is valid
     */
    private function isValidCronExpression(string $expression): bool
    {
        // Basic validation - in production use a proper cron validator
        $parts = explode(' ', $expression);
        return count($parts) === 5;
    }

    /**
     * Get pending jobs that should run now
     */
    private function getPendingJobs(): array
    {
        $sql = "SELECT * FROM cron_jobs 
                WHERE enabled = 1 
                AND (next_run IS NULL OR next_run <= NOW())
                AND status != 'running'
                ORDER BY priority DESC, next_run ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Run job command
     */
    private function runJobCommand(array $job): array
    {
        try {
            $command = $job['command'];
            $parameters = $job['parameters'] ? json_decode($job['parameters'], true) : [];

            // Execute based on command type
            switch ($job['command']) {
                case 'generate_subscription_orders':
                    return $this->executeSubscriptionOrderGeneration($parameters);
                    
                case 'cleanup_expired_products':
                    return $this->executeExpiredProductCleanup($parameters);
                    
                case 'send_low_stock_alerts':
                    return $this->executeLowStockAlerts($parameters);
                    
                case 'process_delivery_failures':
                    return $this->executeDeliveryFailureProcessing($parameters);
                    
                case 'generate_vendor_reports':
                    return $this->executeVendorReportGeneration($parameters);
                    
                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown command: ' . $command
                    ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Command execution failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute subscription order generation
     */
    private function executeSubscriptionOrderGeneration(array $parameters): array
    {
        try {
            $orderGenerator = new OrderGenerator();
            $date = $parameters['date'] ?? date('Y-m-d');
            $vendorId = $parameters['vendor_id'] ?? null;
            
            $result = $orderGenerator->generateSubscriptionOrders($date, $vendorId);
            
            return [
                'success' => $result['success'],
                'output' => $result['success'] ? 
                    "Generated {$result['orders_generated']} orders for {$result['total_subscriptions_processed']} subscriptions" :
                    $result['error']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Subscription order generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute expired product cleanup
     */
    private function executeExpiredProductCleanup(array $parameters): array
    {
        try {
            $expiryManager = new ExpiryManager();
            $vendorId = $parameters['vendor_id'] ?? null;
            
            $result = $expiryManager->processExpiryCleanup($vendorId);
            
            return [
                'success' => $result['success'],
                'output' => $result['success'] ? 
                    "Cleaned up {$result['products_removed']} expired products" :
                    $result['error']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Expired product cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute low stock alerts
     */
    private function executeLowStockAlerts(array $parameters): array
    {
        try {
            $inventoryTracker = new InventoryTracker();
            $vendorId = $parameters['vendor_id'] ?? null;
            $threshold = $parameters['threshold'] ?? 10;
            
            $result = $inventoryTracker->getLowStockAlerts($vendorId, $threshold);
            
            if ($result['success'] && !empty($result['low_stock_products'])) {
                // Send notifications (would integrate with notification service)
                $alertCount = count($result['low_stock_products']);
                return [
                    'success' => true,
                    'output' => "Sent {$alertCount} low stock alerts"
                ];
            } else {
                return [
                    'success' => true,
                    'output' => 'No low stock alerts to send'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Low stock alert processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute delivery failure processing
     */
    private function executeDeliveryFailureProcessing(array $parameters): array
    {
        try {
            $deliveryFailureHandler = new DeliveryFailureHandler();
            $filters = $parameters['filters'] ?? ['status' => 'pending'];
            
            $failures = $deliveryFailureHandler->getFailures($filters);
            
            if ($failures['success'] && !empty($failures['failures'])) {
                $processedCount = 0;
                foreach ($failures['failures'] as $failure) {
                    $result = $deliveryFailureHandler->processFailure($failure['id'], 'auto_reschedule');
                    if ($result['success']) {
                        $processedCount++;
                    }
                }
                
                return [
                    'success' => true,
                    'output' => "Processed {$processedCount} delivery failures"
                ];
            } else {
                return [
                    'success' => true,
                    'output' => 'No delivery failures to process'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery failure processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute vendor report generation
     */
    private function executeVendorReportGeneration(array $parameters): array
    {
        try {
            $reportGenerator = new ReportGenerator();
            $vendorId = $parameters['vendor_id'] ?? null;
            $reportType = $parameters['report_type'] ?? 'comprehensive';
            
            $result = $reportGenerator->generateReport($vendorId, $reportType);
            
            return [
                'success' => $result['success'],
                'output' => $result['success'] ? 
                    "Generated {$reportType} report for vendor {$vendorId}" :
                    $result['error']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Vendor report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Register default system jobs
     */
    private function registerDefaultJobs(): void
    {
        $defaultJobs = [
            [
                'name' => 'generate_subscription_orders',
                'description' => 'Generate daily subscription orders at 2 AM',
                'schedule' => '0 2 * * *',
                'command' => 'generate_subscription_orders',
                'enabled' => true,
                'priority' => 10
            ],
            [
                'name' => 'cleanup_expired_products',
                'description' => 'Clean up expired products daily at 3 AM',
                'schedule' => '0 3 * * *',
                'command' => 'cleanup_expired_products',
                'enabled' => true,
                'priority' => 8
            ],
            [
                'name' => 'send_low_stock_alerts',
                'description' => 'Send low stock alerts every 6 hours',
                'schedule' => '0 */6 * * *',
                'command' => 'send_low_stock_alerts',
                'enabled' => true,
                'priority' => 6
            ],
            [
                'name' => 'process_delivery_failures',
                'description' => 'Process pending delivery failures every hour',
                'schedule' => '0 * * * *',
                'command' => 'process_delivery_failures',
                'enabled' => true,
                'priority' => 7
            ]
        ];

        foreach ($defaultJobs as $jobConfig) {
            $existing = $this->getJobByName($jobConfig['name']);
            if (!$existing) {
                $this->registerJob($jobConfig);
            }
        }
    }

    // Database helper methods
    private function createJob(array $jobData): ?int
    {
        try {
            $sql = "INSERT INTO cron_jobs (" . implode(', ', array_keys($jobData)) . ") 
                    VALUES (" . str_repeat('?,', count($jobData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($jobData));

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    private function getJobByName(string $name): ?array
    {
        try {
            $sql = "SELECT * FROM cron_jobs WHERE name = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$name]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    private function getJobById(int $jobId): ?array
    {
        try {
            $sql = "SELECT * FROM cron_jobs WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$jobId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    private function getAllJobs(): array
    {
        try {
            $sql = "SELECT * FROM cron_jobs ORDER BY priority DESC, name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    private function isJobRunning(int $jobId): bool
    {
        try {
            $sql = "SELECT status FROM cron_jobs WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$jobId]);
            $result = $stmt->fetch();
            
            return $result && $result['status'] === 'running';

        } catch (Exception $e) {
            return false;
        }
    }

    private function markJobAsRunning(int $jobId): void
    {
        $sql = "UPDATE cron_jobs SET status = 'running', updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$jobId]);
    }

    private function markJobAsIdle(int $jobId): void
    {
        $sql = "UPDATE cron_jobs SET status = 'idle', updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$jobId]);
    }

    private function updateJobLastRun(int $jobId, string $lastRun): void
    {
        $sql = "UPDATE cron_jobs SET last_run = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$lastRun, $jobId]);
    }

    private function updateJobNextRun(int $jobId, string $nextRun): void
    {
        $sql = "UPDATE cron_jobs SET next_run = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$nextRun, $jobId]);
    }

    private function logJobExecution(int $jobId, string $status): int
    {
        $sql = "INSERT INTO cron_job_executions (job_id, status, started_at, created_at) 
                VALUES (?, ?, NOW(), NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$jobId, $status]);
        
        return $this->db->lastInsertId();
    }

    private function updateJobExecution(int $executionId, string $status, string $output, int $executionTime): void
    {
        $sql = "UPDATE cron_job_executions 
                SET status = ?, output = ?, execution_time_ms = ?, completed_at = NOW() 
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $output, $executionTime, $executionId]);
    }

    private function getJobExecutions(int $jobId, int $limit): array
    {
        try {
            $sql = "SELECT * FROM cron_job_executions 
                    WHERE job_id = ? 
                    ORDER BY started_at DESC 
                    LIMIT ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$jobId, $limit]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    private function getJobStatistics(int $jobId): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_executions,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_executions,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_executions,
                        AVG(execution_time_ms) as avg_execution_time,
                        MAX(execution_time_ms) as max_execution_time,
                        MIN(execution_time_ms) as min_execution_time
                    FROM cron_job_executions 
                    WHERE job_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$jobId]);
            $stats = $stmt->fetch();

            return [
                'total_executions' => (int)$stats['total_executions'],
                'successful_executions' => (int)$stats['successful_executions'],
                'failed_executions' => (int)$stats['failed_executions'],
                'success_rate' => $stats['total_executions'] > 0 ? 
                    round(($stats['successful_executions'] / $stats['total_executions']) * 100, 2) : 0,
                'avg_execution_time_ms' => round($stats['avg_execution_time'] ?? 0, 2),
                'max_execution_time_ms' => (int)($stats['max_execution_time'] ?? 0),
                'min_execution_time_ms' => (int)($stats['min_execution_time'] ?? 0)
            ];

        } catch (Exception $e) {
            return [
                'total_executions' => 0,
                'successful_executions' => 0,
                'failed_executions' => 0,
                'success_rate' => 0,
                'avg_execution_time_ms' => 0,
                'max_execution_time_ms' => 0,
                'min_execution_time_ms' => 0
            ];
        }
    }

    private function getJobsSummary(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_jobs,
                        SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled_jobs,
                        SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_jobs,
                        SUM(CASE WHEN next_run <= NOW() AND enabled = 1 THEN 1 ELSE 0 END) as pending_jobs
                    FROM cron_jobs";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetch();

        } catch (Exception $e) {
            return [
                'total_jobs' => 0,
                'enabled_jobs' => 0,
                'running_jobs' => 0,
                'pending_jobs' => 0
            ];
        }
    }

    private function handleJobFailure(array $job, string $error): void
    {
        // Implement retry logic and failure handling
        $currentAttempts = $job['current_attempts'] ?? 0;
        $maxAttempts = $job['retry_attempts'];

        if ($currentAttempts < $maxAttempts) {
            // Schedule retry
            $retryDelay = $job['retry_delay'];
            $nextRun = date('Y-m-d H:i:s', strtotime("+{$retryDelay} seconds"));
            
            $sql = "UPDATE cron_jobs 
                    SET current_attempts = current_attempts + 1, 
                        next_run = ?, 
                        status = 'idle',
                        updated_at = NOW() 
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$nextRun, $job['id']]);
        } else {
            // Max retries reached, disable job
            $sql = "UPDATE cron_jobs 
                    SET enabled = 0, 
                        status = 'failed',
                        failure_reason = ?,
                        updated_at = NOW() 
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$error, $job['id']]);
        }
    }

    private function shouldRetryJob(array $job): bool
    {
        $currentAttempts = $job['current_attempts'] ?? 0;
        return $currentAttempts < $job['retry_attempts'];
    }

    /**
     * Create job tables
     */
    private function createJobTables(): void
    {
        // Cron jobs table
        $sql1 = "CREATE TABLE IF NOT EXISTS cron_jobs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) UNIQUE NOT NULL,
            description TEXT,
            schedule VARCHAR(100) NOT NULL,
            command VARCHAR(255) NOT NULL,
            parameters JSON,
            enabled BOOLEAN DEFAULT TRUE,
            status ENUM('idle', 'running', 'failed') DEFAULT 'idle',
            priority INT DEFAULT 5,
            max_execution_time INT DEFAULT 300,
            retry_attempts INT DEFAULT 3,
            retry_delay INT DEFAULT 60,
            current_attempts INT DEFAULT 0,
            last_run TIMESTAMP NULL,
            next_run TIMESTAMP NULL,
            failure_reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_enabled (enabled),
            INDEX idx_status (status),
            INDEX idx_next_run (next_run),
            INDEX idx_priority (priority)
        )";

        // Cron job executions table
        $sql2 = "CREATE TABLE IF NOT EXISTS cron_job_executions (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            job_id BIGINT NOT NULL,
            status ENUM('started', 'completed', 'failed', 'error') NOT NULL,
            output TEXT,
            execution_time_ms INT,
            started_at TIMESTAMP NOT NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_job_id (job_id),
            INDEX idx_status (status),
            INDEX idx_started_at (started_at),
            FOREIGN KEY (job_id) REFERENCES cron_jobs(id) ON DELETE CASCADE
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
    }
}