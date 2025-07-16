<?php

require_once __DIR__ . '/../bootstrap/app.php';

use Antinna\Multivendor\Helpers\ErrorHandlingHelper;
use Antinna\Multivendor\Services\VendorRegistrationService;
use Antinna\Multivendor\Exceptions\ValidationException;
use Antinna\Multivendor\Exceptions\ConflictException;

echo "=== Error Handling and Logging Demo ===\n\n";

// Set up error handlers
ErrorHandlingHelper::setupGlobalHandlers();

$logger = ErrorHandlingHelper::getLogger();
$errorHandler = ErrorHandlingHelper::getErrorHandler();

// Demo 1: Basic logging
echo "1. Basic Logging Demo:\n";
$logger->info('Application started', ['demo' => 'error_handling']);
$logger->debug('Debug information', ['user_id' => 123]);
$logger->warning('This is a warning', ['action' => 'demo']);
echo "   ✓ Logs written to log file\n\n";

// Demo 2: Business event logging
echo "2. Business Event Logging:\n";
$logger->logBusinessEvent('vendor_registration_started', [
    'vendor_id' => 456,
    'business_name' => 'Demo Dairy Farm',
    'timestamp' => time()
]);
echo "   ✓ Business event logged\n\n";

// Demo 3: API request/response logging
echo "3. API Request/Response Logging:\n";
$logger->logApiRequest('POST', '/api/vendors', ['name' => 'Test Vendor'], 'user123');
$logger->logApiResponse('/api/vendors', 201, 0.125, ['success' => true, 'vendor_id' => 789]);
echo "   ✓ API interaction logged\n\n";

// Demo 4: Exception handling
echo "4. Exception Handling Demo:\n";

// Validation exception
try {
    throw new ValidationException(['email' => 'Invalid email format', 'name' => 'Name is required']);
} catch (ValidationException $e) {
    $response = $errorHandler->handleException($e);
    echo "   ValidationException: HTTP {$response['code']} - {$response['message']}\n";
}

// Conflict exception
try {
    throw new ConflictException('Vendor', 'Email already exists');
} catch (ConflictException $e) {
    $response = $errorHandler->handleException($e);
    echo "   ConflictException: HTTP {$response['code']} - {$response['message']}\n";
}

// Generic exception
try {
    throw new \RuntimeException('Something went wrong');
} catch (\RuntimeException $e) {
    $response = $errorHandler->handleException($e);
    echo "   RuntimeException: HTTP {$response['code']} - {$response['message']}\n";
}

echo "\n";

// Demo 5: Database error handling
echo "5. Database Error Handling:\n";
try {
    throw new \PDOException('SQLSTATE[42S02]: Base table or view not found');
} catch (\PDOException $e) {
    $response = $errorHandler->handleDatabaseError($e);
    echo "   Database Error: HTTP {$response['code']} - {$response['message']}\n";
}

echo "\n";

// Demo 6: API error handling
echo "6. External API Error Handling:\n";
$response = $errorHandler->handleApiError('payment-service', 503, 'Service temporarily unavailable');
echo "   API Error: HTTP {$response['code']} - {$response['message']}\n\n";

// Demo 7: Sensitive data redaction
echo "7. Sensitive Data Redaction Demo:\n";
$sensitiveData = [
    'username' => 'testuser',
    'password' => 'secret123',
    'api_key' => 'sk_live_abc123',
    'normal_field' => 'normal_value'
];
$logger->logApiRequest('POST', '/api/login', $sensitiveData);
echo "   ✓ Sensitive data redacted in logs\n\n";

// Demo 8: Complete API response handling
echo "8. Complete API Response Handling:\n";
ErrorHandlingHelper::handleApiResponse(function() {
    // Simulate successful operation
    return [
        'success' => true,
        'message' => 'Demo operation completed successfully',
        'data' => ['id' => 123, 'status' => 'active']
    ];
});
echo "   ✓ Successful API response handled\n";

// Demo with exception
ob_start(); // Capture output
ErrorHandlingHelper::handleApiResponse(function() {
    throw new ValidationException(['field' => 'This field is required']);
});
$output = ob_get_clean();
$errorResponse = json_decode($output, true);
echo "   ✓ Exception in API response handled: HTTP {$errorResponse['code']}\n\n";

echo "=== Demo Complete ===\n";
echo "Check the log files in the logs directory for detailed logging output.\n";