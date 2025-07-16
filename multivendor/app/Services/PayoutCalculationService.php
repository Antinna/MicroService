<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Payout calculation service with fee deductions and payment service integration
 */
class PayoutCalculationService
{
    private PDO $db;
    private Logger $logger;

    // Fee types
    const FEE_TYPE_PLATFORM = 'platform';
    const FEE_TYPE_PAYMENT_PROCESSING = 'payment_processing';
    const FEE_TYPE_DELIVERY = 'delivery';
    const FEE_TYPE_MARKETING = 'marketing';
    const FEE_TYPE_SUBSCRIPTION = 'subscription';
    const FEE_TYPE_PENALTY = 'penalty';

    // Payout statuses
    const STATUS_PENDING = 'pending';
    const STATUS_CALCULATED = 'calculated';
    const STATUS_APPROVED = 'approved';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_DISPUTED = 'disputed';

    // Payout frequencies
    const FREQUENCY_DAILY = 'daily';
    const FREQUENCY_WEEKLY = 'weekly';
    const FREQUENCY_BIWEEKLY = 'biweekly';
    const FREQUENCY_MONTHLY = 'monthly';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->createPayoutTables();
    }

    /**
     * Calculate vendor payout for a specific period
     */
    public function calculateVendorPayout(int $vendorId, string $startDate, string $endDate, array $options = []): array
    {
        try {
            $this->logger->info('Calculating vendor payout', [
                'vendor_id' => $vendorId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Get vendor details
            $vendor = $this->getVendorDetails($vendorId);
            if (!$vendor) {
                return [
                    'success' => false,
                    'error' => 'Vendor not found'
                ];
            }

            // Get orders for the period
            $orders = $this->getVendorOrdersForPeriod($vendorId, $startDate, $endDate);
            
            if (empty($orders)) {
                return [
                    'success' => true,
                    'message' => 'No orders found for the specified period',
                    'payout_amount' => 0,
                    'orders_count' => 0
                ];
            }

            // Calculate gross revenue
            $grossRevenue = $this->calculateGrossRevenue($orders);

            // Calculate fees
            $fees = $this->calculateFees($vendorId, $orders, $grossRevenue, $options);

            // Calculate net payout
            $netPayout = $grossRevenue['total'] - $fees['total'];

            // Get vendor fee structure
            $feeStructure = $this->getVendorFeeStructure($vendorId);

            // Create payout calculation record
            $payoutCalculation = [
                'vendor_id' => $vendorId,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'orders_count' => count($orders),
                'gross_revenue' => $grossRevenue['total'],
                'total_fees' => $fees['total'],
                'net_payout' => max(0, $netPayout), // Ensure non-negative payout
                'fee_breakdown' => $fees['breakdown'],
                'revenue_breakdown' => $grossRevenue['breakdown'],
                'fee_structure' => $feeStructure,
                'calculation_date' => date('Y-m-d H:i:s'),
                'status' => self::STATUS_CALCULATED
            ];

            // Save calculation
            $payoutId = $this->savePayoutCalculation($payoutCalculation);

            return [
                'success' => true,
                'payout_id' => $payoutId,
                'vendor_id' => $vendorId,
                'vendor_name' => $vendor['business_name'],
                'period' => ['start' => $startDate, 'end' => $endDate],
                'calculation' => $payoutCalculation
            ];

        } catch (Exception $e) {
            $this->logger->error('Vendor payout calculation failed', [
                'vendor_id' => $vendorId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Payout calculation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate bulk payouts for multiple vendors
     */
    public function calculateBulkPayouts(array $vendorIds, string $startDate, string $endDate, array $options = []): array
    {
        try {
            $results = [];
            $successCount = 0;
            $failureCount = 0;
            $totalPayoutAmount = 0;

            foreach ($vendorIds as $vendorId) {
                $payoutResult = $this->calculateVendorPayout($vendorId, $startDate, $endDate, $options);
                
                $results[] = [
                    'vendor_id' => $vendorId,
                    'result' => $payoutResult
                ];

                if ($payoutResult['success']) {
                    $successCount++;
                    $totalPayoutAmount += $payoutResult['calculation']['net_payout'] ?? 0;
                } else {
                    $failureCount++;
                }

                // Small delay to prevent overwhelming the system
                usleep(10000); // 10ms delay
            }

            return [
                'success' => $successCount > 0,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'total_vendors' => count($vendorIds),
                'successful_calculations' => $successCount,
                'failed_calculations' => $failureCount,
                'total_payout_amount' => $totalPayoutAmount,
                'results' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Bulk payout calculation failed', [
                'vendor_count' => count($vendorIds),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Bulk payout calculation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process payout payment through payment service
     */
    public function processPayoutPayment(int $payoutId, array $paymentOptions = []): array
    {
        try {
            // Get payout details
            $payout = $this->getPayoutDetails($payoutId);
            if (!$payout) {
                return [
                    'success' => false,
                    'error' => 'Payout not found'
                ];
            }

            // Validate payout status
            if ($payout['status'] !== self::STATUS_APPROVED) {
                return [
                    'success' => false,
                    'error' => 'Payout must be approved before processing payment'
                ];
            }

            // Get vendor payment details
            $vendor = $this->getVendorDetails($payout['vendor_id']);
            $paymentDetails = $this->getVendorPaymentDetails($payout['vendor_id']);

            if (!$paymentDetails) {
                return [
                    'success' => false,
                    'error' => 'Vendor payment details not found'
                ];
            }

            // Update payout status to processing
            $this->updatePayoutStatus($payoutId, self::STATUS_PROCESSING);

            // Process payment through payment service API
            $paymentResult = $this->callPaymentServiceAPI($payout, $paymentDetails, $paymentOptions);

            // Update payout with payment result
            $finalStatus = $paymentResult['success'] ? self::STATUS_COMPLETED : self::STATUS_FAILED;
            $this->updatePayoutStatus($payoutId, $finalStatus, $paymentResult);

            // Send payout notification
            $this->sendPayoutNotification($payout, $paymentResult);

            return [
                'success' => $paymentResult['success'],
                'payout_id' => $payoutId,
                'payment_result' => $paymentResult,
                'final_status' => $finalStatus
            ];

        } catch (Exception $e) {
            // Update payout status to failed
            $this->updatePayoutStatus($payoutId, self::STATUS_FAILED, [
                'error' => $e->getMessage(),
                'failed_at' => date('Y-m-d H:i:s')
            ]);

            $this->logger->error('Payout payment processing failed', [
                'payout_id' => $payoutId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Payout payment processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get payout history for vendor
     */
    public function getVendorPayoutHistory(int $vendorId, int $months = 12, array $filters = []): array
    {
        try {
            $whereClause = 'WHERE vendor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)';
            $params = [$vendorId, $months];

            if (!empty($filters['status'])) {
                $whereClause .= ' AND status = ?';
                $params[] = $filters['status'];
            }

            if (!empty($filters['min_amount'])) {
                $whereClause .= ' AND net_payout >= ?';
                $params[] = $filters['min_amount'];
            }

            $sql = "SELECT * FROM vendor_payouts 
                    {$whereClause}
                    ORDER BY created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $payouts = $stmt->fetchAll();

            // Calculate summary statistics
            $totalPayouts = count($payouts);
            $totalAmount = array_sum(array_column($payouts, 'net_payout'));
            $completedPayouts = array_filter($payouts, fn($p) => $p['status'] === self::STATUS_COMPLETED);
            $completedAmount = array_sum(array_column($completedPayouts, 'net_payout'));

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'period_months' => $months,
                'payouts' => $payouts,
                'summary' => [
                    'total_payouts' => $totalPayouts,
                    'completed_payouts' => count($completedPayouts),
                    'total_amount' => $totalAmount,
                    'completed_amount' => $completedAmount,
                    'average_payout' => $totalPayouts > 0 ? $totalAmount / $totalPayouts : 0,
                    'completion_rate' => $totalPayouts > 0 ? (count($completedPayouts) / $totalPayouts) * 100 : 0
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor payout history', [
                'vendor_id' => $vendorId,
                'months' => $months,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get payout history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get payout analytics and insights
     */
    public function getPayoutAnalytics(array $filters = []): array
    {
        try {
            $whereClause = 'WHERE 1=1';
            $params = [];

            if (!empty($filters['vendor_id'])) {
                $whereClause .= ' AND vendor_id = ?';
                $params[] = $filters['vendor_id'];
            }

            if (!empty($filters['start_date'])) {
                $whereClause .= ' AND period_start >= ?';
                $params[] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $whereClause .= ' AND period_end <= ?';
                $params[] = $filters['end_date'];
            }

            // Get payout statistics
            $sql = "SELECT 
                        COUNT(*) as total_payouts,
                        SUM(gross_revenue) as total_gross_revenue,
                        SUM(total_fees) as total_fees,
                        SUM(net_payout) as total_net_payout,
                        AVG(net_payout) as average_payout,
                        MIN(net_payout) as min_payout,
                        MAX(net_payout) as max_payout,
                        COUNT(DISTINCT vendor_id) as unique_vendors
                    FROM vendor_payouts 
                    {$whereClause}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch();

            // Get payout trends by month
            $trendSql = "SELECT 
                            DATE_FORMAT(period_start, '%Y-%m') as month,
                            COUNT(*) as payout_count,
                            SUM(net_payout) as total_amount,
                            AVG(net_payout) as average_amount
                        FROM vendor_payouts 
                        {$whereClause}
                        GROUP BY DATE_FORMAT(period_start, '%Y-%m')
                        ORDER BY month DESC
                        LIMIT 12";

            $stmt = $this->db->prepare($trendSql);
            $stmt->execute($params);
            $trends = $stmt->fetchAll();

            // Get fee breakdown analysis
            $feeAnalysisSql = "SELECT 
                                JSON_EXTRACT(fee_breakdown, '$.platform') as platform_fees,
                                JSON_EXTRACT(fee_breakdown, '$.payment_processing') as payment_fees,
                                JSON_EXTRACT(fee_breakdown, '$.delivery') as delivery_fees
                            FROM vendor_payouts 
                            {$whereClause}";

            $stmt = $this->db->prepare($feeAnalysisSql);
            $stmt->execute($params);
            $feeData = $stmt->fetchAll();

            $feeAnalysis = [
                'total_platform_fees' => array_sum(array_column($feeData, 'platform_fees')),
                'total_payment_fees' => array_sum(array_column($feeData, 'payment_fees')),
                'total_delivery_fees' => array_sum(array_column($feeData, 'delivery_fees'))
            ];

            return [
                'success' => true,
                'statistics' => $stats,
                'monthly_trends' => $trends,
                'fee_analysis' => $feeAnalysis,
                'filters_applied' => $filters
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get payout analytics', [
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get payout analytics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update vendor fee structure
     */
    public function updateVendorFeeStructure(int $vendorId, array $feeStructure): array
    {
        try {
            // Validate fee structure
            $validation = $this->validateFeeStructure($feeStructure);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            $sql = "INSERT INTO vendor_fee_structures 
                    (vendor_id, fee_structure, effective_from, created_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    fee_structure = VALUES(fee_structure),
                    effective_from = VALUES(effective_from),
                    updated_at = NOW()";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $vendorId,
                json_encode($feeStructure),
                $feeStructure['effective_from'] ?? date('Y-m-d')
            ]);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Vendor fee structure updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update vendor fee structure'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to update vendor fee structure', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Fee structure update failed: ' . $e->getMessage()
            ];
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
     * Get vendor orders for period
     */
    private function getVendorOrdersForPeriod(int $vendorId, string $startDate, string $endDate): array
    {
        try {
            $sql = "SELECT o.*, oi.product_id, oi.quantity, oi.unit_price, oi.total_price
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    WHERE o.vendor_id = ?
                    AND o.status IN ('completed', 'delivered')
                    AND DATE(o.created_at) BETWEEN ? AND ?
                    ORDER BY o.created_at";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $startDate, $endDate]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor orders for period', [
                'vendor_id' => $vendorId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Calculate gross revenue
     */
    private function calculateGrossRevenue(array $orders): array
    {
        $productRevenue = 0;
        $deliveryRevenue = 0;
        $orderCount = 0;
        $uniqueOrders = [];

        foreach ($orders as $order) {
            $orderId = $order['id'];
            
            // Count unique orders
            if (!isset($uniqueOrders[$orderId])) {
                $uniqueOrders[$orderId] = true;
                $orderCount++;
                $deliveryRevenue += $order['delivery_fee'] ?? 0;
            }
            
            $productRevenue += $order['total_price'];
        }

        $totalRevenue = $productRevenue + $deliveryRevenue;

        return [
            'total' => $totalRevenue,
            'breakdown' => [
                'product_revenue' => $productRevenue,
                'delivery_revenue' => $deliveryRevenue,
                'order_count' => $orderCount,
                'average_order_value' => $orderCount > 0 ? $totalRevenue / $orderCount : 0
            ]
        ];
    }

    /**
     * Calculate fees
     */
    private function calculateFees(int $vendorId, array $orders, array $grossRevenue, array $options): array
    {
        $feeStructure = $this->getVendorFeeStructure($vendorId);
        $fees = [];

        // Platform fee (percentage of gross revenue)
        $platformFeeRate = $feeStructure['platform_fee_percentage'] ?? 5.0;
        $fees[self::FEE_TYPE_PLATFORM] = ($grossRevenue['total'] * $platformFeeRate) / 100;

        // Payment processing fee
        $paymentFeeRate = $feeStructure['payment_processing_percentage'] ?? 2.5;
        $paymentFixedFee = $feeStructure['payment_fixed_fee'] ?? 0.30;
        $fees[self::FEE_TYPE_PAYMENT_PROCESSING] = 
            ($grossRevenue['total'] * $paymentFeeRate / 100) + 
            ($grossRevenue['breakdown']['order_count'] * $paymentFixedFee);

        // Delivery fee (if applicable)
        $deliveryFeeRate = $feeStructure['delivery_fee_percentage'] ?? 0;
        $fees[self::FEE_TYPE_DELIVERY] = ($grossRevenue['breakdown']['delivery_revenue'] * $deliveryFeeRate) / 100;

        // Marketing fee (if applicable)
        $marketingFeeRate = $feeStructure['marketing_fee_percentage'] ?? 0;
        $fees[self::FEE_TYPE_MARKETING] = ($grossRevenue['total'] * $marketingFeeRate) / 100;

        // Subscription fee (monthly)
        $subscriptionFee = $feeStructure['monthly_subscription_fee'] ?? 0;
        $fees[self::FEE_TYPE_SUBSCRIPTION] = $subscriptionFee;

        // Penalty fees (if any)
        $penaltyFees = $this->calculatePenaltyFees($vendorId, $orders);
        $fees[self::FEE_TYPE_PENALTY] = $penaltyFees;

        $totalFees = array_sum($fees);

        return [
            'total' => $totalFees,
            'breakdown' => $fees
        ];
    }

    /**
     * Calculate penalty fees
     */
    private function calculatePenaltyFees(int $vendorId, array $orders): float
    {
        // This would calculate penalty fees based on various factors
        // For now, return 0
        return 0.0;
    }

    /**
     * Get vendor fee structure
     */
    private function getVendorFeeStructure(int $vendorId): array
    {
        try {
            $sql = "SELECT fee_structure FROM vendor_fee_structures 
                    WHERE vendor_id = ? 
                    ORDER BY effective_from DESC 
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $result = $stmt->fetch();

            if ($result) {
                return json_decode($result['fee_structure'], true);
            }

            // Return default fee structure
            return [
                'platform_fee_percentage' => 5.0,
                'payment_processing_percentage' => 2.5,
                'payment_fixed_fee' => 0.30,
                'delivery_fee_percentage' => 0,
                'marketing_fee_percentage' => 0,
                'monthly_subscription_fee' => 0
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor fee structure', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Save payout calculation
     */
    private function savePayoutCalculation(array $calculation): ?int
    {
        try {
            $sql = "INSERT INTO vendor_payouts (
                        vendor_id, period_start, period_end, orders_count,
                        gross_revenue, total_fees, net_payout, fee_breakdown,
                        revenue_breakdown, fee_structure, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $calculation['vendor_id'],
                $calculation['period_start'],
                $calculation['period_end'],
                $calculation['orders_count'],
                $calculation['gross_revenue'],
                $calculation['total_fees'],
                $calculation['net_payout'],
                json_encode($calculation['fee_breakdown']),
                json_encode($calculation['revenue_breakdown']),
                json_encode($calculation['fee_structure']),
                $calculation['status']
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to save payout calculation', [
                'vendor_id' => $calculation['vendor_id'],
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get payout details
     */
    private function getPayoutDetails(int $payoutId): ?array
    {
        try {
            $sql = "SELECT * FROM vendor_payouts WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$payoutId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get payout details', [
                'payout_id' => $payoutId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Get vendor payment details
     */
    private function getVendorPaymentDetails(int $vendorId): ?array
    {
        try {
            $sql = "SELECT * FROM vendor_payment_details WHERE vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get vendor payment details', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Update payout status
     */
    private function updatePayoutStatus(int $payoutId, string $status, array $additionalData = []): void
    {
        try {
            $sql = "UPDATE vendor_payouts 
                    SET status = ?, 
                        payment_response = ?,
                        updated_at = NOW()
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $status,
                json_encode($additionalData),
                $payoutId
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update payout status', [
                'payout_id' => $payoutId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Call payment service API
     */
    private function callPaymentServiceAPI(array $payout, array $paymentDetails, array $options): array
    {
        try {
            // In a real implementation, this would make HTTP calls to the payment service
            // For now, we'll simulate the API call
            
            $this->logger->info('Calling payment service API', [
                'payout_id' => $payout['id'],
                'amount' => $payout['net_payout']
            ]);

            // Simulate API call (90% success rate)
            $success = (rand(1, 100) <= 90);

            if ($success) {
                return [
                    'success' => true,
                    'transaction_id' => 'PAY_' . uniqid(),
                    'amount_transferred' => $payout['net_payout'],
                    'transfer_fee' => 0,
                    'net_amount' => $payout['net_payout'],
                    'processed_at' => date('Y-m-d H:i:s'),
                    'payment_method' => $paymentDetails['payment_method'] ?? 'bank_transfer'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Payment processing failed (simulated failure)',
                    'error_code' => 'PAYMENT_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Payment service API call failed', [
                'payout_id' => $payout['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Payment service API call failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send payout notification
     */
    private function sendPayoutNotification(array $payout, array $paymentResult): void
    {
        try {
            // This would send payout notifications to vendors
            // For now, just log the notification
            $this->logger->info('Sending payout notification', [
                'vendor_id' => $payout['vendor_id'],
                'payout_id' => $payout['id'],
                'amount' => $payout['net_payout'],
                'success' => $paymentResult['success']
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to send payout notification', [
                'payout_id' => $payout['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate fee structure
     */
    private function validateFeeStructure(array $feeStructure): array
    {
        $errors = [];

        // Validate percentage fields
        $percentageFields = [
            'platform_fee_percentage',
            'payment_processing_percentage',
            'delivery_fee_percentage',
            'marketing_fee_percentage'
        ];

        foreach ($percentageFields as $field) {
            if (isset($feeStructure[$field])) {
                $value = $feeStructure[$field];
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    $errors[$field] = 'Must be a percentage between 0 and 100';
                }
            }
        }

        // Validate fixed fee fields
        $fixedFeeFields = [
            'payment_fixed_fee',
            'monthly_subscription_fee'
        ];

        foreach ($fixedFeeFields as $field) {
            if (isset($feeStructure[$field])) {
                $value = $feeStructure[$field];
                if (!is_numeric($value) || $value < 0) {
                    $errors[$field] = 'Must be a non-negative number';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Create payout tables
     */
    private function createPayoutTables(): void
    {
        // Vendor payouts table
        $sql1 = "CREATE TABLE IF NOT EXISTS vendor_payouts (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            orders_count INT NOT NULL,
            gross_revenue DECIMAL(12,2) NOT NULL,
            total_fees DECIMAL(12,2) NOT NULL,
            net_payout DECIMAL(12,2) NOT NULL,
            fee_breakdown JSON,
            revenue_breakdown JSON,
            fee_structure JSON,
            status VARCHAR(20) DEFAULT 'pending',
            payment_response JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_period (period_start, period_end),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
        )";

        // Vendor fee structures table
        $sql2 = "CREATE TABLE IF NOT EXISTS vendor_fee_structures (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL UNIQUE,
            fee_structure JSON NOT NULL,
            effective_from DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_effective_from (effective_from),
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
        )";

        // Vendor payment details table
        $sql3 = "CREATE TABLE IF NOT EXISTS vendor_payment_details (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL UNIQUE,
            payment_method VARCHAR(50) NOT NULL,
            bank_account_number VARCHAR(100),
            bank_routing_number VARCHAR(50),
            bank_name VARCHAR(100),
            account_holder_name VARCHAR(100),
            upi_id VARCHAR(100),
            wallet_id VARCHAR(100),
            payment_details JSON,
            is_verified BOOLEAN DEFAULT FALSE,
            verified_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_payment_method (payment_method),
            INDEX idx_is_verified (is_verified),
            FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
        $this->db->exec($sql3);
    }
}