<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Vendor promotion service for managing vendor-specific discounts and coupons
 */
class VendorPromotionService
{
    private PDO $db;
    private Logger $logger;

    // Promotion types
    const TYPE_PERCENTAGE_DISCOUNT = 'percentage_discount';
    const TYPE_FIXED_AMOUNT_DISCOUNT = 'fixed_amount_discount';
    const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    const TYPE_FREE_DELIVERY = 'free_delivery';
    const TYPE_MINIMUM_ORDER_DISCOUNT = 'minimum_order_discount';
    const TYPE_FIRST_ORDER_DISCOUNT = 'first_order_discount';
    const TYPE_BULK_DISCOUNT = 'bulk_discount';

    // Promotion statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    // Coupon statuses
    const COUPON_STATUS_ACTIVE = 'active';
    const COUPON_STATUS_USED = 'used';
    const COUPON_STATUS_EXPIRED = 'expired';
    const COUPON_STATUS_CANCELLED = 'cancelled';

    // Application scopes
    const SCOPE_ALL_PRODUCTS = 'all_products';
    const SCOPE_SPECIFIC_PRODUCTS = 'specific_products';
    const SCOPE_CATEGORY = 'category';
    const SCOPE_MINIMUM_ORDER = 'minimum_order';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->createPromotionTables();
    }

    /**
     * Create vendor promotion
     */
    public function createPromotion(int $vendorId, array $promotionData): array
    {
        try {
            // Validate promotion data
            $validation = $this->validatePromotionData($promotionData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check vendor exists
            $vendor = $this->getVendorDetails($vendorId);
            if (!$vendor) {
                return [
                    'success' => false,
                    'error' => 'Vendor not found'
                ];
            }

            // Check for conflicting promotions
            $conflictCheck = $this->checkPromotionConflicts($vendorId, $validation['data']);
            if (!$conflictCheck['valid']) {
                return [
                    'success' => false,
                    'error' => $conflictCheck['message']
                ];
            }

            // Create promotion
            $promotionId = $this->savePromotion($vendorId, $validation['data']);
            
            if (!$promotionId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create promotion'
                ];
            }

            // Generate coupon codes if needed
            if ($validation['data']['generate_coupons']) {
                $couponResult = $this->generatePromotionCoupons($promotionId, $validation['data']);
                if (!$couponResult['success']) {
                    return $couponResult;
                }
            }

            return [
                'success' => true,
                'promotion_id' => $promotionId,
                'message' => 'Promotion created successfully'
            ];

        } catch (Exception $e) {
            $this->logger->error('Promotion creation failed', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Promotion creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate coupon and calculate discount
     */
    public function validateAndCalculateDiscount(string $couponCode, int $vendorId, array $orderData): array
    {
        try {
            // Get coupon details
            $coupon = $this->getCouponByCode($couponCode, $vendorId);
            if (!$coupon) {
                return [
                    'valid' => false,
                    'error' => 'Invalid coupon code'
                ];
            }

            // Check coupon status
            if ($coupon['status'] !== self::COUPON_STATUS_ACTIVE) {
                return [
                    'valid' => false,
                    'error' => 'Coupon is not active'
                ];
            }

            // Get promotion details
            $promotion = $this->getPromotionDetails($coupon['promotion_id']);
            if (!$promotion || $promotion['status'] !== self::STATUS_ACTIVE) {
                return [
                    'valid' => false,
                    'error' => 'Promotion is not active'
                ];
            }

            // Check promotion validity dates
            $now = date('Y-m-d H:i:s');
            if ($now < $promotion['start_date'] || $now > $promotion['end_date']) {
                return [
                    'valid' => false,
                    'error' => 'Promotion has expired'
                ];
            }

            // Check usage limits
            $usageCheck = $this->checkUsageLimits($coupon, $promotion, $orderData);
            if (!$usageCheck['valid']) {
                return $usageCheck;
            }

            // Check promotion conditions
            $conditionCheck = $this->checkPromotionConditions($promotion, $orderData);
            if (!$conditionCheck['valid']) {
                return $conditionCheck;
            }

            // Calculate discount
            $discountCalculation = $this->calculateDiscount($promotion, $orderData);

            // Calculate payout adjustment
            $payoutAdjustment = $this->calculatePayoutAdjustment($promotion, $discountCalculation);

            return [
                'valid' => true,
                'coupon' => $coupon,
                'promotion' => $promotion,
                'discount_calculation' => $discountCalculation,
                'payout_adjustment' => $payoutAdjustment
            ];

        } catch (Exception $e) {
            $this->logger->error('Coupon validation failed', [
                'coupon_code' => $couponCode,
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'error' => 'Coupon validation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Apply promotion to order
     */
    public function applyPromotionToOrder(int $orderId, string $couponCode, array $discountData): array
    {
        try {
            // Record promotion usage
            $usageId = $this->recordPromotionUsage($orderId, $couponCode, $discountData);
            
            if (!$usageId) {
                return [
                    'success' => false,
                    'error' => 'Failed to record promotion usage'
                ];
            }

            // Update coupon usage count
            $this->updateCouponUsage($couponCode);

            // Update order with discount
            $this->updateOrderDiscount($orderId, $discountData);

            return [
                'success' => true,
                'usage_id' => $usageId,
                'discount_applied' => $discountData['discount_amount'],
                'message' => 'Promotion applied successfully'
            ];

        } catch (Exception $e) {
            $this->logger->error('Promotion application failed', [
                'order_id' => $orderId,
                'coupon_code' => $couponCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Promotion application failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get vendor promotions
     */
    public function getVendorPromotions(int $vendorId, array $filters = []): array
    {
        try {
            $whereClause = 'WHERE vendor_id = ?';
            $params = [$vendorId];

            if (!empty($filters['status'])) {
                $whereClause .= ' AND status = ?';
                $params[] = $filters['status'];
            }

            if (!empty($filters['type'])) {
                $whereClause .= ' AND promotion_type = ?';
                $params[] = $filters['type'];
            }

            if (!empty($filters['active_only'])) {
                $whereClause .= ' AND status = ? AND start_date <= NOW() AND end_date >= NOW()';
                $params[] = self::STATUS_ACTIVE;
            }

            $sql = "SELECT * FROM vendor_promotions 
                    {$whereClause}
                    ORDER BY created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $promotions = $stmt->fetchAll();

            // Get coupon counts for each promotion
            foreach ($promotions as &$promotion) {
                $promotion['coupon_stats'] = $this->getPromotionCouponStats($promotion['id']);
                $promotion['usage_stats'] = $this->getPromotionUsageStats($promotion['id']);
            }

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'promotions' => $promotions,
                'total_promotions' => count($promotions)
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor promotions', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get vendor promotions: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get promotion analytics
     */
    public function getPromotionAnalytics(int $promotionId): array
    {
        try {
            $promotion = $this->getPromotionDetails($promotionId);
            if (!$promotion) {
                return [
                    'success' => false,
                    'error' => 'Promotion not found'
                ];
            }

            // Get usage statistics
            $usageStats = $this->getDetailedUsageStats($promotionId);

            // Get revenue impact
            $revenueImpact = $this->calculateRevenueImpact($promotionId);

            // Get customer acquisition metrics
            $customerMetrics = $this->getCustomerAcquisitionMetrics($promotionId);

            // Get performance trends
            $performanceTrends = $this->getPromotionPerformanceTrends($promotionId);

            return [
                'success' => true,
                'promotion' => $promotion,
                'usage_statistics' => $usageStats,
                'revenue_impact' => $revenueImpact,
                'customer_metrics' => $customerMetrics,
                'performance_trends' => $performanceTrends
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get promotion analytics', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get promotion analytics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update promotion status
     */
    public function updatePromotionStatus(int $promotionId, string $status): array
    {
        try {
            $validStatuses = [
                self::STATUS_DRAFT,
                self::STATUS_ACTIVE,
                self::STATUS_PAUSED,
                self::STATUS_EXPIRED,
                self::STATUS_CANCELLED
            ];

            if (!in_array($status, $validStatuses)) {
                return [
                    'success' => false,
                    'error' => 'Invalid promotion status'
                ];
            }

            $sql = "UPDATE vendor_promotions 
                    SET status = ?, updated_at = NOW() 
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$status, $promotionId]);

            if ($success && $stmt->rowCount() > 0) {
                // Update related coupons if promotion is cancelled
                if ($status === self::STATUS_CANCELLED) {
                    $this->cancelPromotionCoupons($promotionId);
                }

                return [
                    'success' => true,
                    'message' => 'Promotion status updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Promotion not found or status unchanged'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to update promotion status', [
                'promotion_id' => $promotionId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Promotion status update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate bulk coupons
     */
    public function generateBulkCoupons(int $promotionId, int $quantity, array $options = []): array
    {
        try {
            $promotion = $this->getPromotionDetails($promotionId);
            if (!$promotion) {
                return [
                    'success' => false,
                    'error' => 'Promotion not found'
                ];
            }

            $coupons = [];
            $prefix = $options['prefix'] ?? 'COUP';
            $length = $options['length'] ?? 8;

            for ($i = 0; $i < $quantity; $i++) {
                $couponCode = $this->generateCouponCode($prefix, $length);
                
                $couponId = $this->createCoupon($promotionId, $couponCode, $options);
                if ($couponId) {
                    $coupons[] = [
                        'id' => $couponId,
                        'code' => $couponCode
                    ];
                }
            }

            return [
                'success' => true,
                'promotion_id' => $promotionId,
                'generated_count' => count($coupons),
                'requested_count' => $quantity,
                'coupons' => $coupons
            ];

        } catch (Exception $e) {
            $this->logger->error('Bulk coupon generation failed', [
                'promotion_id' => $promotionId,
                'quantity' => $quantity,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Bulk coupon generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate promotion data
     */
    private function validatePromotionData(array $data): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['name', 'promotion_type', 'start_date', 'end_date'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Validate promotion type
        if (!empty($data['promotion_type'])) {
            $validTypes = [
                self::TYPE_PERCENTAGE_DISCOUNT,
                self::TYPE_FIXED_AMOUNT_DISCOUNT,
                self::TYPE_BUY_X_GET_Y,
                self::TYPE_FREE_DELIVERY,
                self::TYPE_MINIMUM_ORDER_DISCOUNT,
                self::TYPE_FIRST_ORDER_DISCOUNT,
                self::TYPE_BULK_DISCOUNT
            ];

            if (!in_array($data['promotion_type'], $validTypes)) {
                $errors['promotion_type'] = 'Invalid promotion type';
            }
        }

        // Validate dates
        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            $startDate = strtotime($data['start_date']);
            $endDate = strtotime($data['end_date']);
            
            if ($startDate === false) {
                $errors['start_date'] = 'Invalid start date format';
            }
            
            if ($endDate === false) {
                $errors['end_date'] = 'Invalid end date format';
            }
            
            if ($startDate && $endDate && $endDate <= $startDate) {
                $errors['end_date'] = 'End date must be after start date';
            }
        }

        // Validate discount values
        if (!empty($data['discount_value'])) {
            if (!is_numeric($data['discount_value']) || $data['discount_value'] <= 0) {
                $errors['discount_value'] = 'Discount value must be a positive number';
            }
            
            if ($data['promotion_type'] === self::TYPE_PERCENTAGE_DISCOUNT && $data['discount_value'] > 100) {
                $errors['discount_value'] = 'Percentage discount cannot exceed 100%';
            }
        }

        // Validate usage limits
        if (isset($data['usage_limit_per_customer']) && (!is_numeric($data['usage_limit_per_customer']) || $data['usage_limit_per_customer'] < 0)) {
            $errors['usage_limit_per_customer'] = 'Usage limit per customer must be a non-negative number';
        }

        if (isset($data['total_usage_limit']) && (!is_numeric($data['total_usage_limit']) || $data['total_usage_limit'] < 0)) {
            $errors['total_usage_limit'] = 'Total usage limit must be a non-negative number';
        }

        // Set defaults
        $data['status'] = $data['status'] ?? self::STATUS_DRAFT;
        $data['application_scope'] = $data['application_scope'] ?? self::SCOPE_ALL_PRODUCTS;
        $data['usage_limit_per_customer'] = $data['usage_limit_per_customer'] ?? 1;
        $data['generate_coupons'] = $data['generate_coupons'] ?? true;

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Check promotion conflicts
     */
    private function checkPromotionConflicts(int $vendorId, array $promotionData): array
    {
        try {
            // Check for overlapping active promotions of the same type
            $sql = "SELECT id, name FROM vendor_promotions 
                    WHERE vendor_id = ? 
                    AND promotion_type = ? 
                    AND status = ?
                    AND ((start_date <= ? AND end_date >= ?) 
                         OR (start_date <= ? AND end_date >= ?))";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $vendorId,
                $promotionData['promotion_type'],
                self::STATUS_ACTIVE,
                $promotionData['start_date'],
                $promotionData['start_date'],
                $promotionData['end_date'],
                $promotionData['end_date']
            ]);

            $conflicts = $stmt->fetchAll();

            if (!empty($conflicts)) {
                $conflictNames = array_column($conflicts, 'name');
                return [
                    'valid' => false,
                    'message' => 'Promotion conflicts with existing promotions: ' . implode(', ', $conflictNames)
                ];
            }

            return ['valid' => true];

        } catch (Exception $e) {
            $this->logger->error('Failed to check promotion conflicts', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'message' => 'Failed to check promotion conflicts'
            ];
        }
    }

    /**
     * Save promotion
     */
    private function savePromotion(int $vendorId, array $data): ?int
    {
        try {
            $sql = "INSERT INTO vendor_promotions (
                        vendor_id, name, description, promotion_type, discount_value,
                        application_scope, conditions, start_date, end_date,
                        usage_limit_per_customer, total_usage_limit, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $vendorId,
                $data['name'],
                $data['description'] ?? null,
                $data['promotion_type'],
                $data['discount_value'] ?? 0,
                $data['application_scope'],
                json_encode($data['conditions'] ?? []),
                $data['start_date'],
                $data['end_date'],
                $data['usage_limit_per_customer'],
                $data['total_usage_limit'] ?? null,
                $data['status']
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to save promotion', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Generate promotion coupons
     */
    private function generatePromotionCoupons(int $promotionId, array $promotionData): array
    {
        try {
            $couponCount = $promotionData['coupon_count'] ?? 100;
            $prefix = $promotionData['coupon_prefix'] ?? 'PROMO';
            
            return $this->generateBulkCoupons($promotionId, $couponCount, [
                'prefix' => $prefix,
                'length' => 8
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to generate promotion coupons', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate promotion coupons: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get coupon by code
     */
    private function getCouponByCode(string $couponCode, int $vendorId): ?array
    {
        try {
            $sql = "SELECT c.*, p.vendor_id 
                    FROM promotion_coupons c
                    JOIN vendor_promotions p ON c.promotion_id = p.id
                    WHERE c.coupon_code = ? AND p.vendor_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$couponCode, $vendorId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get coupon by code', [
                'coupon_code' => $couponCode,
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get promotion details
     */
    private function getPromotionDetails(int $promotionId): ?array
    {
        try {
            $sql = "SELECT * FROM vendor_promotions WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$promotionId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get promotion details', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Check usage limits
     */
    private function checkUsageLimits(array $coupon, array $promotion, array $orderData): array
    {
        // Check coupon usage limit
        if ($coupon['usage_count'] >= $coupon['usage_limit']) {
            return [
                'valid' => false,
                'error' => 'Coupon usage limit exceeded'
            ];
        }

        // Check total promotion usage limit
        if ($promotion['total_usage_limit'] && $promotion['total_usage_count'] >= $promotion['total_usage_limit']) {
            return [
                'valid' => false,
                'error' => 'Promotion usage limit exceeded'
            ];
        }

        // Check per-customer usage limit
        $customerId = $orderData['customer_id'] ?? null;
        if ($customerId && $promotion['usage_limit_per_customer']) {
            $customerUsageCount = $this->getCustomerPromotionUsageCount($promotion['id'], $customerId);
            if ($customerUsageCount >= $promotion['usage_limit_per_customer']) {
                return [
                    'valid' => false,
                    'error' => 'Customer usage limit exceeded for this promotion'
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Check promotion conditions
     */
    private function checkPromotionConditions(array $promotion, array $orderData): array
    {
        $conditions = json_decode($promotion['conditions'], true) ?? [];

        // Check minimum order amount
        if (!empty($conditions['minimum_order_amount'])) {
            $orderAmount = $orderData['subtotal'] ?? 0;
            if ($orderAmount < $conditions['minimum_order_amount']) {
                return [
                    'valid' => false,
                    'error' => 'Minimum order amount not met'
                ];
            }
        }

        // Check product-specific conditions
        if ($promotion['application_scope'] === self::SCOPE_SPECIFIC_PRODUCTS) {
            $allowedProducts = $conditions['product_ids'] ?? [];
            $orderProducts = array_column($orderData['items'] ?? [], 'product_id');
            
            if (empty(array_intersect($allowedProducts, $orderProducts))) {
                return [
                    'valid' => false,
                    'error' => 'Promotion not applicable to products in cart'
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Calculate discount
     */
    private function calculateDiscount(array $promotion, array $orderData): array
    {
        $orderAmount = $orderData['subtotal'] ?? 0;
        $discountAmount = 0;

        switch ($promotion['promotion_type']) {
            case self::TYPE_PERCENTAGE_DISCOUNT:
                $discountAmount = ($orderAmount * $promotion['discount_value']) / 100;
                break;
                
            case self::TYPE_FIXED_AMOUNT_DISCOUNT:
                $discountAmount = min($promotion['discount_value'], $orderAmount);
                break;
                
            case self::TYPE_FREE_DELIVERY:
                $discountAmount = $orderData['delivery_fee'] ?? 0;
                break;
                
            case self::TYPE_MINIMUM_ORDER_DISCOUNT:
                $conditions = json_decode($promotion['conditions'], true) ?? [];
                if ($orderAmount >= ($conditions['minimum_order_amount'] ?? 0)) {
                    $discountAmount = ($orderAmount * $promotion['discount_value']) / 100;
                }
                break;
                
            default:
                $discountAmount = 0;
        }

        // Apply maximum discount limit if set
        $conditions = json_decode($promotion['conditions'], true) ?? [];
        if (!empty($conditions['maximum_discount_amount'])) {
            $discountAmount = min($discountAmount, $conditions['maximum_discount_amount']);
        }

        return [
            'discount_amount' => $discountAmount,
            'discount_type' => $promotion['promotion_type'],
            'original_amount' => $orderAmount,
            'final_amount' => $orderAmount - $discountAmount
        ];
    }

    /**
     * Calculate payout adjustment
     */
    private function calculatePayoutAdjustment(array $promotion, array $discountCalculation): array
    {
        // Determine who bears the cost of the discount
        $conditions = json_decode($promotion['conditions'], true) ?? [];
        $vendorBearsCost = $conditions['vendor_bears_cost'] ?? true;
        
        if ($vendorBearsCost) {
            // Vendor bears the full cost
            $vendorAdjustment = -$discountCalculation['discount_amount'];
            $platformAdjustment = 0;
        } else {
            // Platform bears the cost (promotional campaign)
            $vendorAdjustment = 0;
            $platformAdjustment = -$discountCalculation['discount_amount'];
        }

        return [
            'vendor_adjustment' => $vendorAdjustment,
            'platform_adjustment' => $platformAdjustment,
            'total_discount' => $discountCalculation['discount_amount'],
            'cost_bearer' => $vendorBearsCost ? 'vendor' : 'platform'
        ];
    }

    /**
     * Record promotion usage
     */
    private function recordPromotionUsage(int $orderId, string $couponCode, array $discountData): ?int
    {
        try {
            $sql = "INSERT INTO promotion_usage (
                        order_id, coupon_code, promotion_id, discount_amount,
                        payout_adjustment, usage_data, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $orderId,
                $couponCode,
                $discountData['promotion']['id'],
                $discountData['discount_calculation']['discount_amount'],
                $discountData['payout_adjustment']['vendor_adjustment'],
                json_encode($discountData)
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to record promotion usage', [
                'order_id' => $orderId,
                'coupon_code' => $couponCode,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Update coupon usage
     */
    private function updateCouponUsage(string $couponCode): void
    {
        try {
            $sql = "UPDATE promotion_coupons 
                    SET usage_count = usage_count + 1, 
                        last_used_at = NOW(),
                        status = CASE 
                            WHEN usage_count + 1 >= usage_limit THEN ?
                            ELSE status 
                        END
                    WHERE coupon_code = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::COUPON_STATUS_USED, $couponCode]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update coupon usage', [
                'coupon_code' => $couponCode,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update order discount
     */
    private function updateOrderDiscount(int $orderId, array $discountData): void
    {
        try {
            $sql = "UPDATE orders 
                    SET discount_amount = ?, 
                        coupon_code = ?,
                        total_amount = total_amount - ?,
                        updated_at = NOW()
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $discountData['discount_calculation']['discount_amount'],
                $discountData['coupon']['coupon_code'],
                $discountData['discount_calculation']['discount_amount'],
                $orderId
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update order discount', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor details
     */
    private function getVendorDetails(int $vendorId): ?array
    {
        try {
            $sql = "SELECT * FROM vendors WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor details', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Generate coupon code
     */
    private function generateCouponCode(string $prefix, int $length): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = $prefix;
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $code;
    }

    /**
     * Create coupon
     */
    private function createCoupon(int $promotionId, string $couponCode, array $options): ?int
    {
        try {
            $sql = "INSERT INTO promotion_coupons (
                        promotion_id, coupon_code, usage_limit, status, created_at
                    ) VALUES (?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $promotionId,
                $couponCode,
                $options['usage_limit'] ?? 1,
                self::COUPON_STATUS_ACTIVE
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to create coupon', [
                'promotion_id' => $promotionId,
                'coupon_code' => $couponCode,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get promotion coupon stats
     */
    private function getPromotionCouponStats(int $promotionId): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_coupons,
                        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_coupons,
                        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as used_coupons,
                        SUM(usage_count) as total_usage_count
                    FROM promotion_coupons 
                    WHERE promotion_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::COUPON_STATUS_ACTIVE, self::COUPON_STATUS_USED, $promotionId]);
            
            return $stmt->fetch() ?: [];

        } catch (Exception $e) {
            $this->logger->error('Failed to get promotion coupon stats', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Get promotion usage stats
     */
    private function getPromotionUsageStats(int $promotionId): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_usage,
                        SUM(discount_amount) as total_discount_given,
                        COUNT(DISTINCT order_id) as unique_orders,
                        AVG(discount_amount) as average_discount
                    FROM promotion_usage 
                    WHERE promotion_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$promotionId]);
            
            return $stmt->fetch() ?: [];

        } catch (Exception $e) {
            $this->logger->error('Failed to get promotion usage stats', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Get customer promotion usage count
     */
    private function getCustomerPromotionUsageCount(int $promotionId, int $customerId): int
    {
        try {
            $sql = "SELECT COUNT(*) 
                    FROM promotion_usage pu
                    JOIN orders o ON pu.order_id = o.id
                    WHERE pu.promotion_id = ? AND o.customer_id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$promotionId, $customerId]);
            
            return (int) $stmt->fetchColumn();

        } catch (Exception $e) {
            $this->logger->error('Failed to get customer promotion usage count', [
                'promotion_id' => $promotionId,
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Cancel promotion coupons
     */
    private function cancelPromotionCoupons(int $promotionId): void
    {
        try {
            $sql = "UPDATE promotion_coupons 
                    SET status = ?, updated_at = NOW() 
                    WHERE promotion_id = ? AND status = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([self::COUPON_STATUS_CANCELLED, $promotionId, self::COUPON_STATUS_ACTIVE]);

        } catch (Exception $e) {
            $this->logger->error('Failed to cancel promotion coupons', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get detailed usage stats
     */
    private function getDetailedUsageStats(int $promotionId): array
    {
        // This would return detailed usage statistics
        // For now, return basic stats
        return $this->getPromotionUsageStats($promotionId);
    }

    /**
     * Calculate revenue impact
     */
    private function calculateRevenueImpact(int $promotionId): array
    {
        // This would calculate the revenue impact of the promotion
        // For now, return a simplified calculation
        return [
            'total_discount_given' => 0,
            'additional_revenue_generated' => 0,
            'net_impact' => 0
        ];
    }

    /**
     * Get customer acquisition metrics
     */
    private function getCustomerAcquisitionMetrics(int $promotionId): array
    {
        // This would return customer acquisition metrics
        // For now, return basic metrics
        return [
            'new_customers_acquired' => 0,
            'repeat_customers' => 0,
            'customer_retention_rate' => 0
        ];
    }

    /**
     * Get promotion performance trends
     */
    private function getPromotionPerformanceTrends(int $promotionId): array
    {
        // This would return performance trends over time
        // For now, return empty array
        return [];
    }

    /**
     * Create promotion tables
     */
    private function createPromotionTables(): void
    {
        // Vendor promotions table
        $sql1 = "CREATE TABLE IF NOT EXISTS vendor_promotions (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            promotion_type VARCHAR(50) NOT NULL,
            discount_value DECIMAL(10,2) DEFAULT 0,
            application_scope VARCHAR(50) DEFAULT 'all_products',
            conditions JSON,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            usage_limit_per_customer INT DEFAULT 1,
            total_usage_limit INT NULL,
            total_usage_count INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_promotion_type (promotion_type),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date),
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
        )";

        // Promotion coupons table
        $sql2 = "CREATE TABLE IF NOT EXISTS promotion_coupons (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            promotion_id BIGINT NOT NULL,
            coupon_code VARCHAR(50) UNIQUE NOT NULL,
            usage_limit INT DEFAULT 1,
            usage_count INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            last_used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_promotion_id (promotion_id),
            INDEX idx_coupon_code (coupon_code),
            INDEX idx_status (status),
            FOREIGN KEY (promotion_id) REFERENCES vendor_promotions(id) ON DELETE CASCADE
        )";

        // Promotion usage table
        $sql3 = "CREATE TABLE IF NOT EXISTS promotion_usage (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            coupon_code VARCHAR(50) NOT NULL,
            promotion_id BIGINT NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL,
            payout_adjustment DECIMAL(10,2) DEFAULT 0,
            usage_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_coupon_code (coupon_code),
            INDEX idx_promotion_id (promotion_id),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (promotion_id) REFERENCES vendor_promotions(id) ON DELETE CASCADE
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
        $this->db->exec($sql3);
    }
}