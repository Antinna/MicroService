<?php

namespace Antinna\MultiVendor\Controllers;

use Antinna\MultiVendor\Services\SubscriptionManager;
use Antinna\MultiVendor\Services\OrderGenerator;
use Antinna\MultiVendor\Services\DeliverySlotManager;
use Antinna\MultiVendor\Services\OrderConsolidator;
use Antinna\MultiVendor\Services\DeliveryFailureHandler;
use Exception;

/**
 * Subscription and delivery management API controller
 */
class SubscriptionController
{
    private SubscriptionManager $subscriptionManager;
    private OrderGenerator $orderGenerator;
    private DeliverySlotManager $deliverySlotManager;
    private OrderConsolidator $orderConsolidator;
    private DeliveryFailureHandler $deliveryFailureHandler;

    public function __construct()
    {
        $this->subscriptionManager = new SubscriptionManager();
        $this->orderGenerator = new OrderGenerator();
        $this->deliverySlotManager = new DeliverySlotManager();
        $this->orderConsolidator = new OrderConsolidator();
        $this->deliveryFailureHandler = new DeliveryFailureHandler();
    }

    /**
     * Create new subscription
     * POST /api/subscriptions
     */
    public function createSubscription(): void
    {
        try {
            $input = $this->getJsonInput();
            
            // Validate required fields
            $requiredFields = ['customer_id', 'vendor_id', 'products', 'delivery_frequency', 'delivery_preferences'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            // Create subscription
            $result = $this->subscriptionManager->createSubscription($input);
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Subscription creation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get subscription by ID
     * GET /api/subscriptions/{id}
     */
    public function getSubscription(int $subscriptionId): void
    {
        try {
            $result = $this->subscriptionManager->getSubscription($subscriptionId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get subscription: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update subscription
     * PUT /api/subscriptions/{id}
     */
    public function updateSubscription(int $subscriptionId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->subscriptionManager->updateSubscription($subscriptionId, $input);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Subscription update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Cancel subscription
     * DELETE /api/subscriptions/{id}
     */
    public function cancelSubscription(int $subscriptionId): void
    {
        try {
            $input = $this->getJsonInput();
            $reason = $input['reason'] ?? 'Customer request';
            
            $result = $this->subscriptionManager->cancelSubscription($subscriptionId, $reason);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Subscription cancellation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get subscriptions with filtering
     * GET /api/subscriptions
     */
    public function getSubscriptions(): void
    {
        try {
            $filters = [
                'customer_id' => $_GET['customer_id'] ?? null,
                'vendor_id' => $_GET['vendor_id'] ?? null,
                'status' => $_GET['status'] ?? null,
                'delivery_frequency' => $_GET['delivery_frequency'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 20),
                'sort_by' => $_GET['sort_by'] ?? 'created_at',
                'sort_order' => $_GET['sort_order'] ?? 'desc'
            ];

            $result = $this->subscriptionManager->getSubscriptions($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get subscriptions: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Pause subscription
     * PATCH /api/subscriptions/{id}/pause
     */
    public function pauseSubscription(int $subscriptionId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->subscriptionManager->pauseSubscription(
                $subscriptionId,
                $input['pause_until'] ?? null,
                $input['reason'] ?? 'Customer request'
            );
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Subscription pause failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Resume subscription
     * PATCH /api/subscriptions/{id}/resume
     */
    public function resumeSubscription(int $subscriptionId): void
    {
        try {
            $result = $this->subscriptionManager->resumeSubscription($subscriptionId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Subscription resume failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get customer subscriptions
     * GET /api/customers/{customerId}/subscriptions
     */
    public function getCustomerSubscriptions(int $customerId): void
    {
        try {
            $status = $_GET['status'] ?? null;
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            
            $result = $this->subscriptionManager->getCustomerSubscriptions($customerId, $status, $page, $limit);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get customer subscriptions: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor subscriptions
     * GET /api/vendors/{vendorId}/subscriptions
     */
    public function getVendorSubscriptions(int $vendorId): void
    {
        try {
            $status = $_GET['status'] ?? null;
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            
            $result = $this->subscriptionManager->getVendorSubscriptions($vendorId, $status, $page, $limit);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendor subscriptions: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Generate orders for subscriptions
     * POST /api/subscriptions/generate-orders
     */
    public function generateOrders(): void
    {
        try {
            $input = $this->getJsonInput();
            $date = $input['date'] ?? date('Y-m-d');
            $vendorId = $input['vendor_id'] ?? null;
            
            $result = $this->orderGenerator->generateSubscriptionOrders($date, $vendorId);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Order generation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get subscription orders
     * GET /api/subscriptions/{id}/orders
     */
    public function getSubscriptionOrders(int $subscriptionId): void
    {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? null;
            
            $result = $this->subscriptionManager->getSubscriptionOrders($subscriptionId, $status, $page, $limit);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get subscription orders: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get available delivery slots
     * GET /api/delivery/slots
     */
    public function getDeliverySlots(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            $date = $_GET['date'] ?? date('Y-m-d');
            $location = $_GET['location'] ?? null;
            
            $result = $this->deliverySlotManager->getAvailableSlots($vendorId, $date, $location);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get delivery slots: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Book delivery slot
     * POST /api/delivery/slots/book
     */
    public function bookDeliverySlot(): void
    {
        try {
            $input = $this->getJsonInput();
            
            $requiredFields = ['slot_id', 'order_id', 'customer_id'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            $result = $this->deliverySlotManager->bookSlot(
                $input['slot_id'],
                $input['order_id'],
                $input['customer_id'],
                $input['special_instructions'] ?? null
            );
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Slot booking failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Cancel delivery slot booking
     * DELETE /api/delivery/slots/{slotId}/bookings/{bookingId}
     */
    public function cancelSlotBooking(int $slotId, int $bookingId): void
    {
        try {
            $input = $this->getJsonInput();
            $reason = $input['reason'] ?? 'Customer request';
            
            $result = $this->deliverySlotManager->cancelBooking($slotId, $bookingId, $reason);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Booking cancellation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get delivery slot capacity
     * GET /api/delivery/slots/{slotId}/capacity
     */
    public function getSlotCapacity(int $slotId): void
    {
        try {
            $result = $this->deliverySlotManager->getSlotCapacity($slotId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get slot capacity: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Consolidate orders
     * POST /api/orders/consolidate
     */
    public function consolidateOrders(): void
    {
        try {
            $input = $this->getJsonInput();
            
            $filters = [
                'vendor_id' => $input['vendor_id'] ?? null,
                'delivery_date' => $input['delivery_date'] ?? date('Y-m-d'),
                'location' => $input['location'] ?? null,
                'delivery_slot' => $input['delivery_slot'] ?? null
            ];
            
            $result = $this->orderConsolidator->consolidateOrders($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Order consolidation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get consolidated orders
     * GET /api/orders/consolidated
     */
    public function getConsolidatedOrders(): void
    {
        try {
            $filters = [
                'vendor_id' => $_GET['vendor_id'] ?? null,
                'delivery_date' => $_GET['delivery_date'] ?? null,
                'status' => $_GET['status'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 20)
            ];
            
            $result = $this->orderConsolidator->getConsolidatedOrders($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get consolidated orders: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Report delivery failure
     * POST /api/delivery/failures
     */
    public function reportDeliveryFailure(): void
    {
        try {
            $input = $this->getJsonInput();
            
            $requiredFields = ['order_id', 'failure_reason'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            $result = $this->deliveryFailureHandler->reportFailure(
                $input['order_id'],
                $input['failure_reason'],
                $input['failure_details'] ?? null,
                $input['delivery_attempt_time'] ?? date('Y-m-d H:i:s')
            );
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Delivery failure reporting failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Process delivery failure
     * POST /api/delivery/failures/{failureId}/process
     */
    public function processDeliveryFailure(int $failureId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->deliveryFailureHandler->processFailure(
                $failureId,
                $input['action'] ?? 'reschedule',
                $input['notes'] ?? null
            );
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Delivery failure processing failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get delivery failures
     * GET /api/delivery/failures
     */
    public function getDeliveryFailures(): void
    {
        try {
            $filters = [
                'vendor_id' => $_GET['vendor_id'] ?? null,
                'customer_id' => $_GET['customer_id'] ?? null,
                'status' => $_GET['status'] ?? null,
                'failure_reason' => $_GET['failure_reason'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'page' => (int)($_GET['page'] ?? 1),
                'limit' => (int)($_GET['limit'] ?? 20)
            ];
            
            $result = $this->deliveryFailureHandler->getFailures($filters);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get delivery failures: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get subscription analytics
     * GET /api/subscriptions/analytics
     */
    public function getSubscriptionAnalytics(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            
            $result = $this->subscriptionManager->getSubscriptionAnalytics($vendorId, $dateFrom, $dateTo);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get subscription analytics: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get delivery performance metrics
     * GET /api/delivery/performance
     */
    public function getDeliveryPerformance(): void
    {
        try {
            $vendorId = $_GET['vendor_id'] ?? null;
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            
            $result = $this->deliverySlotManager->getDeliveryPerformance($vendorId, $dateFrom, $dateTo);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get delivery performance: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Validate subscription data
     * POST /api/subscriptions/validate
     */
    public function validateSubscription(): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->subscriptionManager->validateSubscriptionData($input);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Subscription validation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get subscription history
     * GET /api/subscriptions/{id}/history
     */
    public function getSubscriptionHistory(int $subscriptionId): void
    {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 50);
            
            $result = $this->subscriptionManager->getSubscriptionHistory($subscriptionId, $page, $limit);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get subscription history: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input');
        }
        
        return $data ?? [];
    }

    /**
     * Validate required fields
     */
    private function validateRequiredFields(array $data, array $requiredFields): array
    {
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Send JSON response
     */
    private function sendResponse(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}