<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Interfaces\ServiceInterface;
use Antinna\MultiVendor\Database\Connection;
use Antinna\MultiVendor\Repositories\ProductRepository;
use Antinna\MultiVendor\Repositories\VendorRepository;
use PDO;
use Exception;
use DateTime;

/**
 * Subscription management service for customer subscription handling
 */
class SubscriptionManager implements ServiceInterface
{
    private PDO $db;
    private ProductRepository $productRepository;
    private VendorRepository $vendorRepository;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->productRepository = new ProductRepository();
        $this->vendorRepository = new VendorRepository();
    }

    /**
     * Validate subscription data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = [
            'customer_id', 'vendor_id', 'product_id', 'quantity',
            'delivery_days', 'preferred_time_slot', 'delivery_address', 'start_date'
        ];

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Customer ID validation
        if (!empty($data['customer_id']) && !is_numeric($data['customer_id'])) {
            $errors['customer_id'] = 'Customer ID must be a valid number';
        }

        // Vendor ID validation
        if (!empty($data['vendor_id'])) {
            if (!is_numeric($data['vendor_id'])) {
                $errors['vendor_id'] = 'Vendor ID must be a valid number';
            } else {
                $vendor = $this->vendorRepository->find($data['vendor_id']);
                if (!$vendor) {
                    $errors['vendor_id'] = 'Vendor not found';
                } elseif ($vendor['status'] !== 'active') {
                    $errors['vendor_id'] = 'Vendor must be active for subscriptions';
                }
            }
        }

        // Product ID validation
        if (!empty($data['product_id'])) {
            if (!is_numeric($data['product_id'])) {
                $errors['product_id'] = 'Product ID must be a valid number';
            } else {
                $product = $this->productRepository->find($data['product_id']);
                if (!$product) {
                    $errors['product_id'] = 'Product not found';
                } elseif ($product['status'] !== 'active') {
                    $errors['product_id'] = 'Product must be active for subscriptions';
                }
                
                // Verify product belongs to vendor
                if (!empty($data['vendor_id']) && $product && $product['vendor_id'] != $data['vendor_id']) {
                    $errors['product_id'] = 'Product does not belong to the specified vendor';
                }
            }
        }

        // Quantity validation
        if (!empty($data['quantity'])) {
            if (!is_numeric($data['quantity']) || $data['quantity'] <= 0) {
                $errors['quantity'] = 'Quantity must be a positive number';
            }
            
            // Check against product minimum/maximum order quantities
            if (!empty($data['product_id']) && is_numeric($data['product_id'])) {
                $product = $this->productRepository->find($data['product_id']);
                if ($product) {
                    if ($data['quantity'] < $product['minimum_order_quantity']) {
                        $errors['quantity'] = "Quantity must be at least {$product['minimum_order_quantity']}";
                    }
                    if ($product['maximum_order_quantity'] && $data['quantity'] > $product['maximum_order_quantity']) {
                        $errors['quantity'] = "Quantity cannot exceed {$product['maximum_order_quantity']}";
                    }
                }
            }
        }

        // Delivery days validation
        if (!empty($data['delivery_days'])) {
            if (is_string($data['delivery_days'])) {
                $data['delivery_days'] = json_decode($data['delivery_days'], true);
            }
            
            if (!is_array($data['delivery_days'])) {
                $errors['delivery_days'] = 'Delivery days must be an array';
            } else {
                $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                foreach ($data['delivery_days'] as $day) {
                    if (!in_array(strtolower($day), $validDays)) {
                        $errors['delivery_days'] = 'Invalid delivery day: ' . $day;
                        break;
                    }
                }
                
                if (empty($data['delivery_days'])) {
                    $errors['delivery_days'] = 'At least one delivery day must be specified';
                }
                
                if (count($data['delivery_days']) > 7) {
                    $errors['delivery_days'] = 'Cannot specify more than 7 delivery days';
                }
            }
        }

        // Time slot validation
        if (!empty($data['preferred_time_slot'])) {
            if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]-([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $data['preferred_time_slot'])) {
                $errors['preferred_time_slot'] = 'Time slot must be in format HH:MM-HH:MM (e.g., 06:00-08:00)';
            } else {
                // Validate time range
                $times = explode('-', $data['preferred_time_slot']);
                $startTime = DateTime::createFromFormat('H:i', $times[0]);
                $endTime = DateTime::createFromFormat('H:i', $times[1]);
                
                if ($endTime <= $startTime) {
                    $errors['preferred_time_slot'] = 'End time must be after start time';
                }
            }
        }

        // Delivery address validation
        if (!empty($data['delivery_address'])) {
            if (strlen($data['delivery_address']) < 10) {
                $errors['delivery_address'] = 'Delivery address must be at least 10 characters long';
            }
            if (strlen($data['delivery_address']) > 500) {
                $errors['delivery_address'] = 'Delivery address must not exceed 500 characters';
            }
        }

        // Location validation
        if (!empty($data['delivery_latitude']) || !empty($data['delivery_longitude'])) {
            if (empty($data['delivery_latitude']) || empty($data['delivery_longitude'])) {
                $errors['location'] = 'Both latitude and longitude are required for delivery location';
            } else {
                if (!is_numeric($data['delivery_latitude']) || $data['delivery_latitude'] < -90 || $data['delivery_latitude'] > 90) {
                    $errors['delivery_latitude'] = 'Delivery latitude must be between -90 and 90';
                }
                if (!is_numeric($data['delivery_longitude']) || $data['delivery_longitude'] < -180 || $data['delivery_longitude'] > 180) {
                    $errors['delivery_longitude'] = 'Delivery longitude must be between -180 and 180';
                }
            }
        }

        // Date validations
        if (!empty($data['start_date'])) {
            if (!$this->isValidDate($data['start_date'])) {
                $errors['start_date'] = 'Invalid start date format (YYYY-MM-DD)';
            } else {
                $startDate = new DateTime($data['start_date']);
                $today = new DateTime();
                $today->setTime(0, 0, 0);
                
                if ($startDate < $today) {
                    $errors['start_date'] = 'Start date cannot be in the past';
                }
            }
        }

        if (!empty($data['end_date'])) {
            if (!$this->isValidDate($data['end_date'])) {
                $errors['end_date'] = 'Invalid end date format (YYYY-MM-DD)';
            } else {
                $endDate = new DateTime($data['end_date']);
                
                if (!empty($data['start_date']) && $this->isValidDate($data['start_date'])) {
                    $startDate = new DateTime($data['start_date']);
                    if ($endDate <= $startDate) {
                        $errors['end_date'] = 'End date must be after start date';
                    }
                }
            }
        }

        // Pause date validations
        if (!empty($data['pause_start_date']) || !empty($data['pause_end_date'])) {
            if (!empty($data['pause_start_date']) && !$this->isValidDate($data['pause_start_date'])) {
                $errors['pause_start_date'] = 'Invalid pause start date format (YYYY-MM-DD)';
            }
            if (!empty($data['pause_end_date']) && !$this->isValidDate($data['pause_end_date'])) {
                $errors['pause_end_date'] = 'Invalid pause end date format (YYYY-MM-DD)';
            }
            
            if (!empty($data['pause_start_date']) && !empty($data['pause_end_date'])) {
                if ($this->isValidDate($data['pause_start_date']) && $this->isValidDate($data['pause_end_date'])) {
                    $pauseStart = new DateTime($data['pause_start_date']);
                    $pauseEnd = new DateTime($data['pause_end_date']);
                    
                    if ($pauseEnd <= $pauseStart) {
                        $errors['pause_end_date'] = 'Pause end date must be after pause start date';
                    }
                }
            }
        }

        // Status validation
        if (!empty($data['status'])) {
            $validStatuses = ['active', 'paused', 'cancelled'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors['status'] = 'Status must be one of: ' . implode(', ', $validStatuses);
            }
        }

        // Special instructions validation
        if (!empty($data['special_instructions']) && strlen($data['special_instructions']) > 1000) {
            $errors['special_instructions'] = 'Special instructions must not exceed 1000 characters';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Process subscription creation/update
     */
    public function process(array $data): array
    {
        if (isset($data['subscription_id'])) {
            return $this->updateSubscription($data['subscription_id'], $data);
        } else {
            return $this->createSubscription($data);
        }
    }

    /**
     * Create new subscription
     */
    public function createSubscription(array $data): array
    {
        try {
            // Validate subscription data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for existing active subscription for same customer/product
            if ($this->hasActiveSubscription($data['customer_id'], $data['product_id'])) {
                return [
                    'success' => false,
                    'errors' => ['subscription' => 'Customer already has an active subscription for this product']
                ];
            }

            // Calculate next delivery date
            $nextDeliveryDate = $this->calculateNextDeliveryDate($data['start_date'], $data['delivery_days']);

            // Prepare subscription data
            $subscriptionData = [
                'customer_id' => $validation['data']['customer_id'],
                'vendor_id' => $validation['data']['vendor_id'],
                'product_id' => $validation['data']['product_id'],
                'quantity' => $validation['data']['quantity'],
                'delivery_days' => json_encode($validation['data']['delivery_days']),
                'preferred_time_slot' => $validation['data']['preferred_time_slot'],
                'delivery_address' => $validation['data']['delivery_address'],
                'delivery_latitude' => $validation['data']['delivery_latitude'] ?? null,
                'delivery_longitude' => $validation['data']['delivery_longitude'] ?? null,
                'special_instructions' => $validation['data']['special_instructions'] ?? null,
                'status' => 'active',
                'start_date' => $validation['data']['start_date'],
                'end_date' => $validation['data']['end_date'] ?? null,
                'next_delivery_date' => $nextDeliveryDate,
                'total_orders_generated' => 0
            ];

            // Create subscription
            $sql = "INSERT INTO customer_subscriptions (" . implode(', ', array_keys($subscriptionData)) . ") 
                    VALUES (" . str_repeat('?,', count($subscriptionData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($subscriptionData));

            if ($success) {
                $subscriptionId = $this->db->lastInsertId();
                
                return [
                    'success' => true,
                    'subscription_id' => $subscriptionId,
                    'next_delivery_date' => $nextDeliveryDate,
                    'message' => 'Subscription created successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create subscription'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Subscription creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update existing subscription
     */
    public function updateSubscription(int $subscriptionId, array $data): array
    {
        try {
            // Check if subscription exists
            $existingSubscription = $this->getSubscription($subscriptionId);
            if (!$existingSubscription['found']) {
                return [
                    'success' => false,
                    'error' => 'Subscription not found'
                ];
            }

            $subscription = $existingSubscription['subscription'];

            // Add required fields from existing subscription for validation
            $data['customer_id'] = $data['customer_id'] ?? $subscription['customer_id'];
            $data['vendor_id'] = $data['vendor_id'] ?? $subscription['vendor_id'];
            $data['product_id'] = $data['product_id'] ?? $subscription['product_id'];

            // Validate update data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'quantity', 'delivery_days', 'preferred_time_slot', 'delivery_address',
                'delivery_latitude', 'delivery_longitude', 'special_instructions',
                'status', 'end_date', 'pause_start_date', 'pause_end_date'
            ];

            foreach ($allowedFields as $field) {
                if (isset($validation['data'][$field])) {
                    if ($field === 'delivery_days') {
                        $updateData[$field] = json_encode($validation['data'][$field]);
                    } else {
                        $updateData[$field] = $validation['data'][$field];
                    }
                }
            }

            // Recalculate next delivery date if delivery days changed
            if (isset($updateData['delivery_days'])) {
                $deliveryDays = json_decode($updateData['delivery_days'], true);
                $updateData['next_delivery_date'] = $this->calculateNextDeliveryDate(
                    date('Y-m-d'), 
                    $deliveryDays
                );
            }

            if (empty($updateData)) {
                return [
                    'success' => false,
                    'error' => 'No valid fields to update'
                ];
            }

            // Update subscription
            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE customer_subscriptions SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $params = array_values($updateData);
            $params[] = $subscriptionId;
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Subscription updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update subscription'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Subscription update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get subscription details
     */
    public function getSubscription(int $subscriptionId): array
    {
        try {
            $sql = "SELECT cs.*, p.name as product_name, p.unit_type, p.price_per_unit,
                           v.business_name as vendor_name
                    FROM customer_subscriptions cs
                    JOIN products p ON cs.product_id = p.id
                    JOIN vendors v ON cs.vendor_id = v.id
                    WHERE cs.id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$subscriptionId]);
            $subscription = $stmt->fetch();

            if (!$subscription) {
                return [
                    'found' => false,
                    'error' => 'Subscription not found'
                ];
            }

            // Decode JSON fields
            $subscription['delivery_days'] = json_decode($subscription['delivery_days'], true);

            return [
                'found' => true,
                'subscription' => $subscription
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => 'Error retrieving subscription: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get customer subscriptions
     */
    public function getCustomerSubscriptions(int $customerId, ?string $status = null): array
    {
        try {
            $sql = "SELECT cs.*, p.name as product_name, p.unit_type, p.price_per_unit,
                           v.business_name as vendor_name
                    FROM customer_subscriptions cs
                    JOIN products p ON cs.product_id = p.id
                    JOIN vendors v ON cs.vendor_id = v.id
                    WHERE cs.customer_id = ?";

            $params = [$customerId];

            if ($status) {
                $sql .= " AND cs.status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY cs.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll();

            // Decode JSON fields
            foreach ($subscriptions as &$subscription) {
                $subscription['delivery_days'] = json_decode($subscription['delivery_days'], true);
            }

            return [
                'success' => true,
                'subscriptions' => $subscriptions
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving customer subscriptions: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(int $subscriptionId, ?string $reason = null): array
    {
        try {
            $updateData = [
                'status' => 'cancelled'
            ];

            if ($reason) {
                $updateData['special_instructions'] = ($this->getSubscription($subscriptionId)['subscription']['special_instructions'] ?? '') . 
                                                    "\n[CANCELLED: {$reason}]";
            }

            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE customer_subscriptions SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $params = array_values($updateData);
            $params[] = $subscriptionId;
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Subscription cancelled successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to cancel subscription'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Subscription cancellation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if customer has active subscription for product
     */
    private function hasActiveSubscription(int $customerId, int $productId): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM customer_subscriptions 
                    WHERE customer_id = ? AND product_id = ? AND status = 'active'";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$customerId, $productId]);
            
            return $stmt->fetchColumn() > 0;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Calculate next delivery date based on start date and delivery days
     */
    private function calculateNextDeliveryDate(string $startDate, array $deliveryDays): string
    {
        $start = new DateTime($startDate);
        $dayMap = [
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6
        ];

        // Convert delivery days to numeric values
        $numericDays = [];
        foreach ($deliveryDays as $day) {
            $numericDays[] = $dayMap[strtolower($day)];
        }
        sort($numericDays);

        // Find next delivery date
        $currentDay = (int)$start->format('w');
        $nextDay = null;

        // Look for next delivery day in current week
        foreach ($numericDays as $day) {
            if ($day >= $currentDay) {
                $nextDay = $day;
                break;
            }
        }

        // If no day found in current week, use first day of next week
        if ($nextDay === null) {
            $nextDay = $numericDays[0];
            $start->modify('+1 week');
        }

        // Calculate days to add
        $daysToAdd = $nextDay - $currentDay;
        if ($daysToAdd < 0) {
            $daysToAdd += 7;
        }

        $start->modify("+{$daysToAdd} days");
        return $start->format('Y-m-d');
    }

    /**
     * Pause subscription
     */
    public function pauseSubscription(int $subscriptionId, ?string $pauseStartDate = null, ?string $pauseEndDate = null, ?string $reason = null): array
    {
        try {
            $subscription = $this->getSubscription($subscriptionId);
            if (!$subscription['found']) {
                return [
                    'success' => false,
                    'error' => 'Subscription not found'
                ];
            }

            if ($subscription['subscription']['status'] !== 'active') {
                return [
                    'success' => false,
                    'error' => 'Only active subscriptions can be paused'
                ];
            }

            // Set default pause dates if not provided
            $pauseStartDate = $pauseStartDate ?? date('Y-m-d');
            
            // Validate pause dates
            $validation = $this->validate([
                'customer_id' => $subscription['subscription']['customer_id'],
                'vendor_id' => $subscription['subscription']['vendor_id'],
                'product_id' => $subscription['subscription']['product_id'],
                'quantity' => $subscription['subscription']['quantity'],
                'delivery_days' => $subscription['subscription']['delivery_days'],
                'preferred_time_slot' => $subscription['subscription']['preferred_time_slot'],
                'delivery_address' => $subscription['subscription']['delivery_address'],
                'start_date' => $subscription['subscription']['start_date'],
                'pause_start_date' => $pauseStartDate,
                'pause_end_date' => $pauseEndDate
            ]);

            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Update subscription
            $updateData = [
                'status' => 'paused',
                'pause_start_date' => $pauseStartDate,
                'pause_end_date' => $pauseEndDate
            ];

            if ($reason) {
                $currentInstructions = $subscription['subscription']['special_instructions'] ?? '';
                $updateData['special_instructions'] = $currentInstructions . "\n[PAUSED: {$reason}]";
            }

            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE customer_subscriptions SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $params = array_values($updateData);
            $params[] = $subscriptionId;
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                return [
                    'success' => true,
                    'pause_start_date' => $pauseStartDate,
                    'pause_end_date' => $pauseEndDate,
                    'message' => 'Subscription paused successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to pause subscription'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Subscription pause failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Resume subscription
     */
    public function resumeSubscription(int $subscriptionId, ?string $resumeDate = null): array
    {
        try {
            $subscription = $this->getSubscription($subscriptionId);
            if (!$subscription['found']) {
                return [
                    'success' => false,
                    'error' => 'Subscription not found'
                ];
            }

            if ($subscription['subscription']['status'] !== 'paused') {
                return [
                    'success' => false,
                    'error' => 'Only paused subscriptions can be resumed'
                ];
            }

            $resumeDate = $resumeDate ?? date('Y-m-d');
            
            // Calculate next delivery date from resume date
            $nextDeliveryDate = $this->calculateNextDeliveryDate(
                $resumeDate, 
                $subscription['subscription']['delivery_days']
            );

            // Update subscription
            $updateData = [
                'status' => 'active',
                'pause_start_date' => null,
                'pause_end_date' => null,
                'next_delivery_date' => $nextDeliveryDate
            ];

            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE customer_subscriptions SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $params = array_values($updateData);
            $params[] = $subscriptionId;
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                return [
                    'success' => true,
                    'resume_date' => $resumeDate,
                    'next_delivery_date' => $nextDeliveryDate,
                    'message' => 'Subscription resumed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to resume subscription'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Subscription resume failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Pause subscription for limited time
     */
    public function pauseForLimitedTime(int $subscriptionId, int $days, ?string $reason = null): array
    {
        $pauseStartDate = date('Y-m-d');
        $pauseEndDate = date('Y-m-d', strtotime("+{$days} days"));
        
        return $this->pauseSubscription($subscriptionId, $pauseStartDate, $pauseEndDate, $reason);
    }

    /**
     * Auto-resume expired pauses
     */
    public function autoResumeExpiredPauses(): array
    {
        try {
            $today = date('Y-m-d');
            
            // Find paused subscriptions where pause_end_date has passed
            $sql = "SELECT id FROM customer_subscriptions 
                    WHERE status = 'paused' 
                    AND pause_end_date IS NOT NULL 
                    AND pause_end_date < ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$today]);
            $expiredPauses = $stmt->fetchAll();

            $resumedCount = 0;
            $errors = [];

            foreach ($expiredPauses as $subscription) {
                $result = $this->resumeSubscription($subscription['id']);
                if ($result['success']) {
                    $resumedCount++;
                } else {
                    $errors[] = [
                        'subscription_id' => $subscription['id'],
                        'error' => $result['error']
                    ];
                }
            }

            return [
                'success' => true,
                'total_expired_pauses' => count($expiredPauses),
                'resumed_count' => $resumedCount,
                'errors' => $errors,
                'message' => "Auto-resumed {$resumedCount} subscriptions"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Auto-resume failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get subscription pause/resume history
     */
    public function getSubscriptionHistory(int $subscriptionId): array
    {
        try {
            // This would typically come from an audit log table
            // For now, we'll return basic subscription info with current pause status
            $subscription = $this->getSubscription($subscriptionId);
            
            if (!$subscription['found']) {
                return [
                    'success' => false,
                    'error' => 'Subscription not found'
                ];
            }

            $history = [
                'subscription_id' => $subscriptionId,
                'current_status' => $subscription['subscription']['status'],
                'created_at' => $subscription['subscription']['created_at'],
                'total_orders_generated' => $subscription['subscription']['total_orders_generated'],
                'pause_history' => []
            ];

            // Add current pause info if paused
            if ($subscription['subscription']['status'] === 'paused') {
                $history['pause_history'][] = [
                    'pause_start_date' => $subscription['subscription']['pause_start_date'],
                    'pause_end_date' => $subscription['subscription']['pause_end_date'],
                    'is_active' => true
                ];
            }

            return [
                'success' => true,
                'history' => $history
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get subscription history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get paused subscriptions summary
     */
    public function getPausedSubscriptionsSummary(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_paused,
                        COUNT(CASE WHEN pause_end_date IS NULL THEN 1 END) as indefinite_pauses,
                        COUNT(CASE WHEN pause_end_date IS NOT NULL THEN 1 END) as temporary_pauses,
                        COUNT(CASE WHEN pause_end_date < CURDATE() THEN 1 END) as expired_pauses
                    FROM customer_subscriptions 
                    WHERE status = 'paused'";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $summary = $stmt->fetch();

            return [
                'success' => true,
                'summary' => $summary ?: [
                    'total_paused' => 0,
                    'indefinite_pauses' => 0,
                    'temporary_pauses' => 0,
                    'expired_pauses' => 0
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get paused subscriptions summary: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate date format
     */
    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}