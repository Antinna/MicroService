<?php

require_once __DIR__ . '/bootstrap/app.php';

use Antinna\Auth\Services\ServiceTokenManager;
use Antinna\Auth\Services\JWTManager;

echo "=== Service Token Management Test ===\n\n";

try {
    // Initialize services
    $serviceTokenManager = new ServiceTokenManager();
    $jwtManager = new JWTManager();

    echo "1. Testing Service Token Generation...\n";
    
    // Generate a service token for the payment service
    $tokenResult = $serviceTokenManager->generateServiceToken(
        ServiceTokenManager::SERVICE_PAYMENT,
        [ServiceTokenManager::SCOPE_SERVICE_ACCESS, 'payment:process', 'payment:refund']
    );

    if ($tokenResult['success']) {
        echo "   ✓ Service token generated successfully\n";
        echo "   Token ID: {$tokenResult['token_id']}\n";
        echo "   Service: {$tokenResult['service_id']}\n";
        echo "   Scopes: " . implode(', ', $tokenResult['scopes']) . "\n";
        echo "   Expires: {$tokenResult['expires_at']}\n\n";
        
        $serviceToken = $tokenResult['token'];
    } else {
        echo "   ✗ Failed to generate service token: {$tokenResult['message']}\n";
        exit(1);
    }

    echo "2. Testing Service Token Validation...\n";
    
    // Validate the service token
    $validationResult = $serviceTokenManager->validateServiceToken(
        $serviceToken,
        ServiceTokenManager::SERVICE_PAYMENT,
        [ServiceTokenManager::SCOPE_SERVICE_ACCESS]
    );

    if ($validationResult['valid']) {
        echo "   ✓ Service token validated successfully\n";
        echo "   Service: {$validationResult['service_id']}\n";
        echo "   Service Name: {$validationResult['service_name']}\n";
        echo "   Token ID: {$validationResult['token_id']}\n";
        echo "   Scopes: " . implode(', ', $validationResult['scopes']) . "\n\n";
    } else {
        echo "   ✗ Service token validation failed: {$validationResult['message']}\n";
    }

    echo "3. Testing Service Listing...\n";
    
    // List all services
    $servicesResult = $serviceTokenManager->listServices();
    
    if ($servicesResult['success']) {
        echo "   ✓ Services listed successfully\n";
        echo "   Total services: {$servicesResult['total_count']}\n";
        
        foreach ($servicesResult['services'] as $service) {
            echo "   - {$service['service_id']}: {$service['name']} ({$service['status']})\n";
        }
        echo "\n";
    } else {
        echo "   ✗ Failed to list services: {$servicesResult['message']}\n";
    }

    echo "4. Testing Service Info Retrieval...\n";
    
    // Get service info
    $serviceInfoResult = $serviceTokenManager->getServiceInfo(ServiceTokenManager::SERVICE_AUTH);
    
    if ($serviceInfoResult['success']) {
        echo "   ✓ Service info retrieved successfully\n";
        echo "   Service: {$serviceInfoResult['service_id']}\n";
        echo "   Name: {$serviceInfoResult['service_info']['name']}\n";
        echo "   Description: {$serviceInfoResult['service_info']['description']}\n";
        echo "   Status: {$serviceInfoResult['service_info']['status']}\n";
        echo "   Allowed Scopes: " . implode(', ', $serviceInfoResult['service_info']['allowed_scopes']) . "\n\n";
    } else {
        echo "   ✗ Failed to get service info: {$serviceInfoResult['message']}\n";
    }

    echo "5. Testing API Key Generation...\n";
    
    // Generate an API key
    $apiKeyResult = $serviceTokenManager->generateApiKey(
        ServiceTokenManager::SERVICE_DELIVERY,
        [ServiceTokenManager::SCOPE_API_READ, ServiceTokenManager::SCOPE_API_WRITE],
        90 // 90 days expiration
    );

    if ($apiKeyResult['success']) {
        echo "   ✓ API key generated successfully\n";
        echo "   API Key: {$apiKeyResult['api_key']}\n";
        echo "   Key ID: {$apiKeyResult['key_id']}\n";
        echo "   Service: {$apiKeyResult['service_id']}\n";
        echo "   Scopes: " . implode(', ', $apiKeyResult['scopes']) . "\n";
        echo "   Expires: {$apiKeyResult['expires_at']}\n\n";
        
        $apiKey = $apiKeyResult['api_key'];
    } else {
        echo "   ✗ Failed to generate API key: {$apiKeyResult['message']}\n";
    }

    echo "6. Testing API Key Validation...\n";
    
    // Validate the API key
    $apiValidationResult = $serviceTokenManager->validateApiKey(
        $apiKey,
        ServiceTokenManager::SERVICE_DELIVERY,
        [ServiceTokenManager::SCOPE_API_READ]
    );

    if ($apiValidationResult['valid']) {
        echo "   ✓ API key validated successfully\n";
        echo "   Service: {$apiValidationResult['service_id']}\n";
        echo "   Key ID: {$apiValidationResult['key_id']}\n";
        echo "   Scopes: " . implode(', ', $apiValidationResult['scopes']) . "\n\n";
    } else {
        echo "   ✗ API key validation failed: {$apiValidationResult['message']}\n";
    }

    echo "7. Testing Service Registration...\n";
    
    // Register a new service
    $registrationResult = $serviceTokenManager->registerService('test-service', [
        'name' => 'Test Service',
        'description' => 'A test service for demonstration',
        'allowed_scopes' => [ServiceTokenManager::SCOPE_SERVICE_ACCESS, 'test:read', 'test:write']
    ]);

    if ($registrationResult['success']) {
        echo "   ✓ Service registered successfully\n";
        echo "   Service ID: {$registrationResult['service_id']}\n";
        echo "   Registered at: {$registrationResult['registered_at']}\n\n";
    } else {
        echo "   ✗ Failed to register service: {$registrationResult['message']}\n";
    }

    echo "8. Testing Token Revocation...\n";
    
    // Revoke the service token
    $revocationResult = $serviceTokenManager->revokeServiceToken(
        $tokenResult['token_id'],
        'Test cleanup'
    );

    if ($revocationResult['success']) {
        echo "   ✓ Service token revoked successfully\n";
        echo "   Token ID: {$revocationResult['token_id']}\n";
        echo "   Reason: {$revocationResult['reason']}\n";
        echo "   Revoked at: {$revocationResult['revoked_at']}\n\n";
    } else {
        echo "   ✗ Failed to revoke service token: {$revocationResult['message']}\n";
    }

    echo "9. Testing Revoked Token Validation...\n";
    
    // Try to validate the revoked token
    $revokedValidationResult = $serviceTokenManager->validateServiceToken($serviceToken);

    if (!$revokedValidationResult['valid']) {
        echo "   ✓ Revoked token correctly identified as invalid\n";
        echo "   Reason: {$revokedValidationResult['message']}\n\n";
    } else {
        echo "   ✗ Revoked token incorrectly validated as valid\n";
    }

    echo "=== All Service Token Tests Completed Successfully ===\n";

} catch (Exception $e) {
    echo "Error during testing: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}