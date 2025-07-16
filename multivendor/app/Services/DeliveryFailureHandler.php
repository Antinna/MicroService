<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Delivery failure handler for missed delivery processing and automatic refunds
 */
class DeliveryFailureHandler
{
    private PDO $db;
    private Logger $logger;
    private DeliveryNotificationService $notificationService;

    // Failure reasons
    const REASON_CUSTOMER_UNAVAILABLE = 'customer_unavailable';
    const REASON_ADDRESS_NOT_FOUND = 'address_not_found';
    const REASON_CUSTOMER_REFUSED = 'customer_refused';
    const REASON_PRODUCT_DAMAGED = 'product_damaged';
    const REASON_VEHICLE_BREAKDOWN = 'vehicle_breakdown';
    const REASON_WEATHER_CONDITIONS = 'weather_conditions';
    const REASON_SECURITY_ISSUES = 'security_issues';
    const REASON_OTHER = 'other';

    // Failure actions
    const ACTION_RETRY = 'retry';
    const ACTION_REFUND = 'refund';
    const ACTION_RESCHEDULE = 'reschedule';
    const ACTION_RETURN_TO_VENDOR = 'return_to_vendor';
    const ACTION_MANUAL_REVIEW = 'manual_review';

    // Refund types
    const REFUND_FULL = 'full';
    const REFUND_PARTIAL = 'partial';
    const REFUND_DELIVERY_FEE_ONLY = 'delivery_fee_only';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->notificationService = new DeliveryNotificationService();
        $this->createDeliveryFailureTables();
    }

    /**
     * Handle delivery failure
     */
    public function handleDeliveryFailure(int $orderId, string $failureReason, array $failureDetails = []): array
    {
        try {
            $this->logger->info('Handling delivery failure', [
                'order_id' => $orderId,
                'failure_reason' => $failureReason
            ]);

            // Get order details
            $order = $this->getOrderDetails($orderId);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found'
                ];
            }

            // Record delivery failure
            $failureId = $this->recordDeliveryFailure($orderId, $failureReason, $failureDetails);
            if (!$failureId) {
                return [
                    'success' => false,
                    'error' => 'Failed to record delivery failure'
                ];
            }

            // Determine appropriate action based on failure reason and order history
            $actionPlan = $this->determineFailureAction($order, $failureReason, $failureDetails);

            // Execute the action plan
            $actionResult = $this->executeFailureAction($orderId, $failureId, $actionPlan);

            // Send notifications
            $notificationResult = $this->sendFailureNotifications($order, $failureReason, $actionPlan);

            // Update order status
            $this->updateOrderStatus($orderId, 'delivery_failed', [
                'failure_reason' => $failureReason,
                'action_taken' => $actionPlan['action'],
                'failure_id' => $failureId
            ]);

            return [
                'success' => true,
                'order_id' => $orderId,
                'failure_id' => $failureId,
                'failure_reason' => $failureReason,
                'action_plan' => $actionPlan,
                'action_result' => $actionResult,
                'notification_result' => $notificationResult
            ];

        } catch (Exception $e) {
            $this->logger->error('Delivery failure handling failed', [
                'order_id' => $orderId,
                'failure_reason' => $failureReason,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Delivery failure handling failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process automatic refund
     */
    public function processAutomaticRefund(int $orderId, string $refundType = self::REFUND_FULL, array $refundDetails = []): array
    {
        try {
            // Get order details
            $order = $this->getOrderDetails($orderId);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found'
                ];
            }

            // Calculate refund amount
            $refundCalculation = $this->calculateRefundAmount($order, $refundType, $refundDetails);
            
            if ($refundCalculation['amount'] <= 0) {
                return [
                    'success' => false,
                    'error' => 'No refund amount calculated'
                ];
            }

            // Create refund record
            $refundId = $this->createRefundRecord($orderId, $refundCalculation);
            
            if (!$refundId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create refund record'
                ];
            }

            // Process refund through payment service
            $paymentResult = $this->processRefundPayment($order, $refundCalculation, $refundId);

            // Update refund status
            $this->updateRefundStatus($refundId, $paymentResult['success'] ? 'completed' : 'failed', $paymentResult);

            // Send refund notifications
            $notificationResult = $this->sendRefundNotifications($order, $refundCalculation, $paymentResult);

            return [
                'success' => $paymentResult['success'],
                'refund_id' => $refundId,
                'refund_amount' => $refundCalculation['amount'],
                'refund_type' => $refundType,
                'payment_result' => $paymentResult,
                'notification_result' => $notificationResult
            ];

        } catch (Exception $e) {
            $this->logger->error('Automatic refund processing failed', [
                'order_id' => $orderId,
                'refund_type' => $refundType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Automatic refund processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Schedule delivery retry
     */
    public function scheduleDeliveryRetry(int $orderId, array $retryOptions = []): array
    {
        try {
            // Get order details
            $order = $this->getOrderDetails($orderId);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found'
                ];
            }

            // Check retry eligibility
            $eligibilityCheck = $this->checkRetryEligibility($orderId);
            if (!$eligibilityCheck['eligible']) {
                return [
                    'success' => false,
                    'error' => $eligibilityCheck['reason']
                ];
            }

            // Determine retry schedule
            $retrySchedule = $this->determineRetrySchedule($order, $retryOptions);

            // Create retry record
            $retryId = $this->createRetryRecord($orderId, $retrySchedule);
            
            if (!$retryId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create retry record'
                ];
            }

            // Update delivery slot booking if needed
            if (!empty($retrySchedule['new_delivery_slot_id'])) {
                $slotBookingResult = $this->updateDeliverySlotBooking($orderId, $retrySchedule);
            }

            // Send retry notifications
            $notificationResult = $this->sendRetryNotifications($order, $retrySchedule);

            return [
                'success' => true,
                'retry_id' => $retryId,
                'retry_schedule' => $retrySchedule,
                'notification_result' => $notificationResult
            ];

        } catch (Exception $e) {
            $this->logger->error('Delivery retry scheduling failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Delivery retry scheduling failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get delivery failure statistics
     */
    public function getDeliveryFailureStatistics(int $days = 30, array $filters = []): array
    {
        try {
            $whereClause = 'WHERE df.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params = [$days];

            if (!empty($filters['vendor_id'])) {
                $whereClause .= ' AND o.vendor_id = ?';
                $params[] = $filters['vendor_id'];
            }

            if (!empty($filters['failure_reason'])) {
                $whereClause .= ' AND df.failure_reason = ?';
                $params[] = $filters['failure_reason'];
            }

            // Get failure statistics by reason
            $sql = "SELECT 
                        df.failure_reason,
                        COUNT(*) as failure_count,
                        COUNT(DISTINCT df.order_id) as unique_orders,
                        AVG(o.total_amount) as avg_order_value,
                        SUM(CASE WHEN df.action_taken = 'refund' THEN 1 ELSE 0 END) as refunds_issued,
                        SUM(CASE WHEN df.action_taken = 'retry' THEN 1 ELSE 0 END) as retries_scheduled
                    FROM delivery_failures df
                    JOIN orders o ON df.order_id = o.id
                    {$whereClause}
                    GROUP BY df.failure_reason
                    ORDER BY failure_count DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $failureStats = $stmt->fetchAll();

            // Get daily failure trends
            $trendSql = "SELECT 
                            DATE(df.created_at) as date,
                            COUNT(*) as failure_count,
                            COUNT(DISTINCT df.order_id) as unique_orders
                        FROM delivery_failures df
                        JOIN orders o ON df.order_id = o.id
                        {$whereClause}
                        GROUP BY DATE(df.created_at)
                        ORDER BY date DESC";

            $stmt = $this->db->prepare($trendSql);
            $stmt->execute($params);
            $dailyTrends = $stmt->fetchAll();

            // Calculate overall statistics
            $totalFailures = array_sum(array_column($failureStats, 'failure_count'));
            $totalRefunds = array_sum(array_column($failureStats, 'refunds_issued'));
            $totalRetries = array_sum(array_column($failureStats, 'retries_scheduled'));

            return [
                'success' => true,
                'period_days' => $days,
                'failure_statistics' => $failureStats,
                'daily_trends' => $dailyTrends,
                'summary' => [
                    'total_failures' => $totalFailures,
                    'total_refunds' => $totalRefunds,
                    'total_retries' => $totalRetries,
                    'refund_rate_percentage' => $totalFailures > 0 ? ($totalRefunds / $totalFailures) * 100 : 0,
                    'retry_rate_percentage' => $totalFailures > 0 ? ($totalRetries / $totalFailures) * 100 : 0
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get delivery failure statistics', [
                'days' => $days,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get delivery failure statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get refund history
     */
    public function getRefundHistory(int $days = 30, array $filters = []): array
    {
        try {
            $whereClause = 'WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params = [$days];

            if (!empty($filters['vendor_id'])) {
                $whereClause .= ' AND o.vendor_id = ?';
                $params[] = $filters['vendor_id'];
            }

            if (!empty($filters['refund_status'])) {
                $whereClause .= ' AND r.status = ?';
                $params[] = $filters['refund_status'];
            }

            $sql = "SELECT 
                        r.*,
                        o.order_number,
                        o.total_amount as order_amount,
                        v.business_name as vendor_name,
                        COALESCE(c.name, o.customer_name) as customer_name
                    FROM refunds r
                    JOIN orders o ON r.order_id = o.id
                    LEFT JOIN vendors v ON o.vendor_id = v.id
                    LEFT JOIN customers c ON o.customer_id = c.id
                    {$whereClause}
                    ORDER BY r.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $refunds = $stmt->fetchAll();

            // Calculate refund statistics
            $totalRefundAmount = array_sum(array_column($refunds, 'refund_amount'));
            $completedRefunds = array_filter($refunds, fn($r) => $r['status'] === 'completed');
            $completedRefundAmount = array_sum(array_column($completedRefunds, 'refund_amount'));

            return [
                'success' => true,
                'period_days' => $days,
                'refunds' => $refunds,
                'summary' => [
                    'total_refunds' => count($refunds),
                    'completed_refunds' => count($completedRefunds),
                    'total_refund_amount' => $totalRefundAmount,
                    'completed_refund_amount' => $completedRefundAmount,
                    'average_refund_amount' => count($refunds) > 0 ? $totalRefundAmount / count($refunds) : 0
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get refund history', [
                'days' => $days,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get refund history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get order details
     */
    private function getOrderDetails(int $orderId): ?array
    {
        try {
            $sql = "SELECT o.*, v.business_name as vendor_name
                    FROM orders o
                    LEFT JOIN vendors v ON o.vendor_id = v.id
                    WHERE o.id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            $this->logger->error('Failed to get order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Record delivery failure
     */
    private function recordDeliveryFailure(int $orderId, string $failureReason, array $failureDetails): ?int
    {
        try {
            $sql = "INSERT INTO delivery_failures 
                    (order_id, failure_reason, failure_details, reported_at, created_at)
                    VALUES (?, ?, ?, NOW(), NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $orderId,
                $failureReason,
                json_encode($failureDetails)
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to record delivery failure', [
                'order_id' => $orderId,
                'failure_reason' => $failureReason,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Determine appropriate failure action
     */
    private function determineFailureAction(array $order, string $failureReason, array $failureDetails): array
    {
        // Get failure history for this order
        $failureHistory = $this->getOrderFailureHistory($order['id']);
        $failureCount = count($failureHistory);

        // Determine action based on failure reason and history
        $action = match($failureReason) {
            self::REASON_CUSTOMER_UNAVAILABLE => $failureCount < 2 ? self::ACTION_RETRY : self::ACTION_REFUND,
            self::REASON_ADDRESS_NOT_FOUND => $failureCount < 1 ? self::ACTION_RETRY : self::ACTION_REFUND,
            self::REASON_CUSTOMER_REFUSED => self::ACTION_REFUND,
            self::REASON_PRODUCT_DAMAGED => self::ACTION_REFUND,
            self::REASON_VEHICLE_BREAKDOWN => self::ACTION_RETRY,
            self::REASON_WEATHER_CONDITIONS => self::ACTION_RESCHEDULE,
            self::REASON_SECURITY_ISSUES => self::ACTION_MANUAL_REVIEW,
            default => $failureCount < 2 ? self::ACTION_RETRY : self::ACTION_REFUND
        };

        // Determine refund type if refund action
        $refundType = match($failureReason) {
            self::REASON_CUSTOMER_REFUSED => self::REFUND_DELIVERY_FEE_ONLY,
            self::REASON_PRODUCT_DAMAGED => self::REFUND_FULL,
            default => self::REFUND_FULL
        };

        return [
            'action' => $action,
            'refund_type' => $refundType,
            'failure_count' => $failureCount,
            'reason' => $this->getActionReason($action, $failureReason, $failureCount)
        ];
    }

    /**
     * Execute failure action
     */
    private function executeFailureAction(int $orderId, int $failureId, array $actionPlan): array
    {
        try {
            $result = match($actionPlan['action']) {
                self::ACTION_RETRY => $this->scheduleDeliveryRetry($orderId),
                self::ACTION_REFUND => $this->processAutomaticRefund($orderId, $actionPlan['refund_type']),
                self::ACTION_RESCHEDULE => $this->scheduleDeliveryRetry($orderId, ['delay_days' => 1]),
                self::ACTION_RETURN_TO_VENDOR => $this->initiateReturnToVendor($orderId),
                self::ACTION_MANUAL_REVIEW => $this->flagForManualReview($orderId, $failureId),
                default => ['success' => false, 'error' => 'Unknown action: ' . $actionPlan['action']]
            };

            // Update failure record with action taken
            $this->updateFailureRecord($failureId, $actionPlan['action'], $result);

            return $result;

        } catch (Exception $e) {
            $this->logger->error('Failed to execute failure action', [
                'order_id' => $orderId,
                'failure_id' => $failureId,
                'action' => $actionPlan['action'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Action execution failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate refund amount
     */
    private function calculateRefundAmount(array $order, string $refundType, array $refundDetails): array
    {
        $orderAmount = $order['total_amount'];
        $deliveryFee = $order['delivery_fee'] ?? 0;
        $subtotalAmount = $orderAmount - $deliveryFee;

        $refundAmount = match($refundType) {
            self::REFUND_FULL => $orderAmount,
            self::REFUND_PARTIAL => $refundDetails['partial_amount'] ?? ($orderAmount * 0.5),
            self::REFUND_DELIVERY_FEE_ONLY => $deliveryFee,
            default => $orderAmount
        };

        return [
            'amount' => $refundAmount,
            'type' => $refundType,
            'order_amount' => $orderAmount,
            'delivery_fee' => $deliveryFee,
            'subtotal_amount' => $subtotalAmount
        ];
    }

    /**
     * Create refund record
     */
    private function createRefundRecord(int $orderId, array $refundCalculation): ?int
    {
        try {
            $sql = "INSERT INTO refunds 
                    (order_id, refund_amount, refund_type, status, created_at)
                    VALUES (?, ?, ?, 'pending', NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $orderId,
                $refundCalculation['amount'],
                $refundCalculation['type']
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to create refund record', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Process refund payment
     */
    private function processRefundPayment(array $order, array $refundCalculation, int $refundId): array
    {
        try {
            // In a real implementation, this would integrate with the payment service
            // For now, we'll simulate the refund processing
            
            $this->logger->info('Processing refund payment', [
                'order_id' => $order['id'],
                'refund_id' => $refundId,
                'refund_amount' => $refundCalculation['amount']
            ]);

            // Simulate payment processing (90% success rate)
            $success = (rand(1, 100) <= 90);

            if ($success) {
                return [
                    'success' => true,
                    'transaction_id' => 'REF_' . uniqid(),
                    'processed_amount' => $refundCalculation['amount'],
                    'processing_fee' => 0,
                    'net_refund' => $refundCalculation['amount'],
                    'processed_at' => date('Y-m-d H:i:s')
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Payment processing failed (simulated failure)',
                    'error_code' => 'PAYMENT_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Refund payment processing failed', [
                'order_id' => $order['id'],
                'refund_id' => $refundId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Refund payment processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update refund status
     */
    private function updateRefundStatus(int $refundId, string $status, array $paymentResult): void
    {
        try {
            $sql = "UPDATE refunds 
                    SET status = ?, 
                        transaction_id = ?,
                        processed_at = ?,
                        payment_response = ?,
                        updated_at = NOW()
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $status,
                $paymentResult['transaction_id'] ?? null,
                $paymentResult['processed_at'] ?? null,
                json_encode($paymentResult),
                $refundId
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update refund status', [
                'refund_id' => $refundId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send failure notifications
     */
    private function sendFailureNotifications(array $order, string $failureReason, array $actionPlan): array
    {
        try {
            $notificationData = [
                'order_id' => $order['id'],
                'failure_reason' => $failureReason,
                'action_taken' => $actionPlan['action'],
                'customer_name' => $order['customer_name'],
                'vendor_name' => $order['vendor_name']
            ];

            return $this->notificationService->sendDeliveryFailureNotification(
                $order['id'],
                $failureReason,
                $notificationData
            );

        } catch (Exception $e) {
            $this->logger->error('Failed to send failure notifications', [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Notification sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send refund notifications
     */
    private function sendRefundNotifications(array $order, array $refundCalculation, array $paymentResult): array
    {
        try {
            // This would send refund confirmation notifications
            // For now, return a simulated result
            return [
                'success' => true,
                'notifications_sent' => 2, // Customer and vendor
                'channels' => ['email', 'sms']
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to send refund notifications', [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Refund notification sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send retry notifications
     */
    private function sendRetryNotifications(array $order, array $retrySchedule): array
    {
        try {
            // This would send retry scheduling notifications
            // For now, return a simulated result
            return [
                'success' => true,
                'notifications_sent' => 2, // Customer and vendor
                'retry_date' => $retrySchedule['retry_date']
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to send retry notifications', [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Retry notification sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update order status
     */
    private function updateOrderStatus(int $orderId, string $status, array $additionalData): void
    {
        try {
            $sql = "UPDATE orders 
                    SET status = ?, 
                        delivery_notes = ?,
                        updated_at = NOW()
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $status,
                json_encode($additionalData),
                $orderId
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update order status', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get order failure history
     */
    private function getOrderFailureHistory(int $orderId): array
    {
        try {
            $sql = "SELECT * FROM delivery_failures WHERE order_id = ? ORDER BY created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$orderId]);
            
            return $stmt->fetchAll();

        } catch (Exception $e) {
            $this->logger->error('Failed to get order failure history', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Get action reason
     */
    private function getActionReason(string $action, string $failureReason, int $failureCount): string
    {
        return match($action) {
            self::ACTION_RETRY => "Retry scheduled due to {$failureReason} (attempt " . ($failureCount + 1) . ")",
            self::ACTION_REFUND => "Refund initiated due to {$failureReason}" . ($failureCount > 1 ? " after {$failureCount} failed attempts" : ""),
            self::ACTION_RESCHEDULE => "Delivery rescheduled due to {$failureReason}",
            self::ACTION_RETURN_TO_VENDOR => "Order returned to vendor due to {$failureReason}",
            self::ACTION_MANUAL_REVIEW => "Flagged for manual review due to {$failureReason}",
            default => "Action taken due to {$failureReason}"
        };
    }

    /**
     * Check retry eligibility
     */
    private function checkRetryEligibility(int $orderId): array
    {
        $failureHistory = $this->getOrderFailureHistory($orderId);
        $maxRetries = 3;

        if (count($failureHistory) >= $maxRetries) {
            return [
                'eligible' => false,
                'reason' => 'Maximum retry attempts exceeded'
            ];
        }

        return [
            'eligible' => true,
            'remaining_attempts' => $maxRetries - count($failureHistory)
        ];
    }

    /**
     * Determine retry schedule
     */
    private function determineRetrySchedule(array $order, array $retryOptions): array
    {
        $delayDays = $retryOptions['delay_days'] ?? 1;
        $retryDate = date('Y-m-d', strtotime("+{$delayDays} days"));

        return [
            'retry_date' => $retryDate,
            'retry_time_slot' => $retryOptions['time_slot'] ?? $order['delivery_time_slot'],
            'new_delivery_slot_id' => $retryOptions['delivery_slot_id'] ?? $order['delivery_slot_id'],
            'delay_days' => $delayDays,
            'retry_reason' => $retryOptions['reason'] ?? 'Automatic retry after delivery failure'
        ];
    }

    /**
     * Create retry record
     */
    private function createRetryRecord(int $orderId, array $retrySchedule): ?int
    {
        try {
            $sql = "INSERT INTO delivery_retries 
                    (order_id, retry_date, retry_time_slot, retry_reason, status, created_at)
                    VALUES (?, ?, ?, ?, 'scheduled', NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $orderId,
                $retrySchedule['retry_date'],
                $retrySchedule['retry_time_slot'],
                $retrySchedule['retry_reason']
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            $this->logger->error('Failed to create retry record', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Update delivery slot booking
     */
    private function updateDeliverySlotBooking(int $orderId, array $retrySchedule): array
    {
        // This would update the delivery slot booking
        // For now, return a simulated result
        return [
            'success' => true,
            'message' => 'Delivery slot booking updated for retry'
        ];
    }

    /**
     * Initiate return to vendor
     */
    private function initiateReturnToVendor(int $orderId): array
    {
        // This would initiate the return process
        // For now, return a simulated result
        return [
            'success' => true,
            'return_id' => 'RET_' . uniqid(),
            'message' => 'Return to vendor initiated'
        ];
    }

    /**
     * Flag for manual review
     */
    private function flagForManualReview(int $orderId, int $failureId): array
    {
        try {
            $sql = "INSERT INTO manual_review_queue 
                    (order_id, failure_id, review_type, priority, status, created_at)
                    VALUES (?, ?, 'delivery_failure', 'high', 'pending', NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$orderId, $failureId]);

            if ($success) {
                return [
                    'success' => true,
                    'review_id' => $this->db->lastInsertId(),
                    'message' => 'Order flagged for manual review'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to flag order for manual review'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to flag for manual review', [
                'order_id' => $orderId,
                'failure_id' => $failureId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Manual review flagging failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update failure record
     */
    private function updateFailureRecord(int $failureId, string $actionTaken, array $actionResult): void
    {
        try {
            $sql = "UPDATE delivery_failures 
                    SET action_taken = ?, 
                        action_result = ?,
                        updated_at = NOW()
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $actionTaken,
                json_encode($actionResult),
                $failureId
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update failure record', [
                'failure_id' => $failureId,
                'action_taken' => $actionTaken,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create delivery failure tables
     */
    private function createDeliveryFailureTables(): void
    {
        // Delivery failures table
        $sql1 = "CREATE TABLE IF NOT EXISTS delivery_failures (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            failure_reason VARCHAR(100) NOT NULL,
            failure_details JSON,
            action_taken VARCHAR(50),
            action_result JSON,
            reported_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_failure_reason (failure_reason),
            INDEX idx_action_taken (action_taken),
            INDEX idx_reported_at (reported_at),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )";

        // Refunds table
        $sql2 = "CREATE TABLE IF NOT EXISTS refunds (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            refund_amount DECIMAL(10,2) NOT NULL,
            refund_type VARCHAR(50) NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            transaction_id VARCHAR(100),
            processed_at TIMESTAMP NULL,
            payment_response JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_status (status),
            INDEX idx_refund_type (refund_type),
            INDEX idx_processed_at (processed_at),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )";

        // Manual review queue table
        $sql3 = "CREATE TABLE IF NOT EXISTS manual_review_queue (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            failure_id BIGINT,
            review_type VARCHAR(50) NOT NULL,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            status ENUM('pending', 'in_review', 'resolved', 'escalated') DEFAULT 'pending',
            assigned_to VARCHAR(100),
            review_notes TEXT,
            resolved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_order_id (order_id),
            INDEX idx_failure_id (failure_id),
            INDEX idx_review_type (review_type),
            INDEX idx_priority (priority),
            INDEX idx_status (status),
            INDEX idx_assigned_to (assigned_to),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (failure_id) REFERENCES delivery_failures(id) ON DELETE SET NULL
        )";

        $this->db->exec($sql1);
        $this->db->exec($sql2);
        $this->db->exec($sql3);
    }
}