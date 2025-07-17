<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\TokenValidator;
use Antinna\Auth\Controllers\TokenValidationController;
use Antinna\Auth\Routes\TokenValidationRoutes;
use Antinna\Auth\Middleware\TokenValidationMiddleware;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration test for Token Validation System
 */
class TokenValidationIntegrationTest extends TestCase
{
    private PDO $db;
    private UserRepository $userRepository;
    private JWTManager $jwtManager;
    private TokenValidator $tokenValidator;
    private TokenValidationController $controller;
    private TokenValidationRoutes $routes;
    private TokenValidationMiddleware $middleware;
    private int $testUserId;
    private string $validToken;
    private string $serviceToken;

    protected function setUp(): void
    {
        // Set up test database connection
        $this->db = Connection::getInstance()->getConnection();
        
        // Initialize services
        $this->userRepository = new UserRepository();
        $this->jwtManager = new JWTManager();
        $this->tokenValidator = new TokenValidator();
        $this->controller = new TokenValidationController();
        $this->routes = new TokenValidationRoutes();
        $this->middleware = new TokenValidationMiddleware();

        // Create test data
        $this->createTestUser();
        $this->createTestTokens();
    }

    public function testCompleteTokenValidationWorkflow(): void
    {
        echo "Testing complete token validation workflow...\n";

        // 1. Test token validation service
        $this->testTokenValidationService();

        // 2. Test token validation APIs
        $this->testTokenValidationAPIs();

        // 3. Test middleware integration
        $this->testMiddlewareIntegration();

        // 4. Test service token validation
        $this->testServiceTokenValidation();

        // 5. Test batch validation
        $this->testBatchValidation();

        // 6. Test performance and caching
        $this->testPerformanceAndCaching();

        echo "✓ Complete token validation workflow tested successfully\n";
    }

    private function createTestUser(): void
    {
        $userData = [
            'email' => 'token.test@example.com',
            'phone' => '+1234567896',
            'password_hash' => password_hash('token_test_password', PASSWORD_DEFAULT),
            'name' => 'Token Test User',
            'display_name' => 'Token Test',
            'role' => 'user',
            'is_active' => true,
            'email_verified' => true,
            'phone_verified' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->testUserId = $this->userRepository->create($userData);
        $this->assertGreaterThan(0, $this->testUserId, 'Test user should be created successfully');
    }

    private function createTestTokens(): void
    {
        // Create valid user token
        $userTokenResult = $this->jwtManager->generateToken([
            'user_id' => $this->testUserId,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read', 'user:write', 'profile:update'],
            'iss' => 'auth-service',
            'aud' => 'api-gateway'
        ], 3600);

        $this->assertTrue($userTokenResult['success'], 'User token should be generated successfully');
        $this->validToken = $userTokenResult['token'];

        // Create service token
        $serviceTokenResult = $this->jwtManager->generateToken([
            'service_id' => 'payment-service',
            'token_type' => TokenValidator::TOKEN_TYPE_SERVICE,
            'scopes' => ['service:access', 'payment:process'],
            'iss' => 'auth-service',
            'aud' => 'service-mesh'
        ], 7200);

        $this->assertTrue($serviceTokenResult['success'], 'Service token should be generated successfully');
        $this->serviceToken = $serviceTokenResult['token'];
    }

    private function testTokenValidationService(): void
    {
        echo "  Testing token validation service...\n";

        // Test valid token validation
        $validation = $this->tokenValidator->validateToken($this->validToken, ['user:read']);
        
        $this->assertTrue($validation['valid'], 'Valid token should pass validation');
        $this->assertEquals(TokenValidator::VALIDATION_SUCCESS, $validation['status']);
        $this->assertEquals($this->testUserId, $validation['token_info']['user_id']);
        $this->assertEquals('token.test@example.com', $validation['user_info']['email']);
        $this->assertContains('user:read', $validation['scopes']);
        $this->assertContains('user:write', $validation['scopes']);
        $this->assertArrayHasKey('validation_time', $validation);

        echo "    ✓ Valid token validation\n";

        // Test token with insufficient scopes
        $validation = $this->tokenValidator->validateToken($this->validToken, ['admin:write']);
        
        $this->assertFalse($validation['valid'], 'Token with insufficient scopes should fail');
        $this->assertEquals(TokenValidator::VALIDATION_INSUFFICIENT_SCOPE, $validation['status']);
        $this->assertEquals(['admin:write'], $validation['required_scopes']);

        echo "    ✓ Insufficient scope validation\n";

        // Test wildcard scope matching
        $wildcardTokenResult = $this->jwtManager->generateToken([
            'user_id' => $this->testUserId,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:*', 'admin:read'],
            'iss' => 'auth-service'
        ], 3600);

        $wildcardToken = $wildcardTokenResult['token'];
        $validation = $this->tokenValidator->validateToken($wildcardToken, ['user:read', 'user:write']);
        
        $this->assertTrue($validation['valid'], 'Wildcard scopes should match specific scopes');

        echo "    ✓ Wildcard scope matching\n";

        // Test service token validation
        $serviceValidation = $this->tokenValidator->validateServiceToken($this->serviceToken, 'payment-service');
        
        $this->assertTrue($serviceValidation['valid'], 'Service token should be valid');
        $this->assertEquals('payment-service', $serviceValidation['service_id']);
        $this->assertContains('service:access', $serviceValidation['scopes']);

        echo "    ✓ Service token validation\n";
    }

    private function testTokenValidationAPIs(): void
    {
        echo "  Testing token validation APIs...\n";

        // Test token validation endpoint
        $this->mockJsonInput(json_encode([
            'token' => $this->validToken,
            'scopes' => ['user:read'],
            'use_cache' => false
        ]));

        ob_start();
        $this->controller->validateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Token validation API should succeed');
        $this->assertTrue($response['data']['valid']);
        $this->assertEquals($this->testUserId, $response['data']['token_info']['user_id']);

        echo "    ✓ Token validation endpoint\n";

        // Test user validation endpoint
        $this->mockJsonInput(json_encode([
            'token' => $this->validToken,
            'scopes' => ['user:read']
        ]));

        ob_start();
        $this->controller->validateUser();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'User validation API should succeed');
        $this->assertTrue($response['data']['valid']);
        $this->assertEquals('token.test@example.com', $response['data']['user']['email']);

        echo "    ✓ User validation endpoint\n";

        // Test service validation endpoint
        $this->mockJsonInput(json_encode([
            'token' => $this->serviceToken,
            'service_id' => 'payment-service'
        ]));

        ob_start();
        $this->controller->validateService();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service validation API should succeed');
        $this->assertTrue($response['data']['valid']);
        $this->assertEquals('payment-service', $response['data']['service_id']);

        echo "    ✓ Service validation endpoint\n";

        // Test token inspection endpoint
        $this->mockJsonInput(json_encode([
            'token' => $this->validToken
        ]));

        ob_start();
        $this->controller->inspectToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Token inspection API should succeed');
        $this->assertTrue($response['data']['valid_structure']);
        $this->assertEquals($this->testUserId, $response['data']['user_id']);

        echo "    ✓ Token inspection endpoint\n";
    }

    private function testMiddlewareIntegration(): void
    {
        echo "  Testing middleware integration...\n";

        // Set Authorization header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->validToken;

        // Test middleware validation
        $validation = $this->middleware->validateRequest(['user:read']);
        
        $this->assertTrue($validation['authenticated'], 'Middleware should authenticate valid token');
        $this->assertEquals($this->testUserId, $validation['user']['id']);
        $this->assertContains('user:read', $validation['scopes']);

        echo "    ✓ Middleware token validation\n";

        // Test scope checking
        $this->assertTrue($this->middleware->requireScope('user:read', $validation['scopes']));
        $this->assertFalse($this->middleware->requireScope('admin:write', $validation['scopes']));
        $this->assertTrue($this->middleware->requireAnyScope(['user:read', 'admin:write'], $validation['scopes']));
        $this->assertFalse($this->middleware->requireAllScopes(['user:read', 'admin:write'], $validation['scopes']));

        echo "    ✓ Middleware scope checking\n";

        // Test service middleware
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->serviceToken;
        
        $serviceValidation = $this->middleware->validateServiceRequest('payment-service');
        
        $this->assertTrue($serviceValidation['authenticated'], 'Service middleware should authenticate service token');
        $this->assertEquals('payment-service', $serviceValidation['service_id']);

        echo "    ✓ Service middleware validation\n";

        // Test helper functions
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->validToken;
        
        $auth = \Antinna\Auth\Middleware\requireAuth(['user:read']);
        $this->assertTrue($auth['authenticated']);
        
        $optionalAuth = \Antinna\Auth\Middleware\optionalAuth(['admin:write']);
        $this->assertTrue($optionalAuth['authenticated']); // Token is valid but lacks scope
        
        $user = \Antinna\Auth\Middleware\getCurrentUser();
        $this->assertNotNull($user);
        $this->assertEquals($this->testUserId, $user['id']);
        
        $userId = \Antinna\Auth\Middleware\getCurrentUserId();
        $this->assertEquals($this->testUserId, $userId);
        
        $this->assertTrue(\Antinna\Auth\Middleware\hasScope('user:read'));
        $this->assertFalse(\Antinna\Auth\Middleware\hasScope('admin:write'));
        $this->assertTrue(\Antinna\Auth\Middleware\hasAnyScope(['user:read', 'admin:write']));
        $this->assertFalse(\Antinna\Auth\Middleware\hasAllScopes(['user:read', 'admin:write']));

        echo "    ✓ Helper functions\n";
    }

    private function testServiceTokenValidation(): void
    {
        echo "  Testing service token validation...\n";

        // Test service-to-service authentication
        $validation = $this->tokenValidator->validateServiceToken($this->serviceToken);
        
        $this->assertTrue($validation['valid'], 'Service token should be valid');
        $this->assertEquals('payment-service', $validation['service_id']);
        $this->assertEquals(TokenValidator::TOKEN_TYPE_SERVICE, $validation['token_info']['token_type']);

        echo "    ✓ Service token validation\n";

        // Test service token with wrong expected service
        $validation = $this->tokenValidator->validateServiceToken($this->serviceToken, 'delivery-service');
        
        $this->assertFalse($validation['valid'], 'Service token should fail for wrong service');
        $this->assertEquals('INVALID_SERVICE', $validation['code']);

        echo "    ✓ Wrong service validation\n";
    }

    private function testBatchValidation(): void
    {
        echo "  Testing batch validation...\n";

        // Create multiple tokens for batch testing
        $token2Result = $this->jwtManager->generateToken([
            'user_id' => $this->testUserId,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read'],
            'iss' => 'auth-service'
        ], 3600);

        $tokens = [
            $this->validToken,
            $token2Result['token'],
            'invalid.token.here'
        ];

        // Test batch validation via API
        $this->mockJsonInput(json_encode([
            'tokens' => $tokens,
            'scopes' => ['user:read']
        ]));

        ob_start();
        $this->controller->validateBatch();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Batch validation API should succeed');
        $this->assertEquals(3, $response['data']['summary']['total_tokens']);
        $this->assertEquals(2, $response['data']['summary']['valid_tokens']);
        $this->assertEquals(1, $response['data']['summary']['invalid_tokens']);

        echo "    ✓ Batch validation API\n";

        // Test batch validation service directly
        $batchResult = $this->tokenValidator->validateTokensBatch($tokens, ['user:read']);
        
        $this->assertEquals(3, $batchResult['total_tokens']);
        $this->assertEquals(2, $batchResult['valid_tokens']);
        $this->assertEquals(1, $batchResult['invalid_tokens']);
        $this->assertArrayHasKey('batch_validation_time', $batchResult);

        echo "    ✓ Batch validation service\n";
    }

    private function testPerformanceAndCaching(): void
    {
        echo "  Testing performance and caching...\n";

        // Test caching performance
        $startTime = microtime(true);
        
        // First validation (no cache)
        $result1 = $this->tokenValidator->validateToken($this->validToken, ['user:read'], true);
        $firstValidationTime = microtime(true) - $startTime;
        
        $this->assertTrue($result1['valid']);
        $this->assertFalse($result1['cached']);

        // Second validation (should hit cache)
        $startTime = microtime(true);
        $result2 = $this->tokenValidator->validateToken($this->validToken, ['user:read'], true);
        $secondValidationTime = microtime(true) - $startTime;
        
        $this->assertTrue($result2['valid']);
        $this->assertTrue($result2['cached']);
        
        // Cached validation should be faster
        $this->assertLessThan($firstValidationTime, $secondValidationTime);

        echo "    ✓ Caching performance (cached: {$secondValidationTime}s vs uncached: {$firstValidationTime}s)\n";

        // Test validation statistics
        $stats = $this->tokenValidator->getValidationStats();
        
        $this->assertArrayHasKey('cache_size', $stats);
        $this->assertArrayHasKey('cache_hit_ratio', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
        $this->assertGreaterThan(0, $stats['cache_size']);

        echo "    ✓ Validation statistics (cache size: {$stats['cache_size']}, hit ratio: {$stats['cache_hit_ratio']})\n";

        // Test cache clearing
        $this->tokenValidator->clearCache();
        $statsAfterClear = $this->tokenValidator->getValidationStats();
        $this->assertEquals(0, $statsAfterClear['cache_size']);

        echo "    ✓ Cache clearing\n";
    }

    public function testRoutesIntegration(): void
    {
        echo "Testing routes integration...\n";

        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Test health check route
        ob_start();
        $this->routes->handleRequest('GET', '/api/validate/health');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Health check route should work');
        $this->assertEquals('healthy', $response['data']['status']);

        echo "  ✓ Health check route\n";

        // Test stats route (with mock authorization)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('a', 30); // Mock service token

        ob_start();
        $this->routes->handleRequest('GET', '/api/validate/stats');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Stats route should work');
        $this->assertArrayHasKey('validation_stats', $response['data']);

        echo "  ✓ Stats route\n";

        // Test invalid route
        ob_start();
        $this->routes->handleRequest('GET', '/api/validate/invalid');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Invalid route should return error');
        $this->assertEquals('NOT_FOUND', $response['code']);

        echo "  ✓ Invalid route handling\n";

        // Test method not allowed
        ob_start();
        $this->routes->handleRequest('DELETE', '/api/validate/token');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'DELETE should not be allowed');
        $this->assertEquals('METHOD_NOT_ALLOWED', $response['code']);

        echo "  ✓ Method not allowed handling\n";
    }

    public function testErrorHandling(): void
    {
        echo "Testing error handling...\n";

        // Test missing token
        $this->mockJsonInput(json_encode([]));

        ob_start();
        $this->controller->validateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertEquals('MISSING_TOKEN', $response['code']);

        echo "  ✓ Missing token error\n";

        // Test invalid JSON
        $this->mockJsonInput('invalid json');

        ob_start();
        $this->controller->validateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);

        echo "  ✓ Invalid JSON error\n";

        // Test expired token
        $expiredTokenResult = $this->jwtManager->generateToken([
            'user_id' => $this->testUserId,
            'token_type' => TokenValidator::TOKEN_TYPE_ACCESS,
            'scopes' => ['user:read'],
            'exp' => time() - 3600 // Expired 1 hour ago
        ], -3600);

        $expiredToken = $expiredTokenResult['token'];
        $validation = $this->tokenValidator->validateToken($expiredToken);
        
        $this->assertFalse($validation['valid']);
        $this->assertEquals(TokenValidator::VALIDATION_INVALID, $validation['status']);

        echo "  ✓ Expired token error\n";

        // Test middleware without authorization header
        unset($_SERVER['HTTP_AUTHORIZATION']);
        
        $validation = $this->middleware->validateRequest(['user:read']);
        $this->assertFalse($validation['authenticated']);
        $this->assertEquals('Missing Authorization header', $validation['message']);

        echo "  ✓ Missing authorization header error\n";
    }

    /**
     * Mock JSON input for testing
     */
    private function mockJsonInput(string $json): void
    {
        // Create a temporary stream
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $json);
        rewind($stream);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->testUserId) {
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$this->testUserId]);
        }

        // Clear cache
        $this->tokenValidator->clearCache();

        // Clean up globals
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['REQUEST_METHOD']);
    }
}