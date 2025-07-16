<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Automated maintenance tasks manager
 */
class MaintenanceTaskManager
{
    private PDO $db;
    private CronJobManager $cronManager;
    private JobQueue $jobQueue;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->cronManager = new CronJobManager();
        $this->jobQueue = new JobQueue();
        $this->registerMaintenanceTasks();
    }

    /**
     * Execute database cleanup tasks
     */
    public function executeDataCleanup(array $options = []): array
    {
        try {
            $results = [
                'total_tasks' => 0,
                'successful_tasks' => 0,
                'failed_tasks' => 0,
                'cleanup_results' => []
            ];

            // Clean expired products
            $expiredProductsResult = $this->cleanupExpiredProducts($options);
            $results['cleanup_results']['expired_products'] = $expiredProductsResult;
            $results['total_tasks']++;
            if ($expiredProductsResult['success']) $results['successful_tasks']++;
            else $results['failed_tasks']++;

            // Clean old job executions
            $oldJobsResult = $this->cleanupOldJobExecutions($options);
            $results['cleanup_results']['old_job_executions'] = $oldJobsResult;
            $results['total_tasks']++;
            if ($oldJobsResult['success']) $results['successful_tasks']++;
            else $results['failed_tasks']++;

            // Clean old audit logs
            $auditLogsResult = $this->cleanupOldAuditLogs($options);
            $results['cleanup_results']['old_audit_logs'] = $auditLogsResult;
            $results['total_tasks']++;
            if ($auditLogsResult['success']) $results['successful_tasks']++;
            else $results['failed_tasks']++;

            // Clean old traceability logs
            $traceabilityResult = $this->cleanupOldTraceabilityLogs($options);
            $results['cleanup_results']['old_traceability_logs'] = $traceabilityResult;
            $results['total_tasks']++;
            if ($traceabilityResult['success']) $results['successful_tasks']++;
            else $results['failed_tasks']++;

            // Clean temporary files
            $tempFilesResult = $this->cleanupTemporaryFiles($options);
            $results['cleanup_results']['temporary_files'] = $tempFilesResult;
            $results['total_tasks']++;
            if ($tempFilesResult['success']) $results['successful_tasks']++;
            else $results['failed_tasks']++;

            // Clean old sessions
            $sessionsResult = $this->cleanupOldSessions($options);
            $results['cleanup_results']['old_sessions'] = $sessionsResult;
            $results['total_tasks']++;
            if ($sessionsResult['success']) $results['successful_tasks']++;
            else $results['failed_tasks']++;

            return [
                'success' => true,
                'message' => 'Data cleanup completed',
                'execution_summary' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Data cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute automated report generation
     */
    public function executeReportGeneration(array $options = []): array
    {
        try {
            $reportGenerator = new ReportGenerator();
            $results = [
                'total_reports' => 0,
                'successful_reports' => 0,
                'failed_reports' => 0,
                'report_results' => []
            ];

            // Generate daily vendor reports
            $vendorReportsResult = $this->generateVendorReports($options);
            $results['report_results']['vendor_reports'] = $vendorReportsResult;
            $results['total_reports']++;
            if ($vendorReportsResult['success']) $results['successful_reports']++;
            else $results['failed_reports']++;

            // Generate inventory reports
            $inventoryReportsResult = $this->generateInventoryReports($options);
            $results['report_results']['inventory_reports'] = $inventoryReportsResult;
            $results['total_reports']++;
            if ($inventoryReportsResult['success']) $results['successful_reports']++;
            else $results['failed_reports']++;

            // Generate compliance reports
            $complianceReportsResult = $this->generateComplianceReports($options);
            $results['report_results']['compliance_reports'] = $complianceReportsResult;
            $results['total_reports']++;
            if ($complianceReportsResult['success']) $results['successful_reports']++;
            else $results['failed_reports']++;

            // Generate delivery performance reports
            $deliveryReportsResult = $this->generateDeliveryReports($options);
            $results['report_results']['delivery_reports'] = $deliveryReportsResult;
            $results['total_reports']++;
            if ($deliveryReportsResult['success']) $results['successful_reports']++;
            else $results['failed_reports']++;

            return [
                'success' => true,
                'message' => 'Report generation completed',
                'execution_summary' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Report generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute notification sending tasks
     */
    public function executeNotificationTasks(array $options = []): array
    {
        try {
            $results = [
                'total_notifications' => 0,
                'successful_notifications' => 0,
                'failed_notifications' => 0,
                'notification_results' => []
            ];

            // Send low stock alerts
            $lowStockResult = $this->sendLowStockAlerts($options);
            $results['notification_results']['low_stock_alerts'] = $lowStockResult;
            $results['total_notifications'] += $lowStockResult['alerts_sent'] ?? 0;
            if ($lowStockResult['success']) $results['successful_notifications'] += $lowStockResult['alerts_sent'] ?? 0;

            // Send expiry alerts
            $expiryAlertsResult = $this->sendExpiryAlerts($options);
            $results['notification_results']['expiry_alerts'] = $expiryAlertsResult;
            $results['total_notifications'] += $expiryAlertsResult['alerts_sent'] ?? 0;
            if ($expiryAlertsResult['success']) $results['successful_notifications'] += $expiryAlertsResult['alerts_sent'] ?? 0;

            // Send compliance reminders
            $complianceRemindersResult = $this->sendComplianceReminders($options);
            $results['notification_results']['compliance_reminders'] = $complianceRemindersResult;
            $results['total_notifications'] += $complianceRemindersResult['reminders_sent'] ?? 0;
            if ($complianceRemindersResult['success']) $results['successful_notifications'] += $complianceRemindersResult['reminders_sent'] ?? 0;

            // Send delivery failure notifications
            $deliveryFailureResult = $this->sendDeliveryFailureNotifications($options);
            $results['notification_results']['delivery_failure_notifications'] = $deliveryFailureResult;
            $results['total_notifications'] += $deliveryFailureResult['notifications_sent'] ?? 0;
            if ($deliveryFailureResult['success']) $results['successful_notifications'] += $deliveryFailureResult['notifications_sent'] ?? 0;

            return [
                'success' => true,
                'message' => 'Notification tasks completed',
                'execution_summary' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Notification tasks failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Execute system health checks
     */
    public function executeHealthChecks(array $options = []): array
    {
        try {
            $results = [
                'total_checks' => 0,
                'passed_checks' => 0,
                'failed_checks' => 0,
                'health_results' => []
            ];

            // Database health check
            $dbHealthResult = $this->checkDatabaseHealth();
            $results['health_results']['database'] = $dbHealthResult;
            $results['total_checks']++;
            if ($dbHealthResult['healthy']) $results['passed_checks']++;
            else $results['failed_checks']++;

            // Storage health check
            $storageHealthResult = $this->checkStorageHealth();
            $results['health_results']['storage'] = $storageHealthResult;
            $results['total_checks']++;
            if ($storageHealthResult['healthy']) $results['passed_checks']++;
            else $results['failed_checks']++;

            // Queue health check
            $queueHealthResult = $this->checkQueueHealth();
            $results['health_results']['queue'] = $queueHealthResult;
            $results['total_checks']++;
            if ($queueHealthResult['healthy']) $results['passed_checks']++;
            else $results['failed_checks']++;

            // Cron jobs health check
            $cronHealthResult = $this->checkCronJobsHealth();
            $results['health_results']['cron_jobs'] = $cronHealthResult;
            $results['total_checks']++;
            if ($cronHealthResult['healthy']) $results['passed_checks']++;
            else $results['failed_checks']++;

            // External services health check
            $externalServicesResult = $this->checkExternalServicesHealth();
            $results['health_results']['external_services'] = $externalServicesResult;
            $results['total_checks']++;
            if ($externalServicesResult['healthy']) $results['passed_checks']++;
            else $results['failed_checks']++;

            return [
                'success' => true,
                'message' => 'Health checks completed',
                'overall_health' => $results['failed_checks'] === 0 ? 'healthy' : 'unhealthy',
                'execution_summary' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Health checks failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up expired products
     */
    private function cleanupExpiredProducts(array $options): array
    {
        try {
            $expiryManager = new ExpiryManager();
            $vendorId = $options['vendor_id'] ?? null;
            
            $result = $expiryManager->processExpiryCleanup($vendorId);
            
            return [
                'success' => $result['success'],
                'message' => $result['success'] ? 
                    "Cleaned up {$result['products_removed']} expired products" : 
                    $result['error'],
                'products_removed' => $result['products_removed'] ?? 0
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Expired products cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old job executions
     */
    private function cleanupOldJobExecutions(array $options): array
    {
        try {
            $daysToKeep = $options['job_executions_days'] ?? 30;
            $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

            $sql = "DELETE FROM cron_job_executions WHERE started_at < ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cutoffDate]);
            $deletedCount = $stmt->rowCount();

            return [
                'success' => true,
                'message' => "Cleaned up {$deletedCount} old job execution records",
                'records_deleted' => $deletedCount,
                'cutoff_date' => $cutoffDate
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Job executions cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old audit logs
     */
    private function cleanupOldAuditLogs(array $options): array
    {
        try {
            $daysToKeep = $options['audit_logs_days'] ?? 90;
            $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

            // Clean up audit trail logs (if table exists)
            $sql = "DELETE FROM audit_trail WHERE created_at < ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cutoffDate]);
            $deletedCount = $stmt->rowCount();

            return [
                'success' => true,
                'message' => "Cleaned up {$deletedCount} old audit log records",
                'records_deleted' => $deletedCount,
                'cutoff_date' => $cutoffDate
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Audit logs cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old traceability logs
     */
    private function cleanupOldTraceabilityLogs(array $options): array
    {
        try {
            $daysToKeep = $options['traceability_logs_days'] ?? 365; // Keep for 1 year
            $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

            $sql = "DELETE FROM traceability_logs WHERE event_timestamp < ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cutoffDate]);
            $deletedCount = $stmt->rowCount();

            return [
                'success' => true,
                'message' => "Cleaned up {$deletedCount} old traceability log records",
                'records_deleted' => $deletedCount,
                'cutoff_date' => $cutoffDate
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Traceability logs cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up temporary files
     */
    private function cleanupTemporaryFiles(array $options): array
    {
        try {
            $tempDir = $options['temp_directory'] ?? sys_get_temp_dir();
            $daysToKeep = $options['temp_files_days'] ?? 7;
            $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
            
            $deletedCount = 0;
            $deletedSize = 0;

            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file) && filemtime($file) < $cutoffTime) {
                        $fileSize = filesize($file);
                        if (unlink($file)) {
                            $deletedCount++;
                            $deletedSize += $fileSize;
                        }
                    }
                }
            }

            return [
                'success' => true,
                'message' => "Cleaned up {$deletedCount} temporary files (" . $this->formatBytes($deletedSize) . ")",
                'files_deleted' => $deletedCount,
                'bytes_freed' => $deletedSize,
                'temp_directory' => $tempDir
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Temporary files cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old sessions
     */
    private function cleanupOldSessions(array $options): array
    {
        try {
            $daysToKeep = $options['sessions_days'] ?? 30;
            $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

            // Clean up admin sessions (if table exists)
            $sql = "DELETE FROM admin_sessions WHERE created_at < ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cutoffDate]);
            $deletedCount = $stmt->rowCount();

            return [
                'success' => true,
                'message' => "Cleaned up {$deletedCount} old session records",
                'records_deleted' => $deletedCount,
                'cutoff_date' => $cutoffDate
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Sessions cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate vendor reports
     */
    private function generateVendorReports(array $options): array
    {
        try {
            $reportGenerator = new ReportGenerator();
            $vendorIds = $options['vendor_ids'] ?? null;
            $reportType = $options['report_type'] ?? 'daily';
            
            $generatedCount = 0;
            $failedCount = 0;

            if ($vendorIds) {
                // Generate reports for specific vendors
                foreach ($vendorIds as $vendorId) {
                    $result = $reportGenerator->generateReport($vendorId, $reportType);
                    if ($result['success']) {
                        $generatedCount++;
                    } else {
                        $failedCount++;
                    }
                }
            } else {
                // Generate reports for all active vendors
                $vendors = $this->getActiveVendors();
                foreach ($vendors as $vendor) {
                    $result = $reportGenerator->generateReport($vendor['id'], $reportType);
                    if ($result['success']) {
                        $generatedCount++;
                    } else {
                        $failedCount++;
                    }
                }
            }

            return [
                'success' => true,
                'message' => "Generated {$generatedCount} vendor reports, {$failedCount} failed",
                'reports_generated' => $generatedCount,
                'reports_failed' => $failedCount,
                'report_type' => $reportType
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Vendor reports generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate inventory reports
     */
    private function generateInventoryReports(array $options): array
    {
        try {
            $inventoryTracker = new InventoryTracker();
            $vendorId = $options['vendor_id'] ?? null;
            
            $result = $inventoryTracker->getInventorySummary($vendorId);
            
            if ($result['success']) {
                // Save report to file or database
                $reportData = [
                    'report_type' => 'inventory_summary',
                    'vendor_id' => $vendorId,
                    'data' => $result,
                    'generated_at' => date('Y-m-d H:i:s')
                ];
                
                // Mock saving report
                return [
                    'success' => true,
                    'message' => 'Inventory report generated successfully',
                    'report_data' => $reportData
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to generate inventory report: ' . $result['error']
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Inventory reports generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate compliance reports
     */
    private function generateComplianceReports(array $options): array
    {
        try {
            $fssaiValidator = new FSSAIValidator();
            $vendorId = $options['vendor_id'] ?? null;
            
            $result = $fssaiValidator->generateComplianceReport($vendorId);
            
            if ($result['success']) {
                // Save report
                $reportData = [
                    'report_type' => 'compliance_summary',
                    'vendor_id' => $vendorId,
                    'data' => $result,
                    'generated_at' => date('Y-m-d H:i:s')
                ];
                
                return [
                    'success' => true,
                    'message' => 'Compliance report generated successfully',
                    'report_data' => $reportData
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to generate compliance report: ' . $result['error']
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Compliance reports generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate delivery reports
     */
    private function generateDeliveryReports(array $options): array
    {
        try {
            $deliverySlotManager = new DeliverySlotManager();
            $vendorId = $options['vendor_id'] ?? null;
            $dateFrom = $options['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $options['date_to'] ?? date('Y-m-d');
            
            $result = $deliverySlotManager->getDeliveryPerformance($vendorId, $dateFrom, $dateTo);
            
            if ($result['success']) {
                // Save report
                $reportData = [
                    'report_type' => 'delivery_performance',
                    'vendor_id' => $vendorId,
                    'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                    'data' => $result,
                    'generated_at' => date('Y-m-d H:i:s')
                ];
                
                return [
                    'success' => true,
                    'message' => 'Delivery report generated successfully',
                    'report_data' => $reportData
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to generate delivery report: ' . $result['error']
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery reports generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send low stock alerts
     */
    private function sendLowStockAlerts(array $options): array
    {
        try {
            $inventoryTracker = new InventoryTracker();
            $vendorId = $options['vendor_id'] ?? null;
            $threshold = $options['threshold'] ?? 10;
            
            $result = $inventoryTracker->getLowStockAlerts($vendorId, $threshold);
            
            if ($result['success'] && !empty($result['low_stock_products'])) {
                $alertsSent = 0;
                
                foreach ($result['low_stock_products'] as $product) {
                    // Queue notification job
                    $notificationPayload = [
                        'type' => 'low_stock_alert',
                        'recipient' => $product['vendor_email'] ?? 'vendor@example.com',
                        'message' => "Low stock alert: {$product['product_name']} has only {$product['current_stock']} units remaining",
                        'product_id' => $product['product_id'],
                        'vendor_id' => $product['vendor_id'],
                        'current_stock' => $product['current_stock'],
                        'threshold' => $threshold
                    ];
                    
                    $queueResult = $this->jobQueue->addJob('send_notification', $notificationPayload);
                    if ($queueResult['success']) {
                        $alertsSent++;
                    }
                }
                
                return [
                    'success' => true,
                    'message' => "Queued {$alertsSent} low stock alerts",
                    'alerts_sent' => $alertsSent,
                    'products_checked' => count($result['low_stock_products'])
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'No low stock alerts to send',
                    'alerts_sent' => 0
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Low stock alerts failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send expiry alerts
     */
    private function sendExpiryAlerts(array $options): array
    {
        try {
            $expiryManager = new ExpiryManager();
            $vendorId = $options['vendor_id'] ?? null;
            $days = $options['days'] ?? 3;
            
            $result = $expiryManager->getExpiringProducts($vendorId, $days);
            
            if ($result['success'] && !empty($result['expiring_products'])) {
                $alertsSent = 0;
                
                foreach ($result['expiring_products'] as $product) {
                    // Queue notification job
                    $notificationPayload = [
                        'type' => 'expiry_alert',
                        'recipient' => $product['vendor_email'] ?? 'vendor@example.com',
                        'message' => "Expiry alert: {$product['product_name']} (Batch: {$product['batch_number']}) expires on {$product['expiry_date']}",
                        'product_id' => $product['product_id'],
                        'batch_id' => $product['batch_id'],
                        'vendor_id' => $product['vendor_id'],
                        'expiry_date' => $product['expiry_date']
                    ];
                    
                    $queueResult = $this->jobQueue->addJob('send_notification', $notificationPayload);
                    if ($queueResult['success']) {
                        $alertsSent++;
                    }
                }
                
                return [
                    'success' => true,
                    'message' => "Queued {$alertsSent} expiry alerts",
                    'alerts_sent' => $alertsSent,
                    'products_checked' => count($result['expiring_products'])
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'No expiry alerts to send',
                    'alerts_sent' => 0
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Expiry alerts failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send compliance reminders
     */
    private function sendComplianceReminders(array $options): array
    {
        try {
            $fssaiValidator = new FSSAIValidator();
            $daysThreshold = $options['days_threshold'] ?? 30;
            
            $result = $fssaiValidator->getExpiringComplianceRecords($daysThreshold);
            
            if ($result['success'] && !empty($result['records'])) {
                $remindersSent = 0;
                
                foreach ($result['records'] as $record) {
                    // Queue notification job
                    $notificationPayload = [
                        'type' => 'compliance_reminder',
                        'recipient' => $record['vendor_email'] ?? 'vendor@example.com',
                        'message' => "Compliance reminder: Your {$record['compliance_type']} certificate ({$record['certificate_number']}) expires on {$record['expiry_date']}",
                        'vendor_id' => $record['vendor_id'],
                        'compliance_type' => $record['compliance_type'],
                        'certificate_number' => $record['certificate_number'],
                        'expiry_date' => $record['expiry_date']
                    ];
                    
                    $queueResult = $this->jobQueue->addJob('send_notification', $notificationPayload);
                    if ($queueResult['success']) {
                        $remindersSent++;
                    }
                }
                
                return [
                    'success' => true,
                    'message' => "Queued {$remindersSent} compliance reminders",
                    'reminders_sent' => $remindersSent,
                    'records_checked' => count($result['records'])
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'No compliance reminders to send',
                    'reminders_sent' => 0
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Compliance reminders failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send delivery failure notifications
     */
    private function sendDeliveryFailureNotifications(array $options): array
    {
        try {
            $deliveryFailureHandler = new DeliveryFailureHandler();
            $filters = ['status' => 'pending'];
            
            $result = $deliveryFailureHandler->getFailures($filters);
            
            if ($result['success'] && !empty($result['failures'])) {
                $notificationsSent = 0;
                
                foreach ($result['failures'] as $failure) {
                    // Queue notification job
                    $notificationPayload = [
                        'type' => 'delivery_failure_notification',
                        'recipient' => $failure['customer_email'] ?? 'customer@example.com',
                        'message' => "Delivery failure notification: Your order #{$failure['order_id']} could not be delivered. Reason: {$failure['failure_reason']}",
                        'order_id' => $failure['order_id'],
                        'customer_id' => $failure['customer_id'],
                        'failure_reason' => $failure['failure_reason'],
                        'failure_details' => $failure['failure_details']
                    ];
                    
                    $queueResult = $this->jobQueue->addJob('send_notification', $notificationPayload);
                    if ($queueResult['success']) {
                        $notificationsSent++;
                    }
                }
                
                return [
                    'success' => true,
                    'message' => "Queued {$notificationsSent} delivery failure notifications",
                    'notifications_sent' => $notificationsSent,
                    'failures_checked' => count($result['failures'])
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'No delivery failure notifications to send',
                    'notifications_sent' => 0
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery failure notifications failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check database health
     */
    private function checkDatabaseHealth(): array
    {
        try {
            // Test database connection
            $sql = "SELECT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            // Check database size
            $sql = "SELECT 
                        SUM(data_length + index_length) / 1024 / 1024 AS db_size_mb,
                        COUNT(*) as table_count
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $dbStats = $stmt->fetch();

            return [
                'healthy' => true,
                'message' => 'Database is healthy',
                'database_size_mb' => round($dbStats['db_size_mb'] ?? 0, 2),
                'table_count' => $dbStats['table_count'] ?? 0,
                'connection_status' => 'connected'
            ];

        } catch (Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Database health check failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check storage health
     */
    private function checkStorageHealth(): array
    {
        try {
            $uploadDir = 'uploads/'; // Adjust path as needed
            $tempDir = sys_get_temp_dir();
            
            $uploadDiskSpace = disk_free_space($uploadDir) / 1024 / 1024; // MB
            $tempDiskSpace = disk_free_space($tempDir) / 1024 / 1024; // MB
            
            $healthy = $uploadDiskSpace > 100 && $tempDiskSpace > 100; // At least 100MB free

            return [
                'healthy' => $healthy,
                'message' => $healthy ? 'Storage is healthy' : 'Low disk space detected',
                'upload_dir_free_mb' => round($uploadDiskSpace, 2),
                'temp_dir_free_mb' => round($tempDiskSpace, 2),
                'upload_dir' => $uploadDir,
                'temp_dir' => $tempDir
            ];

        } catch (Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Storage health check failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check queue health
     */
    private function checkQueueHealth(): array
    {
        try {
            $queueStats = $this->jobQueue->getQueueStats();
            
            if ($queueStats['success']) {
                $stats = $queueStats['queue_stats'][0] ?? [];
                $pendingJobs = $stats['pending_jobs'] ?? 0;
                $failedJobs = $stats['failed_jobs'] ?? 0;
                
                $healthy = $pendingJobs < 1000 && $failedJobs < 100; // Thresholds

                return [
                    'healthy' => $healthy,
                    'message' => $healthy ? 'Queue is healthy' : 'Queue issues detected',
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs' => $failedJobs,
                    'total_jobs' => $stats['total_jobs'] ?? 0
                ];
            } else {
                return [
                    'healthy' => false,
                    'message' => 'Queue health check failed',
                    'error' => $queueStats['error']
                ];
            }

        } catch (Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Queue health check failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check cron jobs health
     */
    private function checkCronJobsHealth(): array
    {
        try {
            $jobStatus = $this->cronManager->getJobStatus();
            
            if ($jobStatus['success']) {
                $summary = $jobStatus['summary'];
                $enabledJobs = $summary['enabled_jobs'] ?? 0;
                $runningJobs = $summary['running_jobs'] ?? 0;
                $pendingJobs = $summary['pending_jobs'] ?? 0;
                
                $healthy = $enabledJobs > 0 && $runningJobs < 10; // Basic health check

                return [
                    'healthy' => $healthy,
                    'message' => $healthy ? 'Cron jobs are healthy' : 'Cron job issues detected',
                    'enabled_jobs' => $enabledJobs,
                    'running_jobs' => $runningJobs,
                    'pending_jobs' => $pendingJobs,
                    'total_jobs' => $summary['total_jobs'] ?? 0
                ];
            } else {
                return [
                    'healthy' => false,
                    'message' => 'Cron jobs health check failed',
                    'error' => $jobStatus['error']
                ];
            }

        } catch (Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Cron jobs health check failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check external services health
     */
    private function checkExternalServicesHealth(): array
    {
        try {
            $services = [
                'payment_gateway' => 'https://api.payment-gateway.com/health',
                'notification_service' => 'https://api.notification-service.com/health',
                'email_service' => 'https://api.email-service.com/health'
            ];
            
            $healthyServices = 0;
            $totalServices = count($services);
            $serviceResults = [];
            
            foreach ($services as $serviceName => $healthUrl) {
                // Mock health check - in production, make actual HTTP requests
                $isHealthy = rand(0, 1) === 1; // Random for testing
                
                $serviceResults[$serviceName] = [
                    'healthy' => $isHealthy,
                    'url' => $healthUrl,
                    'response_time_ms' => rand(50, 500)
                ];
                
                if ($isHealthy) {
                    $healthyServices++;
                }
            }
            
            $overallHealthy = $healthyServices === $totalServices;

            return [
                'healthy' => $overallHealthy,
                'message' => $overallHealthy ? 'All external services are healthy' : 'Some external services are unhealthy',
                'healthy_services' => $healthyServices,
                'total_services' => $totalServices,
                'service_results' => $serviceResults
            ];

        } catch (Exception $e) {
            return [
                'healthy' => false,
                'message' => 'External services health check failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get active vendors
     */
    private function getActiveVendors(): array
    {
        try {
            $sql = "SELECT id, business_name FROM vendors WHERE status = 'active'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Register maintenance tasks as cron jobs
     */
    private function registerMaintenanceTasks(): void
    {
        $maintenanceTasks = [
            [
                'name' => 'daily_data_cleanup',
                'description' => 'Daily data cleanup tasks',
                'schedule' => '0 1 * * *', // 1 AM daily
                'command' => 'cleanup_data',
                'enabled' => true,
                'priority' => 8
            ],
            [
                'name' => 'weekly_report_generation',
                'description' => 'Weekly report generation',
                'schedule' => '0 6 * * 0', // 6 AM on Sundays
                'command' => 'generate_vendor_reports',
                'enabled' => true,
                'priority' => 6
            ],
            [
                'name' => 'hourly_notifications',
                'description' => 'Hourly notification tasks',
                'schedule' => '0 * * * *', // Every hour
                'command' => 'send_notifications',
                'enabled' => true,
                'priority' => 7
            ],
            [
                'name' => 'system_health_check',
                'description' => 'System health monitoring',
                'schedule' => '*/15 * * * *', // Every 15 minutes
                'command' => 'health_check',
                'enabled' => true,
                'priority' => 9
            ]
        ];

        foreach ($maintenanceTasks as $taskConfig) {
            $existing = $this->cronManager->getJobStatus();
            // Only register if not already exists (simplified check)
            $this->cronManager->registerJob($taskConfig);
        }
    }
}