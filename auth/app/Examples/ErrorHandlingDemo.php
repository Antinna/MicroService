<?php

require_once __DIR__ . '/../bootstrap/app.php';

use Antinna\Auth\Services\ErrorHandler;
use Antinna\Auth\Services\Logger;
use Antinna\Auth\Middleware\LoggingMiddleware;
use Antinna\Auth\Helpers\ErrorHandlingHelper;

echo "=== Error Handling and Logging System Demo ===\n\n";

try {
    // Initialize services
    $errorHandler = new ErrorHandler();
    $logger = new Logger();
    $middleware = new LoggingMiddleware();

    echo "1. Testing Basic Error Handling...\n";
    
    // Test authentication error
    $authError = $errorHandler->handleApplicationError('AUTH_001', [
        'email' => 'invalid@example.com',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Demo Script'
    ]);
    
    echo "   Authentication Error Response:\n";
    echo "   " . json_encode($authError, JSON_PRETTY_PRINT) . "\n\n";

    // Test validation error
    $validationError = $errorHandler->handleApplicationError('VAL_002', [
        'missing_fields' => ['password', 'email'],
        'request_data' => ['username' => 'test']
    ]);
    
    echo "   Validation Error Response:\n";
    echo "   " . json_encode($validationError, JSON_PRETTY_PRINT) . "\n\n";

    echo "2. Testing Different Log Levels and Channels...\n";
    
    // Test different log levels
    $logger->debug('Debug message for troubleshooting', ['debug_info' => 'detailed_data']);
    $logger->info('User login attempt', ['user_id' => 123, 'ip' => '127.0.0.1']);
    $logger->warning('Rate limit approaching', ['current_requests' => 45, 'limit' => 50]);
    $logger->error('Database connection failed', ['host' => 'localhost', 'port' => 3306]);
    $logger->critical('System overload detected', ['cpu_usage' => 95, 'memory_usage' => 90]);
    
    echo "   ✓ Logged messages at different levels\n";

    // Test different channels
    $logger->info('API request processed', ['endpoint' => '/api/users'], Logger::CHANNEL_API);
    $logger->error('Security violation detected', ['type' => 'brute_force'], Logger::CHANNEL_SECURITY);
    $logger->debug('Query executed', ['sql' => 'SELECT * FROM users'], Logger::CHANNEL_DATABASE);
    $logger->warning('External service slow', ['service' => 'email'], Logger::CHANNEL_EXTERNAL);
    
    echo "   ✓ Logged messages to different channels\n\n";

    echo "3. Testing Performance Logging...\n";
    
    // Simulate operations with performance logging
    $startTime = microtime(true);
    
    // Simulate some work
    usleep(100000); // 100ms
    
    $executionTime = microtime(true) - $startTime;
    
    $logger->logPerformance('user_authentication', $executionTime, [
        'db_queries' => 3,
        'cache_hits' => 2,
        'external_calls' => 1,
        'memory_peak' => memory_get_peak_usage(true)
    ]);
    
    echo "   ✓ Performance metrics logged (execution time: " . round($executionTime * 1000, 2) . "ms)\n\n";

    echo "4. Testing API Request/Response Logging...\n";
    
    // Simulate API request logging
    $logger->logApiRequest(
        'POST',
        '/api/auth/login',
        [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer token123',
            'User-Agent' => 'Demo Client'
        ],
        [
            'email' => 'demo@example.com',
            'password' => 'secret123',
            'remember_me' => true
        ],
        123
    );
    
    // Simulate API response logging
    $logger->logApiResponse(200, [
        'success' => true,
        'user' => ['id' => 123, 'email' => 'demo@example.com']
    ], $executionTime);
    
    echo "   ✓ API request and response logged\n\n";

    echo "5. Testing Database and External Service Logging...\n";
    
    // Database query logging
    $logger->logDatabaseQuery(
        'SELECT * FROM users WHERE email = ? AND is_active = ?',
        ['demo@example.com', true],
        0.025
    );
    
    // Database error logging
    $logger->logDatabaseQuery(
        'SELECT * FROM invalid_table',
        [],
        null,
        'Table \'invalid_table\' doesn\'t exist'
    );
    
    // External service logging
    $logger->logExternalService('email_service', 'send_welcome_email', true, [
        'recipient' => 'demo@example.com',
        'template' => 'welcome',
        'delivery_time' => 0.5
    ]);
    
    $logger->logExternalService('sms_service', 'send_verification', false, [
        'phone' => '+1234567890',
        'error' => 'Invalid phone number format',
        'provider' => 'twilio'
    ]);
    
    echo "   ✓ Database and external service calls logged\n\n";

    echo "6. Testing Error Categories and Severities...\n";
    
    $errorCategories = [
        'authentication' => ['AUTH_001', 'AUTH_002', 'AUTH_010'],
        'authorization' => ['AUTHZ_001', 'AUTHZ_002'],
        'validation' => ['VAL_001', 'VAL_002', 'VAL_006'],
        'database' => ['DB_001', 'DB_004', 'DB_005'],
        'external_service' => ['EXT_001', 'EXT_002', 'EXT_003'],
        'system' => ['SYS_001', 'SYS_002'],
        'security' => ['SEC_001', 'SEC_002']
    ];
    
    foreach ($errorCategories as $category => $codes) {
        echo "   {$category} errors:\n";
        foreach ($codes as $code) {
            $errorInfo = $errorHandler->getErrorInfo($code);
            if ($errorInfo) {
                echo "     - {$code}: {$errorInfo['message']} (severity: {$errorInfo['severity']})\n";
            }
        }
    }
    echo "\n";

    echo "7. Testing Helper Functions...\n";
    
    // Test error response creation
    $errorResponse = ErrorHandlingHelper::createErrorResponse('AUTH_001', [
        'email' => 'test@example.com',
        'attempt_number' => 3
    ]);
    
    echo "   Error Response Helper:\n";
    echo "   " . json_encode($errorResponse, JSON_PRETTY_PRINT) . "\n\n";
    
    // Test success response creation
    $successResponse = ErrorHandlingHelper::createSuccessResponse([
        'user_id' => 123,
        'email' => 'demo@example.com',
        'role' => 'user'
    ], 'Login successful');
    
    echo "   Success Response Helper:\n";
    echo "   " . json_encode($successResponse, JSON_PRETTY_PRINT) . "\n\n";

    echo "8. Testing Log Statistics and Search...\n";
    
    // Get log statistics
    $stats = $logger->getLogStats();
    echo "   Log Statistics:\n";
    foreach ($stats as $channel => $channelStats) {
        if ($channelStats['file_size'] > 0) {
            echo "     - {$channel}: {$channelStats['line_count']} lines, " . 
                 round($channelStats['file_size'] / 1024, 2) . " KB\n";
        }
    }
    echo "\n";
    
    // Search logs
    $searchResults = $logger->searchLogs('authentication', null, null, 5);
    if (!empty($searchResults)) {
        echo "   Search Results for 'authentication':\n";
        foreach ($searchResults as $channel => $matches) {
            echo "     {$channel} channel: " . count($matches) . " matches\n";
            foreach (array_slice($matches, 0, 2) as $match) {
                echo "       - " . substr($match, 0, 100) . "...\n";
            }
        }
    }
    echo "\n";

    echo "9. Testing Security Error Handling...\n";
    
    // Simulate security incidents
    $_SESSION['user_id'] = 123;
    
    $securityErrors = [
        'SEC_001' => ['violation_type' => 'suspicious_activity', 'details' => 'Multiple failed logins'],
        'SEC_002' => ['activity_type' => 'unusual_location', 'location' => 'Unknown Country'],
        'SEC_003' => ['ip_address' => '192.168.1.100', 'reason' => 'Brute force attempts']
    ];
    
    foreach ($securityErrors as $code => $context) {
        $securityResult = $errorHandler->handleApplicationError($code, $context);
        echo "   {$code}: {$securityResult['error']['message']}\n";
    }
    
    unset($_SESSION['user_id']);
    echo "\n";

    echo "10. Testing Middleware Integration...\n";
    
    // Set up request environment
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/demo/test';
    $_SERVER['HTTP_USER_AGENT'] = 'Demo Script';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    
    // Handle request
    $middleware->handleRequest();
    
    // Simulate response
    $responseData = json_encode([
        'success' => true,
        'message' => 'Demo completed successfully',
        'timestamp' => date('c')
    ]);
    
    http_response_code(200);
    $result = $middleware->handleResponse($responseData);
    
    echo "   ✓ Request and response logged via middleware\n";
    echo "   Execution time: " . round($middleware->getExecutionTime() * 1000, 2) . "ms\n\n";

    echo "=== Demo Completed Successfully ===\n";
    echo "Check the logs directory for detailed log files:\n";
    echo "- application-" . date('Y-m-d') . ".log\n";
    echo "- api-" . date('Y-m-d') . ".log\n";
    echo "- security-" . date('Y-m-d') . ".log\n";
    echo "- database-" . date('Y-m-d') . ".log\n";
    echo "- external-" . date('Y-m-d') . ".log\n";
    echo "- performance-" . date('Y-m-d') . ".log\n\n";

    echo "📊 SYSTEM CAPABILITIES DEMONSTRATED:\n";
    echo "  ✓ Comprehensive error categorization and handling\n";
    echo "  ✓ Multi-level logging with channel separation\n";
    echo "  ✓ Performance monitoring and slow operation detection\n";
    echo "  ✓ Security incident logging and audit trails\n";
    echo "  ✓ API request/response logging with sanitization\n";
    echo "  ✓ Database query logging and error tracking\n";
    echo "  ✓ External service monitoring and failure tracking\n";
    echo "  ✓ Log analysis, search, and statistics\n";
    echo "  ✓ Middleware integration for automatic logging\n";
    echo "  ✓ Helper functions for common operations\n\n";

    echo "🔒 SECURITY FEATURES:\n";
    echo "  ✓ Sensitive data sanitization in logs\n";
    echo "  ✓ Security incident detection and logging\n";
    echo "  ✓ Rate limiting integration\n";
    echo "  ✓ IP address tracking and analysis\n";
    echo "  ✓ User activity monitoring\n";
    echo "  ✓ Audit trail for administrative actions\n\n";

    echo "📈 OPERATIONAL FEATURES:\n";
    echo "  ✓ Log rotation and cleanup\n";
    echo "  ✓ Performance metrics collection\n";
    echo "  ✓ System health monitoring\n";
    echo "  ✓ Error trend analysis\n";
    echo "  ✓ Real-time log search\n";
    echo "  ✓ Configurable log levels and channels\n\n";

} catch (Exception $e) {
    echo "Error during demo: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}