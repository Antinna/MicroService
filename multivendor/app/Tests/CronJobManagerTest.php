<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\CronJobManager;
use PHPUnit\Framework\TestCase;

class CronJobManagerTest extends TestCase
{
    private CronJobManager $cronManager;
    private array $testJobConfig;
    private ?int $testJobId = null;

    protected function setUp(): void
    {
        $this->cronManager = new CronJobManager();
        $this->testJobConfig = [
            'name' => 'test_job',
            'description' => 'Test job for unit testing',
            'schedule' => '0 * * * *', // Every hour
            'command' => 'cleanup_expired_products',
            'enabled' => true,
            'max_execution_time' => 300,
            'retry_attempts' => 3,
            'retry_delay' => 60
        ];
    }

    protected function tearDown(): void
    {
        // Clean up test job
        if ($this->testJobId) {
            $this->cronManager->deleteJob($this->testJobId);
        }
    }

    public function testRegisterJobSuccess()
    {
        $result = $this->cronManager->registerJob($this->testJobConfig);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('job_id', $result);
        $this->assertArrayHasKey('next_run', $result);
        $this->assertEquals('Job registered successfully', $result['message']);
        
        // Store for cleanup
        $this->testJobId = $result['job_id'];
    }

    public function testRegisterJobWithMissingName()
    {
        $invalidConfig = $this->testJobConfig;
        unset($invalidConfig['name']);

        $result = $this->cronManager->registerJob($invalidConfig);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testRegisterJobWithInvalidSchedule()
    {
        $invalidConfig = $this->testJobConfig;
        $invalidConfig['schedule'] = 'invalid cron';

        $result = $this->cronManager->registerJob($invalidConfig);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('schedule', $result['errors']);
    }

    public function testRegisterJobWithMissingCommand()
    {
        $invalidConfig = $this->testJobConfig;
        unset($invalidConfig['command']);

        $result = $this->cronManager->registerJob($invalidConfig);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('command', $result['errors']);
    }

    public function testRegisterDuplicateJob()
    {
        // Register first job
        $result1 = $this->cronManager->registerJob($this->testJobConfig);
        $this->assertTrue($result1['success']);
        $this->testJobId = $result1['job_id'];

        // Try to register same job again
        $result2 = $this->cronManager->registerJob($this->testJobConfig);
        
        $this->assertFalse($result2['success']);
        $this->assertEquals('Job with this name already exists', $result2['error']);
    }

    public function testExecutePendingJobs()
    {
        // Register a test job
        $result = $this->cronManager->registerJob($this->testJobConfig);
        $this->testJobId = $result['job_id'];

        // Execute pending jobs
        $executionResult = $this->cronManager->executePendingJobs();
        
        $this->assertTrue($executionResult['success']);
        $this->assertArrayHasKey('execution_summary', $executionResult);
        $this->assertArrayHasKey('total_jobs', $executionResult['execution_summary']);
    }

    public function testGetJobStatusForSpecificJob()
    {
        // Register a test job
        $result = $this->cronManager->registerJob($this->testJobConfig);
        $this->testJobId = $result['job_id'];

        // Get job status
        $statusResult = $this->cronManager->getJobStatus($this->testJobId);
        
        $this->assertTrue($statusResult['success']);
        $this->assertArrayHasKey('job', $statusResult);
        $this->assertArrayHasKey('recent_executions', $statusResult);
        $this->assertArrayHasKey('statistics', $statusResult);
        $this->assertEquals($this->testJobConfig['name'], $statusResult['job']['name']);
    }

    public function testGetJobStatusForAllJobs()
    {
        $statusResult = $this->cronManager->getJobStatus();
        
        $this->assertTrue($statusResult['success']);
        $this->assertArrayHasKey('jobs', $statusResult);
        $this->assertArrayHasKey('summary', $statusResult);
        $this->assertIsArray($statusResult['jobs']);
    }

    public function testGetJobStatusForNonExistentJob()
    {
        $statusResult = $this->cronManager->getJobStatus(99999);
        
        $this->assertFalse($statusResult['success']);
        $this->assertEquals('Job not found', $statusResult['error']);
    }

    public function testToggleJobEnable()
    {
        // Register a test job
        $result = $this->cronManager->registerJob($this->testJobConfig);
        $this->testJobId = $result['job_id'];

        // Disable the job
        $disableResult = $this->cronManager->toggleJob($this->testJobId, false);
        
        $this->assertTrue($disableResult['success']);
        $this->assertEquals('Job disabled', $disableResult['message']);
        $this->assertFalse($disableResult['enabled']);

        // Enable the job
        $enableResult = $this->cronManager->toggleJob($this->testJobId, true);
        
        $this->assertTrue($enableResult['success']);
        $this->assertEquals('Job enabled', $enableResult['message']);
        $this->assertTrue($enableResult['enabled']);
        $this->assertArrayHasKey('next_run', $enableResult);
    }

    public function testToggleNonExistentJob()
    {
        $result = $this->cronManager->toggleJob(99999, true);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Job not found', $result['error']);
    }

    public function testDeleteJobSuccess()
    {
        // Register a test job
        $result = $this->cronManager->registerJob($this->testJobConfig);
        $jobId = $result['job_id'];

        // Delete the job
        $deleteResult = $this->cronManager->deleteJob($jobId);
        
        $this->assertTrue($deleteResult['success']);
        $this->assertEquals('Job deleted successfully', $deleteResult['message']);
        $this->assertEquals($jobId, $deleteResult['job_id']);

        // Verify job is deleted
        $statusResult = $this->cronManager->getJobStatus($jobId);
        $this->assertFalse($statusResult['success']);
    }

    public function testDeleteNonExistentJob()
    {
        $result = $this->cronManager->deleteJob(99999);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Job not found', $result['error']);
    }

    public function testGetJobHistory()
    {
        // Register a test job
        $result = $this->cronManager->registerJob($this->testJobConfig);
        $this->testJobId = $result['job_id'];

        // Get job history
        $historyResult = $this->cronManager->getJobHistory($this->testJobId);
        
        $this->assertTrue($historyResult['success']);
        $this->assertArrayHasKey('job_id', $historyResult);
        $this->assertArrayHasKey('job_name', $historyResult);
        $this->assertArrayHasKey('executions', $historyResult);
        $this->assertArrayHasKey('statistics', $historyResult);
        $this->assertEquals($this->testJobId, $historyResult['job_id']);
        $this->assertEquals($this->testJobConfig['name'], $historyResult['job_name']);
    }

    public function testGetJobHistoryForNonExistentJob()
    {
        $result = $this->cronManager->getJobHistory(99999);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Job not found', $result['error']);
    }

    public function testJobConfigValidation()
    {
        // Test with invalid max_execution_time
        $invalidConfig = $this->testJobConfig;
        $invalidConfig['max_execution_time'] = -1;

        $result = $this->cronManager->registerJob($invalidConfig);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('max_execution_time', $result['errors']);
    }

    public function testJobConfigValidationWithInvalidRetryAttempts()
    {
        // Test with invalid retry_attempts
        $invalidConfig = $this->testJobConfig;
        $invalidConfig['retry_attempts'] = -1;

        $result = $this->cronManager->registerJob($invalidConfig);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('retry_attempts', $result['errors']);
    }

    public function testJobWithParameters()
    {
        $jobWithParams = $this->testJobConfig;
        $jobWithParams['name'] = 'test_job_with_params';
        $jobWithParams['parameters'] = [
            'vendor_id' => 1,
            'threshold' => 10
        ];

        $result = $this->cronManager->registerJob($jobWithParams);
        
        $this->assertTrue($result['success']);
        $this->testJobId = $result['job_id'];

        // Verify parameters are stored
        $statusResult = $this->cronManager->getJobStatus($this->testJobId);
        $this->assertTrue($statusResult['success']);
        $this->assertNotNull($statusResult['job']['parameters']);
    }

    public function testCommonCronExpressions()
    {
        $cronExpressions = [
            '* * * * *',     // Every minute
            '0 * * * *',     // Every hour
            '0 0 * * *',     // Daily at midnight
            '0 2 * * *',     // Daily at 2 AM
            '0 0 * * 0',     // Weekly on Sunday
            '0 0 1 * *'      // Monthly on 1st
        ];

        foreach ($cronExpressions as $expression) {
            $jobConfig = $this->testJobConfig;
            $jobConfig['name'] = 'test_cron_' . md5($expression);
            $jobConfig['schedule'] = $expression;

            $result = $this->cronManager->registerJob($jobConfig);
            $this->assertTrue($result['success'], "Failed to register job with cron expression: {$expression}");

            // Clean up
            if ($result['success']) {
                $this->cronManager->deleteJob($result['job_id']);
            }
        }
    }

    public function testJobPriority()
    {
        // Register jobs with different priorities
        $highPriorityJob = $this->testJobConfig;
        $highPriorityJob['name'] = 'high_priority_job';
        $highPriorityJob['priority'] = 10;

        $lowPriorityJob = $this->testJobConfig;
        $lowPriorityJob['name'] = 'low_priority_job';
        $lowPriorityJob['priority'] = 1;

        $result1 = $this->cronManager->registerJob($highPriorityJob);
        $result2 = $this->cronManager->registerJob($lowPriorityJob);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);

        // Clean up
        $this->cronManager->deleteJob($result1['job_id']);
        $this->cronManager->deleteJob($result2['job_id']);
    }

    public function testJobExecutionWithDifferentCommands()
    {
        $commands = [
            'generate_subscription_orders',
            'cleanup_expired_products',
            'send_low_stock_alerts',
            'process_delivery_failures',
            'generate_vendor_reports'
        ];

        foreach ($commands as $command) {
            $jobConfig = $this->testJobConfig;
            $jobConfig['name'] = 'test_' . $command;
            $jobConfig['command'] = $command;

            $result = $this->cronManager->registerJob($jobConfig);
            $this->assertTrue($result['success'], "Failed to register job with command: {$command}");

            if ($result['success']) {
                // Clean up
                $this->cronManager->deleteJob($result['job_id']);
            }
        }
    }

    public function testJobStatistics()
    {
        // Register a test job
        $result = $this->cronManager->registerJob($this->testJobConfig);
        $this->testJobId = $result['job_id'];

        // Get job status to check statistics
        $statusResult = $this->cronManager->getJobStatus($this->testJobId);
        
        $this->assertTrue($statusResult['success']);
        $this->assertArrayHasKey('statistics', $statusResult);
        
        $stats = $statusResult['statistics'];
        $this->assertArrayHasKey('total_executions', $stats);
        $this->assertArrayHasKey('successful_executions', $stats);
        $this->assertArrayHasKey('failed_executions', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('avg_execution_time_ms', $stats);
    }

    public function testJobsSummary()
    {
        $statusResult = $this->cronManager->getJobStatus();
        
        $this->assertTrue($statusResult['success']);
        $this->assertArrayHasKey('summary', $statusResult);
        
        $summary = $statusResult['summary'];
        $this->assertArrayHasKey('total_jobs', $summary);
        $this->assertArrayHasKey('enabled_jobs', $summary);
        $this->assertArrayHasKey('running_jobs', $summary);
        $this->assertArrayHasKey('pending_jobs', $summary);
    }
}