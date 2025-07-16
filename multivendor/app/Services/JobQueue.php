<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Job queue system for background task processing
 */
class JobQueue
{
    private PDO $db;
    private array $workers = [];
    private bool $running = false;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->createQueueTables();
    }

    /**
     * Add job to queue
     */
    public function addJob(string $jobType, array $payload, array $options = []): array
    {
        try {
            // Validate job type
            if (!$this->isValidJobType($jobType)) {
                return [
                    'success' => false,
                    'error' => 'Invalid job type: ' . $jobType
                ];
            }

            // Prepare job data
            $jobData = [
                'job_type' => $jobType,
                'payload' => json_encode($payload),
                'priority' => $options['priority'] ?? 5,
                'max_attempts' => $options['max_attempts'] ?? 3,
                'delay_until' => $options['delay_until'] ?? null,
                'queue' => $options['queue'] ?? 'default',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Add unique job ID if specified
            if (isset($options['unique_id'])) {
                $jobData['unique_id'] = $options['unique_id'];
                
                // Check if unique job already exists
                if ($this->uniqueJobExists($options['unique_id'])) {
                    return [
                        'success' => false,
                        'error' => 'Job with this unique ID already exists'
                    ];
                }
            }

            $jobId = $this->createQueueJob($jobData);

            if ($jobId) {
                return [
                    'success' => true,
                    'message' => 'Job added to queue successfully',
                    'job_id' => $jobId,
                    'job_type' => $jobType,
                    'queue' => $jobData['queue'],
                    'priority' => $jobData['priority']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to add job to queue'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job queue addition failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process jobs from queue
     */
    public function processJobs(string $queue = 'default', int $maxJobs = 10): array
    {
        try {
            $jobs = $this->getNextJobs($queue, $maxJobs);
            $results = [
                'processed_jobs' => 0,
                'successful_jobs' => 0,
                'failed_jobs' => 0,
                'job_results' => []
            ];

            foreach ($jobs as $job) {
                $jobResult = $this->processJob($job);
                $results['job_results'][] = $jobResult;
                $results['processed_jobs']++;

                if ($jobResult['success']) {
                    $results['successful_jobs']++;
                } else {
                    $results['failed_jobs']++;
                }
            }

            return [
                'success' => true,
                'queue' => $queue,
                'processing_summary' => $results,
                'processed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process a single job
     */
    public function processJob(array $job): array
    {
        $startTime = microtime(true);
        $jobId = $job['id'];

        try {
            // Mark job as processing
            $this->updateJobStatus($jobId, 'processing');

            // Decode payload
            $payload = json_decode($job['payload'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid job payload JSON');
            }

            // Execute job based on type
            $result = $this->executeJob($job['job_type'], $payload);

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000); // milliseconds

            if ($result['success']) {
                // Job completed successfully
                $this->updateJobStatus($jobId, 'completed', $result['output'] ?? null, $executionTime);

                return [
                    'success' => true,
                    'job_id' => $jobId,
                    'job_type' => $job['job_type'],
                    'execution_time_ms' => $executionTime,
                    'output' => $result['output'] ?? null
                ];
            } else {
                // Job failed
                $this->handleJobFailure($job, $result['error'], $executionTime);

                return [
                    'success' => false,
                    'job_id' => $jobId,
                    'job_type' => $job['job_type'],
                    'execution_time_ms' => $executionTime,
                    'error' => $result['error'],
                    'will_retry' => $this->shouldRetryJob($job)
                ];
            }

        } catch (Exception $e) {
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000);

            // Handle unexpected errors
            $this->handleJobFailure($job, $e->getMessage(), $executionTime);

            return [
                'success' => false,
                'job_id' => $jobId,
                'job_type' => $job['job_type'],
                'execution_time_ms' => $executionTime,
                'error' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute job based on type
     */
    private function executeJob(string $jobType, array $payload): array
    {
        try {
            switch ($jobType) {
                case 'send_notification':
                    return $this->executeSendNotification($payload);
                    
                case 'generate_report':
                    return $this->executeGenerateReport($payload);
                    
                case 'process_payment':
                    return $this->executeProcessPayment($payload);
                    
                case 'update_inventory':
                    return $this->executeUpdateInventory($payload);
                    
                case 'send_email':
                    return $this->executeSendEmail($payload);
                    
                case 'cleanup_data':
                    return $this->executeCleanupData($payload);
                    
                case 'sync_external_data':
                    return $this->executeSyncExternalData($payload);
                    
                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown job type: ' . $jobType
                    ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job execution failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute send notification job
     */
    private function executeSendNotification(array $payload): array
    {
        try {
            // Validate required fields
            if (empty($payload['recipient']) || empty($payload['message'])) {
                return [
                    'success' => false,
                    'error' => 'Missing required fields: recipient, message'
                ];
            }

            // Mock notification sending
            // In production, this would integrate with actual notification services
            $notificationType = $payload['type'] ?? 'push';
            $recipient = $payload['recipient'];
            $message = $payload['message'];

            // Simulate processing time
            usleep(100000); // 100ms

            return [
                'success' => true,
                'output' => "Sent {$notificationType} notification to {$recipient}"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Notification sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute generate report job
     */
    private function executeGenerateReport(array $payload): array
    {
        try {
            $reportType = $payload['report_type'] ?? 'general';
            $vendorId = $payload['vendor_id'] ?? null;
            $dateRange = $payload['date_range'] ?? [];

            // Mock report generation
            // In production, this would generate actual reports
            $reportGenerator = new ReportGenerator();
            $result = $reportGenerator->generateReport($vendorId, $reportType, $dateRange);

            return [
                'success' => $result['success'],
                'output' => $result['success'] ? 
                    "Generated {$reportType} report" : 
                    $result['error']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute process payment job
     */
    private function executeProcessPayment(array $payload): array
    {
        try {
            // Validate required fields
            if (empty($payload['payment_id']) || empty($payload['amount'])) {
                return [
                    'success' => false,
                    'error' => 'Missing required fields: payment_id, amount'
                ];
            }

            // Mock payment processing
            // In production, this would integrate with payment gateways
            $paymentId = $payload['payment_id'];
            $amount = $payload['amount'];

            // Simulate processing time
            usleep(500000); // 500ms

            return [
                'success' => true,
                'output' => "Processed payment {$paymentId} for amount {$amount}"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Payment processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute update inventory job
     */
    private function executeUpdateInventory(array $payload): array
    {
        try {
            $inventoryTracker = new InventoryTracker();
            
            if (isset($payload['product_id']) && isset($payload['quantity_change'])) {
                $result = $inventoryTracker->updateStock(
                    $payload['product_id'],
                    $payload['quantity_change'],
                    $payload['reason'] ?? 'Background job update',
                    $payload['batch_id'] ?? null
                );

                return [
                    'success' => $result['success'],
                    'output' => $result['success'] ? 
                        "Updated inventory for product {$payload['product_id']}" : 
                        $result['error']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Missing required fields: product_id, quantity_change'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Inventory update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute send email job
     */
    private function executeSendEmail(array $payload): array
    {
        try {
            // Validate required fields
            if (empty($payload['to']) || empty($payload['subject']) || empty($payload['body'])) {
                return [
                    'success' => false,
                    'error' => 'Missing required fields: to, subject, body'
                ];
            }

            // Mock email sending
            // In production, this would use actual email service
            $to = $payload['to'];
            $subject = $payload['subject'];

            // Simulate processing time
            usleep(200000); // 200ms

            return [
                'success' => true,
                'output' => "Sent email to {$to} with subject: {$subject}"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Email sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute cleanup data job
     */
    private function executeCleanupData(array $payload): array
    {
        try {
            $dataType = $payload['data_type'] ?? 'general';
            $olderThan = $payload['older_than_days'] ?? 30;

            // Mock data cleanup
            // In production, this would clean up actual data
            $deletedCount = rand(10, 100); // Mock deleted count

            return [
                'success' => true,
                'output' => "Cleaned up {$deletedCount} {$dataType} records older than {$olderThan} days"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Data cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute sync external data job
     */
    private function executeSyncExternalData(array $payload): array
    {
        try {
            $source = $payload['source'] ?? 'unknown';
            $dataType = $payload['data_type'] ?? 'general';

            // Mock external data sync
            // In production, this would sync with external APIs
            $syncedCount = rand(5, 50); // Mock synced count

            // Simulate processing time
            usleep(1000000); // 1 second

            return [
                'success' => true,
                'output' => "Synced {$syncedCount} {$dataType} records from {$source}"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'External data sync failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(string $queue = null): array
    {
        try {
            $whereClause = $queue ? "WHERE queue = ?" : "";
            $params = $queue ? [$queue] : [];

            $sql = "SELECT 
                        queue,
                        COUNT(*) as total_jobs,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                        AVG(execution_time_ms) as avg_execution_time,
                        MAX(created_at) as latest_job_time
                    FROM job_queue 
                    {$whereClause}
                    GROUP BY queue
                    ORDER BY queue";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetchAll();

            return [
                'success' => true,
                'queue_stats' => $stats,
                'generated_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get queue stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clear completed jobs
     */
    public function clearCompletedJobs(string $queue = null, int $olderThanHours = 24): array
    {
        try {
            $whereClause = "WHERE status = 'completed' AND completed_at < ?";
            $params = [date('Y-m-d H:i:s', strtotime("-{$olderThanHours} hours"))];

            if ($queue) {
                $whereClause .= " AND queue = ?";
                $params[] = $queue;
            }

            $sql = "DELETE FROM job_queue {$whereClause}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $deletedCount = $stmt->rowCount();

            return [
                'success' => true,
                'message' => 'Completed jobs cleared successfully',
                'deleted_count' => $deletedCount,
                'queue' => $queue,
                'older_than_hours' => $olderThanHours
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to clear completed jobs: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Retry failed jobs
     */
    public function retryFailedJobs(string $queue = null, int $maxAge = 24): array
    {
        try {
            $whereClause = "WHERE status = 'failed' AND attempts < max_attempts AND created_at > ?";
            $params = [date('Y-m-d H:i:s', strtotime("-{$maxAge} hours"))];

            if ($queue) {
                $whereClause .= " AND queue = ?";
                $params[] = $queue;
            }

            $sql = "UPDATE job_queue 
                    SET status = 'pending', 
                        attempts = 0, 
                        error_message = NULL,
                        updated_at = NOW()
                    {$whereClause}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $retriedCount = $stmt->rowCount();

            return [
                'success' => true,
                'message' => 'Failed jobs retried successfully',
                'retried_count' => $retriedCount,
                'queue' => $queue
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to retry jobs: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get next jobs to process
     */
    private function getNextJobs(string $queue, int $limit): array
    {
        try {
            $sql = "SELECT * FROM job_queue 
                    WHERE queue = ? 
                    AND status = 'pending' 
                    AND (delay_until IS NULL OR delay_until <= NOW())
                    ORDER BY priority DESC, created_at ASC 
                    LIMIT ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$queue, $limit]);

            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Check if job type is valid
     */
    private function isValidJobType(string $jobType): bool
    {
        $validTypes = [
            'send_notification',
            'generate_report',
            'process_payment',
            'update_inventory',
            'send_email',
            'cleanup_data',
            'sync_external_data'
        ];

        return in_array($jobType, $validTypes);
    }

    /**
     * Check if unique job exists
     */
    private function uniqueJobExists(string $uniqueId): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM job_queue WHERE unique_id = ? AND status IN ('pending', 'processing')";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$uniqueId]);

            return $stmt->fetchColumn() > 0;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create queue job
     */
    private function createQueueJob(array $jobData): ?int
    {
        try {
            $sql = "INSERT INTO job_queue (" . implode(', ', array_keys($jobData)) . ") 
                    VALUES (" . str_repeat('?,', count($jobData) - 1) . "?)";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($jobData));

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update job status
     */
    private function updateJobStatus(int $jobId, string $status, ?string $output = null, ?int $executionTime = null): void
    {
        try {
            $updateFields = ['status = ?', 'updated_at = NOW()'];
            $params = [$status];

            if ($output !== null) {
                $updateFields[] = 'output = ?';
                $params[] = $output;
            }

            if ($executionTime !== null) {
                $updateFields[] = 'execution_time_ms = ?';
                $params[] = $executionTime;
            }

            if ($status === 'completed') {
                $updateFields[] = 'completed_at = NOW()';
            } elseif ($status === 'processing') {
                $updateFields[] = 'started_at = NOW()';
            }

            $params[] = $jobId;

            $sql = "UPDATE job_queue SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

        } catch (Exception $e) {
            // Log error but don't throw
        }
    }

    /**
     * Handle job failure
     */
    private function handleJobFailure(array $job, string $error, int $executionTime): void
    {
        try {
            $attempts = $job['attempts'] + 1;
            $maxAttempts = $job['max_attempts'];

            if ($attempts >= $maxAttempts) {
                // Max attempts reached, mark as failed
                $sql = "UPDATE job_queue 
                        SET status = 'failed', 
                            attempts = ?, 
                            error_message = ?,
                            execution_time_ms = ?,
                            updated_at = NOW() 
                        WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$attempts, $error, $executionTime, $job['id']]);
            } else {
                // Retry later
                $retryDelay = min(60 * pow(2, $attempts - 1), 3600); // Exponential backoff, max 1 hour
                $retryAt = date('Y-m-d H:i:s', strtotime("+{$retryDelay} seconds"));

                $sql = "UPDATE job_queue 
                        SET status = 'pending', 
                            attempts = ?, 
                            error_message = ?,
                            execution_time_ms = ?,
                            delay_until = ?,
                            updated_at = NOW() 
                        WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$attempts, $error, $executionTime, $retryAt, $job['id']]);
            }

        } catch (Exception $e) {
            // Log error but don't throw
        }
    }

    /**
     * Check if job should be retried
     */
    private function shouldRetryJob(array $job): bool
    {
        return ($job['attempts'] + 1) < $job['max_attempts'];
    }

    /**
     * Create queue tables
     */
    private function createQueueTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS job_queue (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            job_type VARCHAR(100) NOT NULL,
            payload JSON NOT NULL,
            queue VARCHAR(100) DEFAULT 'default',
            priority INT DEFAULT 5,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            unique_id VARCHAR(255) NULL,
            delay_until TIMESTAMP NULL,
            output TEXT,
            error_message TEXT,
            execution_time_ms INT,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_queue_status (queue, status),
            INDEX idx_priority (priority),
            INDEX idx_delay_until (delay_until),
            INDEX idx_unique_id (unique_id),
            INDEX idx_created_at (created_at)
        )";

        $this->db->exec($sql);
    }
}