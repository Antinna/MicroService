<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Repositories\InventoryBatchRepository;
use Antinna\MultiVendor\Repositories\ProductRepository;
use Antinna\MultiVendor\Services\BatchTraceabilityService;
use Exception;

/**
 * Expiry management service for automated expired product removal and cleanup
 */
class ExpiryManager
{
    private InventoryBatchRepository $batchRepository;
    private ProductRepository $productRepository;
    private BatchTraceabilityService $traceabilityService;

    public function __construct()
    {
        $this->batchRepository = new InventoryBatchRepository();
        $this->productRepository = new ProductRepository();
        $this->traceabilityService = new BatchTraceabilityService();
    }

    /**
     * Process expired batches - main cleanup method
     */
    public function processExpiredBatches(): array
    {
        try {
            $expiredBatches = $this->batchRepository->findExpired();
            $processedCount = 0;
            $errors = [];

            foreach ($expiredBatches as $batch) {
                try {
                    $result = $this->handleExpiredBatch($batch);
                    if ($result['success']) {
                        $processedCount++;
                    } else {
                        $errors[] = [
                            'batch_id' => $batch['id'],
                            'batch_number' => $batch['batch_number'],
                            'error' => $result['error']
                        ];
                    }
                } catch (Exception $e) {
                    $errors[] = [
                        'batch_id' => $batch['id'],
                        'batch_number' => $batch['batch_number'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return [
                'success' => true,
                'total_expired' => count($expiredBatches),
                'processed_count' => $processedCount,
                'errors' => $errors,
                'message' => "Processed {$processedCount} expired batches"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Expired batch processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handle individual expired batch
     */
    private function handleExpiredBatch(array $batch): array
    {
        try {
            // Mark batch as expired by setting quantity to 0
            $updateData = [
                'quantity_available' => 0,
                'quantity_reserved' => 0,
                'notes' => ($batch['notes'] ?? '') . ' [EXPIRED: ' . date('Y-m-d H:i:s') . ']'
            ];

            $success = $this->batchRepository->update($batch['id'], $updateData);

            if ($success) {
                // Log expiry event in traceability
                $this->traceabilityService->logEvent($batch['id'], 'expired', [
                    'description' => 'Batch expired and removed from available inventory',
                    'notes' => 'Automated expiry processing',
                    'metadata' => [
                        'expiry_date' => $batch['expiry_date'],
                        'original_quantity' => $batch['quantity_available'],
                        'processed_at' => date('Y-m-d H:i:s')
                    ]
                ]);

                return [
                    'success' => true,
                    'message' => 'Batch marked as expired'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update expired batch'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error handling expired batch: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get batches expiring soon for alerts
     */
    public function getBatchesExpiringSoon(int $days = 3): array
    {
        try {
            $expiringBatches = $this->batchRepository->findExpiringSoon($days);

            // Group by vendor for easier notification processing
            $vendorGroups = [];
            foreach ($expiringBatches as $batch) {
                $vendorName = $batch['vendor_name'];
                if (!isset($vendorGroups[$vendorName])) {
                    $vendorGroups[$vendorName] = [
                        'vendor_name' => $vendorName,
                        'batches' => []
                    ];
                }
                $vendorGroups[$vendorName]['batches'][] = $batch;
            }

            return [
                'success' => true,
                'days_ahead' => $days,
                'total_batches' => count($expiringBatches),
                'vendor_groups' => array_values($vendorGroups),
                'batches' => $expiringBatches
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving expiring batches: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update product status based on inventory availability
     */
    public function updateProductAvailability(): array
    {
        try {
            $products = $this->productRepository->findAll(['status' => 'active']);
            $updatedCount = 0;
            $errors = [];

            foreach ($products as $product) {
                try {
                    $availableBatches = $this->batchRepository->findAvailableForProduct($product['id']);
                    $totalAvailable = 0;

                    foreach ($availableBatches as $batch) {
                        $totalAvailable += ($batch['quantity_available'] - $batch['quantity_reserved']);
                    }

                    // Update product status based on availability
                    $newStatus = $totalAvailable > 0 ? 'active' : 'out_of_stock';

                    if ($product['status'] !== $newStatus) {
                        $success = $this->productRepository->update($product['id'], ['status' => $newStatus]);
                        if ($success) {
                            $updatedCount++;
                        } else {
                            $errors[] = [
                                'product_id' => $product['id'],
                                'product_name' => $product['name'],
                                'error' => 'Failed to update product status'
                            ];
                        }
                    }

                } catch (Exception $e) {
                    $errors[] = [
                        'product_id' => $product['id'],
                        'product_name' => $product['name'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return [
                'success' => true,
                'total_products' => count($products),
                'updated_count' => $updatedCount,
                'errors' => $errors,
                'message' => "Updated availability for {$updatedCount} products"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product availability update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate expiry alerts for vendors
     */
    public function generateExpiryAlerts(int $days = 3): array
    {
        try {
            $expiringResult = $this->getBatchesExpiringSoon($days);

            if (!$expiringResult['success']) {
                return $expiringResult;
            }

            $alerts = [];
            foreach ($expiringResult['vendor_groups'] as $vendorGroup) {
                $batchCount = count($vendorGroup['batches']);
                $totalQuantity = array_sum(array_column($vendorGroup['batches'], 'quantity_available'));

                $alerts[] = [
                    'vendor_name' => $vendorGroup['vendor_name'],
                    'alert_type' => 'expiry_warning',
                    'batch_count' => $batchCount,
                    'total_quantity' => $totalQuantity,
                    'days_until_expiry' => $days,
                    'batches' => $vendorGroup['batches'],
                    'message' => "You have {$batchCount} batches expiring within {$days} days",
                    'priority' => $days <= 1 ? 'high' : ($days <= 2 ? 'medium' : 'low'),
                    'generated_at' => date('Y-m-d H:i:s')
                ];
            }

            return [
                'success' => true,
                'alert_count' => count($alerts),
                'alerts' => $alerts
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Alert generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old expired batches (remove from database)
     */
    public function cleanupOldExpiredBatches(int $daysOld = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d', strtotime("-{$daysOld} days"));

            // Get database connection directly
            $db = new \Antinna\MultiVendor\Database\Connection();
            $pdo = $db->getConnection();

            // Find batches that expired more than X days ago and have zero quantity
            $sql = "SELECT * FROM inventory_batches 
                    WHERE expiry_date < ? 
                    AND quantity_available = 0 
                    AND quantity_reserved = 0";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cutoffDate]);
            $oldBatches = $stmt->fetchAll();

            $deletedCount = 0;
            $errors = [];

            foreach ($oldBatches as $batch) {
                try {
                    // Log cleanup event before deletion
                    $this->traceabilityService->logEvent($batch['id'], 'cleanup', [
                        'description' => 'Old expired batch cleaned up from system',
                        'notes' => "Batch expired on {$batch['expiry_date']}, cleaned up after {$daysOld} days",
                        'metadata' => [
                            'cleanup_date' => date('Y-m-d H:i:s'),
                            'days_since_expiry' => $daysOld
                        ]
                    ]);

                    // Delete the batch
                    $success = $this->batchRepository->delete($batch['id']);
                    if ($success) {
                        $deletedCount++;
                    } else {
                        $errors[] = [
                            'batch_id' => $batch['id'],
                            'batch_number' => $batch['batch_number'],
                            'error' => 'Failed to delete batch'
                        ];
                    }

                } catch (Exception $e) {
                    $errors[] = [
                        'batch_id' => $batch['id'],
                        'batch_number' => $batch['batch_number'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return [
                'success' => true,
                'cutoff_date' => $cutoffDate,
                'total_old_batches' => count($oldBatches),
                'deleted_count' => $deletedCount,
                'errors' => $errors,
                'message' => "Cleaned up {$deletedCount} old expired batches"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get expiry statistics
     */
    public function getExpiryStatistics(): array
    {
        try {
            $stats = [
                'expired_today' => 0,
                'expiring_tomorrow' => 0,
                'expiring_this_week' => 0,
                'total_expired_quantity' => 0,
                'total_expiring_quantity' => 0,
                'vendors_affected' => 0
            ];

            // Get expired batches (today)
            $today = date('Y-m-d');
            $expiredToday = $this->batchRepository->findAll(['expiry_date' => $today]);
            $stats['expired_today'] = count($expiredToday);
            $stats['total_expired_quantity'] = array_sum(array_column($expiredToday, 'quantity_available'));

            // Get batches expiring tomorrow
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $expiringTomorrow = $this->batchRepository->findAll(['expiry_date' => $tomorrow]);
            $stats['expiring_tomorrow'] = count($expiringTomorrow);

            // Get batches expiring this week
            $expiringThisWeek = $this->batchRepository->findExpiringSoon(7);
            $stats['expiring_this_week'] = count($expiringThisWeek);
            $stats['total_expiring_quantity'] = array_sum(array_column($expiringThisWeek, 'quantity_available'));

            // Count unique vendors affected
            $vendorIds = array_unique(array_merge(
                array_column($expiredToday, 'vendor_id'),
                array_column($expiringThisWeek, 'vendor_id')
            ));
            $stats['vendors_affected'] = count($vendorIds);

            return [
                'success' => true,
                'statistics' => $stats,
                'generated_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Statistics generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Run complete expiry management process
     */
    public function runExpiryManagement(): array
    {
        try {
            $results = [
                'started_at' => date('Y-m-d H:i:s'),
                'steps' => []
            ];

            // Step 1: Process expired batches
            $expiredResult = $this->processExpiredBatches();
            $results['steps']['expired_batches'] = $expiredResult;

            // Step 2: Update product availability
            $availabilityResult = $this->updateProductAvailability();
            $results['steps']['product_availability'] = $availabilityResult;

            // Step 3: Generate alerts
            $alertsResult = $this->generateExpiryAlerts();
            $results['steps']['expiry_alerts'] = $alertsResult;

            // Step 4: Cleanup old batches (optional, run less frequently)
            $cleanupResult = $this->cleanupOldExpiredBatches();
            $results['steps']['cleanup'] = $cleanupResult;

            $results['completed_at'] = date('Y-m-d H:i:s');
            $results['overall_success'] = $expiredResult['success'] && $availabilityResult['success'];

            return [
                'success' => $results['overall_success'],
                'results' => $results,
                'message' => 'Expiry management process completed'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Expiry management process failed: ' . $e->getMessage()
            ];
        }
    }
}