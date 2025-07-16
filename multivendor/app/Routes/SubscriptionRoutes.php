<?php

namespace Antinna\MultiVendor\Routes;

use Antinna\MultiVendor\Controllers\SubscriptionController;

/**
 * Subscription and delivery management API routes
 */
class SubscriptionRoutes
{
    private SubscriptionController $controller;

    public function __construct()
    {
        $this->controller = new SubscriptionController();
    }

    /**
     * Handle subscription and delivery API routes
     */
    public function handleRequest(string $method, string $path): void
    {
        // Remove /api prefix if present
        $path = preg_replace('/^\/api/', '', $path);
        
        // Parse path segments
        $segments = array_filter(explode('/', $path));
        $segments = array_values($segments); // Re-index array

        // Route matching
        switch ($method) {
            case 'GET':
                $this->handleGetRequests($segments);
                break;
                
            case 'POST':
                $this->handlePostRequests($segments);
                break;
                
            case 'PUT':
                $this->handlePutRequests($segments);
                break;
                
            case 'PATCH':
                $this->handlePatchRequests($segments);
                break;
                
            case 'DELETE':
                $this->handleDeleteRequests($segments);
                break;
                
            default:
                $this->sendMethodNotAllowed();
                break;
        }
    }

    /**
     * Handle GET requests
     */
    private function handleGetRequests(array $segments): void
    {
        // GET /subscriptions - Get all subscriptions
        if (count($segments) === 1 && $segments[0] === 'subscriptions') {
            $this->controller->getSubscriptions();
            return;
        }

        // GET /subscriptions/analytics - Get subscription analytics
        if (count($segments) === 2 && $segments[0] === 'subscriptions' && $segments[1] === 'analytics') {
            $this->controller->getSubscriptionAnalytics();
            return;
        }

        // GET /subscriptions/{id} - Get subscription by ID
        if (count($segments) === 2 && $segments[0] === 'subscriptions' && is_numeric($segments[1])) {
            $this->controller->getSubscription((int)$segments[1]);
            return;
        }

        // GET /subscriptions/{id}/orders - Get subscription orders
        if (count($segments) === 3 && $segments[0] === 'subscriptions' && 
            is_numeric($segments[1]) && $segments[2] === 'orders') {
            $this->controller->getSubscriptionOrders((int)$segments[1]);
            return;
        }

        // GET /subscriptions/{id}/history - Get subscription history
        if (count($segments) === 3 && $segments[0] === 'subscriptions' && 
            is_numeric($segments[1]) && $segments[2] === 'history') {
            $this->controller->getSubscriptionHistory((int)$segments[1]);
            return;
        }

        // GET /customers/{customerId}/subscriptions - Get customer subscriptions
        if (count($segments) === 3 && $segments[0] === 'customers' && 
            is_numeric($segments[1]) && $segments[2] === 'subscriptions') {
            $this->controller->getCustomerSubscriptions((int)$segments[1]);
            return;
        }

        // GET /vendors/{vendorId}/subscriptions - Get vendor subscriptions
        if (count($segments) === 3 && $segments[0] === 'vendors' && 
            is_numeric($segments[1]) && $segments[2] === 'subscriptions') {
            $this->controller->getVendorSubscriptions((int)$segments[1]);
            return;
        }

        // GET /delivery/slots - Get available delivery slots
        if (count($segments) === 2 && $segments[0] === 'delivery' && $segments[1] === 'slots') {
            $this->controller->getDeliverySlots();
            return;
        }

        // GET /delivery/slots/{slotId}/capacity - Get slot capacity
        if (count($segments) === 4 && $segments[0] === 'delivery' && $segments[1] === 'slots' && 
            is_numeric($segments[2]) && $segments[3] === 'capacity') {
            $this->controller->getSlotCapacity((int)$segments[2]);
            return;
        }

        // GET /delivery/performance - Get delivery performance metrics
        if (count($segments) === 2 && $segments[0] === 'delivery' && $segments[1] === 'performance') {
            $this->controller->getDeliveryPerformance();
            return;
        }

        // GET /delivery/failures - Get delivery failures
        if (count($segments) === 2 && $segments[0] === 'delivery' && $segments[1] === 'failures') {
            $this->controller->getDeliveryFailures();
            return;
        }

        // GET /orders/consolidated - Get consolidated orders
        if (count($segments) === 2 && $segments[0] === 'orders' && $segments[1] === 'consolidated') {
            $this->controller->getConsolidatedOrders();
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle POST requests
     */
    private function handlePostRequests(array $segments): void
    {
        // POST /subscriptions - Create new subscription
        if (count($segments) === 1 && $segments[0] === 'subscriptions') {
            $this->controller->createSubscription();
            return;
        }

        // POST /subscriptions/validate - Validate subscription data
        if (count($segments) === 2 && $segments[0] === 'subscriptions' && $segments[1] === 'validate') {
            $this->controller->validateSubscription();
            return;
        }

        // POST /subscriptions/generate-orders - Generate subscription orders
        if (count($segments) === 2 && $segments[0] === 'subscriptions' && $segments[1] === 'generate-orders') {
            $this->controller->generateOrders();
            return;
        }

        // POST /delivery/slots/book - Book delivery slot
        if (count($segments) === 3 && $segments[0] === 'delivery' && $segments[1] === 'slots' && $segments[2] === 'book') {
            $this->controller->bookDeliverySlot();
            return;
        }

        // POST /delivery/failures - Report delivery failure
        if (count($segments) === 2 && $segments[0] === 'delivery' && $segments[1] === 'failures') {
            $this->controller->reportDeliveryFailure();
            return;
        }

        // POST /delivery/failures/{failureId}/process - Process delivery failure
        if (count($segments) === 4 && $segments[0] === 'delivery' && $segments[1] === 'failures' && 
            is_numeric($segments[2]) && $segments[3] === 'process') {
            $this->controller->processDeliveryFailure((int)$segments[2]);
            return;
        }

        // POST /orders/consolidate - Consolidate orders
        if (count($segments) === 2 && $segments[0] === 'orders' && $segments[1] === 'consolidate') {
            $this->controller->consolidateOrders();
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle PUT requests
     */
    private function handlePutRequests(array $segments): void
    {
        // PUT /subscriptions/{id} - Update subscription
        if (count($segments) === 2 && $segments[0] === 'subscriptions' && is_numeric($segments[1])) {
            $this->controller->updateSubscription((int)$segments[1]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle PATCH requests
     */
    private function handlePatchRequests(array $segments): void
    {
        // PATCH /subscriptions/{id}/pause - Pause subscription
        if (count($segments) === 3 && $segments[0] === 'subscriptions' && 
            is_numeric($segments[1]) && $segments[2] === 'pause') {
            $this->controller->pauseSubscription((int)$segments[1]);
            return;
        }

        // PATCH /subscriptions/{id}/resume - Resume subscription
        if (count($segments) === 3 && $segments[0] === 'subscriptions' && 
            is_numeric($segments[1]) && $segments[2] === 'resume') {
            $this->controller->resumeSubscription((int)$segments[1]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Handle DELETE requests
     */
    private function handleDeleteRequests(array $segments): void
    {
        // DELETE /subscriptions/{id} - Cancel subscription
        if (count($segments) === 2 && $segments[0] === 'subscriptions' && is_numeric($segments[1])) {
            $this->controller->cancelSubscription((int)$segments[1]);
            return;
        }

        // DELETE /delivery/slots/{slotId}/bookings/{bookingId} - Cancel slot booking
        if (count($segments) === 5 && $segments[0] === 'delivery' && $segments[1] === 'slots' && 
            is_numeric($segments[2]) && $segments[3] === 'bookings' && is_numeric($segments[4])) {
            $this->controller->cancelSlotBooking((int)$segments[2], (int)$segments[4]);
            return;
        }

        $this->sendNotFound();
    }

    /**
     * Send 404 Not Found response
     */
    private function sendNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found'
        ]);
    }

    /**
     * Send 405 Method Not Allowed response
     */
    private function sendMethodNotAllowed(): void
    {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
    }

    /**
     * Get available routes documentation
     */
    public static function getRouteDocumentation(): array
    {
        return [
            'subscription_management' => [
                'base_path' => '/api/subscriptions',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/',
                        'description' => 'Create new subscription',
                        'parameters' => [
                            'customer_id' => 'int (required)',
                            'vendor_id' => 'int (required)',
                            'products' => 'array (required) - Array of product objects with id and quantity',
                            'delivery_frequency' => 'string (required) - daily|weekly|monthly',
                            'delivery_preferences' => 'object (required) - Delivery time and location preferences',
                            'start_date' => 'date (optional)',
                            'end_date' => 'date (optional)',
                            'special_instructions' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/',
                        'description' => 'Get all subscriptions with filtering',
                        'query_parameters' => [
                            'customer_id' => 'int (optional)',
                            'vendor_id' => 'int (optional)',
                            'status' => 'string (optional)',
                            'delivery_frequency' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)',
                            'sort_by' => 'string (default: created_at)',
                            'sort_order' => 'string (default: desc)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}',
                        'description' => 'Get subscription by ID'
                    ],
                    [
                        'method' => 'PUT',
                        'path' => '/{id}',
                        'description' => 'Update subscription'
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/{id}',
                        'description' => 'Cancel subscription',
                        'parameters' => [
                            'reason' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/{id}/pause',
                        'description' => 'Pause subscription',
                        'parameters' => [
                            'pause_until' => 'date (optional)',
                            'reason' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'PATCH',
                        'path' => '/{id}/resume',
                        'description' => 'Resume subscription'
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/orders',
                        'description' => 'Get subscription orders',
                        'query_parameters' => [
                            'status' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/{id}/history',
                        'description' => 'Get subscription history',
                        'query_parameters' => [
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 50)'
                        ]
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/validate',
                        'description' => 'Validate subscription data'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/generate-orders',
                        'description' => 'Generate subscription orders',
                        'parameters' => [
                            'date' => 'date (optional, default: today)',
                            'vendor_id' => 'int (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/analytics',
                        'description' => 'Get subscription analytics',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'date_from' => 'date (optional)',
                            'date_to' => 'date (optional)'
                        ]
                    ]
                ]
            ],
            'customer_subscriptions' => [
                'base_path' => '/api/customers',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/{customerId}/subscriptions',
                        'description' => 'Get customer subscriptions',
                        'query_parameters' => [
                            'status' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ]
                ]
            ],
            'vendor_subscriptions' => [
                'base_path' => '/api/vendors',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/{vendorId}/subscriptions',
                        'description' => 'Get vendor subscriptions',
                        'query_parameters' => [
                            'status' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ]
                ]
            ],
            'delivery_management' => [
                'base_path' => '/api/delivery',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/slots',
                        'description' => 'Get available delivery slots',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'date' => 'date (optional, default: today)',
                            'location' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/slots/book',
                        'description' => 'Book delivery slot',
                        'parameters' => [
                            'slot_id' => 'int (required)',
                            'order_id' => 'int (required)',
                            'customer_id' => 'int (required)',
                            'special_instructions' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/slots/{slotId}/capacity',
                        'description' => 'Get delivery slot capacity'
                    ],
                    [
                        'method' => 'DELETE',
                        'path' => '/slots/{slotId}/bookings/{bookingId}',
                        'description' => 'Cancel slot booking',
                        'parameters' => [
                            'reason' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/performance',
                        'description' => 'Get delivery performance metrics',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'date_from' => 'date (optional)',
                            'date_to' => 'date (optional)'
                        ]
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/failures',
                        'description' => 'Report delivery failure',
                        'parameters' => [
                            'order_id' => 'int (required)',
                            'failure_reason' => 'string (required)',
                            'failure_details' => 'string (optional)',
                            'delivery_attempt_time' => 'datetime (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/failures',
                        'description' => 'Get delivery failures',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'customer_id' => 'int (optional)',
                            'status' => 'string (optional)',
                            'failure_reason' => 'string (optional)',
                            'date_from' => 'date (optional)',
                            'date_to' => 'date (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/failures/{failureId}/process',
                        'description' => 'Process delivery failure',
                        'parameters' => [
                            'action' => 'string (optional, default: reschedule)',
                            'notes' => 'string (optional)'
                        ]
                    ]
                ]
            ],
            'order_consolidation' => [
                'base_path' => '/api/orders',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/consolidate',
                        'description' => 'Consolidate orders',
                        'parameters' => [
                            'vendor_id' => 'int (optional)',
                            'delivery_date' => 'date (optional, default: today)',
                            'location' => 'string (optional)',
                            'delivery_slot' => 'string (optional)'
                        ]
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/consolidated',
                        'description' => 'Get consolidated orders',
                        'query_parameters' => [
                            'vendor_id' => 'int (optional)',
                            'delivery_date' => 'date (optional)',
                            'status' => 'string (optional)',
                            'page' => 'int (default: 1)',
                            'limit' => 'int (default: 20)'
                        ]
                    ]
                ]
            ]
        ];
    }
}