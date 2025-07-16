<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Interfaces\ServiceInterface;
use Antinna\MultiVendor\Repositories\InventoryBatchRepository;
use Antinna\MultiVendor\Repositories\ProductRepository;
use Exception;

/**
 * Inventory tracking service for stock level monitoring and batch tracking
 */
class InventoryTracker implements ServiceInterface
{
    private InventoryBatchRepository $batchRepository;
    private ProductRepository $productRepository;

    public function __construct()
    {
        $this->batchRepository = new InventoryBatchRepository();
        $this->productRepository = new ProductRepository();
    }

    /**
     * Validate batch data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = [
            'product_id', 'batch_number', 'production_date', 
            'expiry_date', 'quantity_available'
        ];

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Product ID validation
        if (!empty($data['product_id'])) {
            if (!is_numeric($data['product_id'])) {
                $errors['product_id'] = 'Product ID must be a valid number';
            } else {
                // Check if product exists
                $product = $this->productRepository->find($data['product_id']);
                if (!$product) {
                    $errors['product_id'] = 'Product not found';
                }
            }
        }

        // Batch number validation
        if (!empty($data['batch_number'])) {
            if (strlen($data['batch_number']) < 3) {
                $errors['batch_number'] = 'Batch number must be at least 3 characters long';
            }
            if (strlen($data['batch_number']) > 100) {
                $errors['batch_number'] = 'Batch number must not exceed 100 characters';
            }
        }

        // Date validations
        if (!empty($data['production_date'])) {
            if (!$this->isValidDate($data['production_date'])) {
                $errors['production_date'] = 'Invalid production date format (YYYY-MM-DD)';
            } else {
                $productionDate = new \DateTime($data['production_date']);
                $today = new \DateTime();
                
                if ($productionDate > $today) {
                    $errors['production_date'] = 'Production date cannot be in the future';
                }
                
                // Check if production date is too old (more than 1 year)
                $oneYearAgo = new \DateTime('-1 year');
                if ($productionDate < $oneYearAgo) {
                    $errors['production_date'] = 'Production date cannot be more than 1 year ago';
                }
            }
        }

        if (!empty($data['expiry_date'])) {
            if (!$this->isValidDate($data['expiry_date'])) {
                $errors['expiry_date'] = 'Invalid expiry date format (YYYY-MM-DD)';
            } else {
                $expiryDate = new \DateTime($data['expiry_date']);
                $today = new \DateTime();
                
                if ($expiryDate <= $today) {
                    $errors['expiry_date'] = 'Expiry date must be in the future';
                }
            }
        }

        // Cross-date validation
        if (!empty($data['production_date']) && !empty($data['expiry_date'])) {
            if ($this->isValidDate($data['production_date']) && $this->isValidDate($data['expiry_date'])) {
                $productionDate = new \DateTime($data['production_date']);
                $expiryDate = new \DateTime($data['expiry_date']);
                
                if ($expiryDate <= $productionDate) {
                    $errors['expiry_date'] = 'Expiry date must be after production date';
                }
            }
        }

        // Quantity validations
        if (!empty($data['quantity_available'])) {
            if (!is_numeric($data['quantity_available']) || $data['quantity_available'] < 0) {
                $errors['quantity_available'] = 'Quantity available must be a non-negative number';
            }
            if ($data['quantity_available'] > 999999) {
                $errors['quantity_available'] = 'Quantity available cannot exceed 999,999';
            }
        }

        if (!empty($data['quantity_reserved'])) {
            if (!is_numeric($data['quantity_reserved']) || $data['quantity_reserved'] < 0) {
                $errors['quantity_reserved'] = 'Quantity reserved must be a non-negative number';
            }
            
            $available = $data['quantity_available'] ?? 0;
            if ($data['quantity_reserved'] > $available) {
                $errors['quantity_reserved'] = 'Quantity reserved cannot exceed quantity available';
            }
        }

        // Farm source validation
        if (!empty($data['farm_source']) && strlen($data['farm_source']) > 255) {
            $errors['farm_source'] = 'Farm source must not exceed 255 characters';
        }

        // Quality grade validation
        if (!empty($data['quality_grade'])) {
            $validGrades = ['A', 'B', 'C'];
            if (!in_array($data['quality_grade'], $validGrades)) {
                $errors['quality_grade'] = 'Quality grade must be one of: ' . implode(', ', $validGrades);
            }
        }

        // Temperature validations
        if (!empty($data['storage_temperature_min'])) {
            if (!is_numeric($data['storage_temperature_min'])) {
                $errors['storage_temperature_min'] = 'Storage temperature min must be a number';
            } elseif ($data['storage_temperature_min'] < -50 || $data['storage_temperature_min'] > 50) {
                $errors['storage_temperature_min'] = 'Storage temperature min must be between -50°C and 50°C';
            }
        }

        if (!empty($data['storage_temperature_max'])) {
            if (!is_numeric($data['storage_temperature_max'])) {
                $errors['storage_temperature_max'] = 'Storage temperature max must be a number';
            } elseif ($data['storage_temperature_max'] < -50 || $data['storage_temperature_max'] > 50) {
                $errors['storage_temperature_max'] = 'Storage temperature max must be between -50°C and 50°C';
            }
        }

        // Cross-temperature validation
        if (!empty($data['storage_temperature_min']) && !empty($data['storage_temperature_max'])) {
            if (is_numeric($data['storage_temperature_min']) && is_numeric($data['storage_temperature_max'])) {
                if ($data['storage_temperature_max'] <= $data['storage_temperature_min']) {
                    $errors['storage_temperature_max'] = 'Maximum temperature must be greater than minimum temperature';
                }
            }
        }

        // Notes validation
        if (!empty($data['notes']) && strlen($data['notes']) > 1000) {
            $errors['notes'] = 'Notes must not exceed 1000 characters';
        }

        // Boolean validations
        if (isset($data['is_recalled'])) {
            $data['is_recalled'] = filter_var($data['is_recalled'], FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Process batch creation/update
     */
    public function process(array $data): array
    {
        if (isset($data['batch_id'])) {
            return $this->updateBatch($data['batch_id'], $data);
        } else {
            return $this->createBatch($data);
        }
    }

    /**
     * Create new inventory batch
     */
    public function createBatch(array $data): array
    {
        try {
            // Validate batch data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for duplicate batch number for product
            if ($this->isDuplicateBatchNumber($data['product_id'], $data['batch_number'])) {
                return [
                    'success' => false,
                    'errors' => ['batch_number' => 'Batch number already exists for this product']
                ];
            }

            // Prepare batch data
            $batchData = [
                'product_id' => $validation['data']['product_id'],
                'batch_number' => $validation['data']['batch_number'],
                'production_date' => $validation['data']['production_date'],
                'expiry_date' => $validation['data']['expiry_date'],
                'quantity_available' => $validation['data']['quantity_available'],
                'quantity_reserved' => $validation['data']['quantity_reserved'] ?? 0,
                'farm_source' => $validation['data']['farm_source'] ?? null,
                'quality_grade' => $validation['data']['quality_grade'] ?? 'A',
                'storage_temperature_min' => $validation['data']['storage_temperature_min'] ?? null,
                'storage_temperature_max' => $validation['data']['storage_temperature_max'] ?? null,
                'notes' => $validation['data']['notes'] ?? null,
                'is_recalled' => $validation['data']['is_recalled'] ?? false
            ];

            // Create batch
            $batchId = $this->batchRepository->create($batchData);

            return [
                'success' => true,
                'batch_id' => $batchId,
                'message' => 'Inventory batch created successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Batch creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update existing batch
     */
    public function updateBatch(int $batchId, array $data): array
    {
        try {
            // Check if batch exists
            $existingBatch = $this->batchRepository->find($batchId);
            if (!$existingBatch) {
                return [
                    'success' => false,
                    'error' => 'Batch not found'
                ];
            }

            // Add product ID to data for validation context
            $data['product_id'] = $existingBatch['product_id'];

            // Validate update data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for duplicate batch number (excluding current batch)
            if (isset($data['batch_number']) && $data['batch_number'] !== $existingBatch['batch_number']) {
                if ($this->isDuplicateBatchNumber($existingBatch['product_id'], $data['batch_number'], $batchId)) {
                    return [
                        'success' => false,
                        'errors' => ['batch_number' => 'Batch number already exists for this product']
                    ];
                }
            }

            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'batch_number', 'production_date', 'expiry_date', 'quantity_available',
                'quantity_reserved', 'farm_source', 'quality_grade', 'storage_temperature_min',
                'storage_temperature_max', 'notes', 'is_recalled'
            ];

            foreach ($allowedFields as $field) {
                if (isset($validation['data'][$field])) {
                    $updateData[$field] = $validation['data'][$field];
                }
            }

            // Update batch
            $success = $this->batchRepository->update($batchId, $updateData);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Batch updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update batch'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Batch update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get batch details
     */
    public function getBatch(int $batchId): array
    {
        try {
            $batch = $this->batchRepository->find($batchId);
            
            if (!$batch) {
                return [
                    'found' => false,
                    'error' => 'Batch not found'
                ];
            }

            // Get product information
            $product = $this->productRepository->find($batch['product_id']);
            $batch['product_name'] = $product['name'] ?? 'Unknown Product';

            return [
                'found' => true,
                'batch' => $batch
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => 'Error retrieving batch: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get batches for product
     */
    public function getProductBatches(int $productId, ?bool $availableOnly = null): array
    {
        try {
            if ($availableOnly) {
                $batches = $this->batchRepository->findAvailableForProduct($productId);
            } else {
                $batches = $this->batchRepository->findByProduct($productId);
            }

            return [
                'success' => true,
                'batches' => $batches
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving product batches: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get expiring batches
     */
    public function getExpiringBatches(int $days = 3): array
    {
        try {
            $batches = $this->batchRepository->findExpiringSoon($days);

            return [
                'success' => true,
                'batches' => $batches,
                'count' => count($batches)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving expiring batches: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get expired batches
     */
    public function getExpiredBatches(): array
    {
        try {
            $batches = $this->batchRepository->findExpired();

            return [
                'success' => true,
                'batches' => $batches,
                'count' => count($batches)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving expired batches: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reserve quantity from batch
     */
    public function reserveQuantity(int $batchId, int $quantity): array
    {
        try {
            $batch = $this->batchRepository->find($batchId);
            
            if (!$batch) {
                return [
                    'success' => false,
                    'error' => 'Batch not found'
                ];
            }

            $availableQuantity = $batch['quantity_available'] - $batch['quantity_reserved'];
            
            if ($quantity > $availableQuantity) {
                return [
                    'success' => false,
                    'error' => 'Insufficient quantity available for reservation'
                ];
            }

            $success = $this->batchRepository->reserveQuantity($batchId, $quantity);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Quantity reserved successfully',
                    'reserved_quantity' => $quantity
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to reserve quantity'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Quantity reservation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Release reserved quantity
     */
    public function releaseQuantity(int $batchId, int $quantity): array
    {
        try {
            $success = $this->batchRepository->releaseQuantity($batchId, $quantity);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Quantity released successfully',
                    'released_quantity' => $quantity
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to release quantity'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Quantity release failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mark batch as recalled
     */
    public function recallBatch(int $batchId, string $reason): array
    {
        try {
            $updateData = [
                'is_recalled' => true,
                'recall_reason' => $reason
            ];

            $success = $this->batchRepository->update($batchId, $updateData);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Batch recalled successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to recall batch'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Batch recall failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get inventory summary for product
     */
    public function getInventorySummary(int $productId): array
    {
        try {
            $batches = $this->batchRepository->findByProduct($productId);
            
            $summary = [
                'total_batches' => count($batches),
                'total_quantity' => 0,
                'available_quantity' => 0,
                'reserved_quantity' => 0,
                'expired_quantity' => 0,
                'expiring_soon_quantity' => 0,
                'recalled_quantity' => 0
            ];

            $today = new \DateTime();
            $threeDaysFromNow = new \DateTime('+3 days');

            foreach ($batches as $batch) {
                $expiryDate = new \DateTime($batch['expiry_date']);
                
                $summary['total_quantity'] += $batch['quantity_available'];
                $summary['reserved_quantity'] += $batch['quantity_reserved'];
                
                if ($batch['is_recalled']) {
                    $summary['recalled_quantity'] += $batch['quantity_available'];
                } elseif ($expiryDate < $today) {
                    $summary['expired_quantity'] += $batch['quantity_available'];
                } elseif ($expiryDate <= $threeDaysFromNow) {
                    $summary['expiring_soon_quantity'] += $batch['quantity_available'];
                    $summary['available_quantity'] += ($batch['quantity_available'] - $batch['quantity_reserved']);
                } else {
                    $summary['available_quantity'] += ($batch['quantity_available'] - $batch['quantity_reserved']);
                }
            }

            return [
                'success' => true,
                'summary' => $summary
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error generating inventory summary: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if batch number is duplicate for product
     */
    private function isDuplicateBatchNumber(int $productId, string $batchNumber, ?int $excludeBatchId = null): bool
    {
        $existing = $this->batchRepository->findAll(['product_id' => $productId, 'batch_number' => $batchNumber]);
        
        foreach ($existing as $batch) {
            if ($excludeBatchId && $batch['id'] == $excludeBatchId) {
                continue;
            }
            return true;
        }
        
        return false;
    }

    /**
     * Validate date format
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}