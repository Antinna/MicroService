<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\ServiceTokenManager;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Controllers\ServiceTokenController;
use Antinna\Auth\Routes\ServiceTokenRoutes;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration test for Service Token Management
 */
class ServiceTokenIntegrationTest extends TestCase
{
    private PDO $db;
    private ServiceTokenManager $serviceTokenManager;
    private JWTManager $jwtManager;
    private ServiceTokenController $controller;
    private ServiceTokenRoutes $routes;
    private string $adminServiceToken;
    private string $regularServiceToken;

    protected function setUp(): void
    {
        // Set up test database connection
        $this->db = Connection::getInstance()->getConnection();
        
        // Initialize services
        $this->serviceTokenManager = new ServiceTokenManager();
        $this->jwtManager = new JWTManager();
        $this->controller = new ServiceTokenController();
        $this->routes = new ServiceTokenRoutes();

        // Create test tokens
        $this->createTestTokens();
    }

    public function testCompleteServiceTokenWorkflow(): void
    {
        echo "Testing complete service token workflow...\n";

        // 1. Test service token generation
        $this->testServiceTokenGeneration();

        // 2. Test service token validation
        $this->testServiceTokenValidation();

        // 3. Test service management
        $this->testServiceManagement();

        // 4. Test API key management
        $this->testApiKeyManagement();

        // 5. Test token revocation
        $this->testTokenRevocation();

        // 6. Test routes integration
        $this->testRoutesIntegration();

        echo "✓ Complete service token workflow tested successfully\n";
    }

    private function createTestTokens(): void
    {
        // Create admin service token
        $adminTokenResult = $this->jwtManager->generateToken([
            'service_id' => ServiceTokenManager::SERVICE_AUTH,
            'service_name' => 'Authentication Service',
            'token_type' => ServiceTokenManager::TOKEN_TYPE_SERVICE,
            'scopes' => [
                ServiceTokenManager::SCOPE_SERVICE_ACCESS,
                ServiceTokenManager::SCOPE_SERVICE_ADMIN,
                ServiceTokenManager::SCOPE_INTERNAL_COMMUNICATION,
                'system:config'
            ],
            'iss' => 'auth-service',
            'aud' => 'service-mesh',
            'jti' => 'st_auth-service_test_admin'
        ], 3600);

        $this->assertTrue($adminTokenResult['success'], 'Admin service token should be generated successfully');
        $this->adminServiceToken = $adminTokenResult['token'];

        // Create regular service token
        $regularTokenResult = $this->jwtManager->generateToken([
            'service_id' => ServiceTokenManager::SERVICE_PAYMENT,
            'service_name' => 'Payment Service',
            'token_type' => ServiceTokenManager::TOKEN_TYPE_SERVICE,
            'scopes' => [
                ServiceTokenManager::SCOPE_SERVICE_ACCESS,
                'payment:process'
            ],
            'iss' => 'auth-service',
            'aud' => 'service-mesh',
            'jti' => 'st_payment-service_test_regular'
        ], 3600);

        $this->assertTrue($regularTokenResult['success'], 'Regular service token should be generated successfully');
        $this->regularServiceToken = $regularTokenResult['token'];
    }

    private function testServiceTokenGeneration(): void
    {
        echo "  Testing service token generation...\n";

        // Set admin service token in Authorization header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->adminServiceToken;

        // Test generating a service token
        $this->mockJsonInput(json_encode([
            'service_id' => ServiceTokenManager::SERVICE_DELIVERY,
            'scopes' => [ServiceTokenManager::SCOPE_SERVICE_ACCESS, 'delivery:track'],
            'expiration_time' => 3600
        ]));

        ob_start();
        $this->controller->generateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service token generation should succeed');
        $this->assertArrayHasKey('token', $response['data']);
        $this->assertArrayHasKey('token_id', $response['data']);
        $this->assertEquals(ServiceTokenManager::SERVICE_DELIVERY, $response['data']['service_id']);
        $this->assertContains(ServiceTokenManager::SCOPE_SERVICE_ACCESS, $response['data']['scopes']);
        $this->assertContains('delivery:track', $response['data']['scopes']);

        echo "    ✓ Service token generation\n";

        // Test generating a token with insufficient privileges
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->regularServiceToken;

        ob_start();
        $this->controller->generateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Regular service should not be able to generate tokens');
        $this->assertEquals('INSUFFICIENT_SCOPES', $response['code']);

        echo "    ✓ Insufficient privileges check\n";

        // Restore admin token for further tests
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->adminServiceToken;
    }

    private function testServiceTokenValidation(): void
    {
        echo "  Testing service token validation...\n";

        // Test validating a service token
        $this->mockJsonInput(json_encode([
            'token' => $this->regularServiceToken,
            'expected_service' => ServiceTokenManager::SERVICE_PAYMENT,
            'required_scopes' => [ServiceTokenManager::SCOPE_SERVICE_ACCESS]
        ]));

        ob_start();
        $this->controller->validateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service token validation should succeed');
        $this->assertTrue($response['data']['valid']);
        $this->assertEquals(ServiceTokenManager::SERVICE_PAYMENT, $response['data']['service_id']);
        $this->assertContains(ServiceTokenManager::SCOPE_SERVICE_ACCESS, $response['data']['scopes']);
        $this->assertContains('payment:process', $response['data']['scopes']);

        echo "    ✓ Service token validation\n";

        // Test validating with wrong expected service
        $this->mockJsonInput(json_encode([
            'token' => $this->regularServiceToken,
            'expected_service' => ServiceTokenManager::SERVICE_DELIVERY
        ]));

        ob_start();
        $this->controller->validateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Validation with wrong service should fail');
        $this->assertEquals('SERVICE_MISMATCH', $response['code']);

        echo "    ✓ Wrong service validation\n";

        // Test validating with missing required scopes
        $this->mockJsonInput(json_encode([
            'token' => $this->regularServiceToken,
            'required_scopes' => ['payment:process', 'payment:refund']
        ]));

        ob_start();
        $this->controller->validateToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Validation with missing scopes should fail');
        $this->assertEquals('INSUFFICIENT_SCOPES', $response['code']);

        echo "    ✓ Missing scopes validation\n";
    }

    private function testServiceManagement(): void
    {
        echo "  Testing service management...\n";

        // Test listing services
        ob_start();
        $this->controller->listServices();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service listing should succeed');
        $this->assertArrayHasKey('services', $response['data']);
        $this->assertGreaterThan(0, $response['data']['total_count']);

        echo "    ✓ Service listing\n";

        // Test getting service info
        $_GET['service_id'] = ServiceTokenManager::SERVICE_AUTH;
        
        ob_start();
        $this->controller->getServiceInfo(ServiceTokenManager::SERVICE_AUTH);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service info retrieval should succeed');
        $this->assertEquals(ServiceTokenManager::SERVICE_AUTH, $response['data']['service_id']);
        $this->assertArrayHasKey('service_info', $response['data']);

        echo "    ✓ Service info retrieval\n";

        // Test registering a new service
        $this->mockJsonInput(json_encode([
            'service_id' => 'test-integration-service',
            'config' => [
                'name' => 'Test Integration Service',
                'description' => 'A service for integration testing',
                'allowed_scopes' => [ServiceTokenManager::SCOPE_SERVICE_ACCESS, 'test:read']
            ]
        ]));

        ob_start();
        $this->controller->registerService();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service registration should succeed');
        $this->assertEquals('test-integration-service', $response['data']['service_id']);

        echo "    ✓ Service registration\n";
    }

    private function testApiKeyManagement(): void
    {
        echo "  Testing API key management...\n";

        // Test generating an API key
        $this->mockJsonInput(json_encode([
            'service_id' => ServiceTokenManager::SERVICE_PAYMENT,
            'scopes' => [ServiceTokenManager::SCOPE_API_READ, ServiceTokenManager::SCOPE_API_WRITE],
            'expiration_days' => 90
        ]));

        ob_start();
        $this->controller->generateApiKey();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'API key generation should succeed');
        $this->assertStringStartsWith('ak_', $response['data']['api_key']);
        $this->assertStringStartsWith('key_', $response['data']['key_id']);
        $this->assertEquals(ServiceTokenManager::SERVICE_PAYMENT, $response['data']['service_id']);

        echo "    ✓ API key generation\n";

        // Store the API key for validation test
        $apiKey = $response['data']['api_key'];

        // Test validating the API key
        $this->mockJsonInput(json_encode([
            'api_key' => $apiKey,
            'expected_service' => ServiceTokenManager::SERVICE_PAYMENT,
            'required_scopes' => [ServiceTokenManager::SCOPE_API_READ]
        ]));

        ob_start();
        $this->controller->validateApiKey();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'API key validation should succeed');
        $this->assertTrue($response['data']['valid']);
        $this->assertEquals(ServiceTokenManager::SERVICE_PAYMENT, $response['data']['service_id']);

        echo "    ✓ API key validation\n";
    }

    private function testTokenRevocation(): void
    {
        echo "  Testing token revocation...\n";

        // Generate a token to revoke
        $tokenResult = $this->serviceTokenManager->generateServiceToken(
            ServiceTokenManager::SERVICE_DELIVERY,
            [ServiceTokenManager::SCOPE_SERVICE_ACCESS]
        );

        $this->assertTrue($tokenResult['success'], 'Token generation for revocation test should succeed');
        $tokenId = $tokenResult['token_id'];

        // Test revoking the token
        $this->mockJsonInput(json_encode([
            'token_id' => $tokenId,
            'reason' => 'Integration test cleanup'
        ]));

        ob_start();
        $this->controller->revokeToken();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Token revocation should succeed');
        $this->assertEquals($tokenId, $response['data']['token_id']);

        echo "    ✓ Token revocation\n";

        // Verify the token is no longer valid
        $validationResult = $this->serviceTokenManager->validateServiceToken($tokenResult['token']);
        $this->assertFalse($validationResult['valid'], 'Revoked token should be invalid');

        echo "    ✓ Revoked token validation\n";
    }

    private function testRoutesIntegration(): void
    {
        echo "  Testing routes integration...\n";

        // Test service listing route
        ob_start();
        $this->routes->handleRequest('GET', '/api/services');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Services route should work');

        echo "    ✓ Services listing route\n";

        // Test service info route
        ob_start();
        $this->routes->handleRequest('GET', '/api/services/' . ServiceTokenManager::SERVICE_AUTH);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success'], 'Service info route should work');

        echo "    ✓ Service info route\n";

        // Test invalid route
        ob_start();
        $this->routes->handleRequest('GET', '/api/invalid-endpoint');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Invalid route should return error');
        $this->assertEquals('NOT_FOUND', $response['code']);

        echo "    ✓ Invalid route handling\n";

        // Test method not allowed
        ob_start();
        $this->routes->handleRequest('DELETE', '/api/services');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success'], 'Unsupported method should return error');
        $this->assertEquals('METHOD_NOT_ALLOWED', $response['code']);

        echo "    ✓ Method not allowed handling\n";
    }

    /**
     * Mock JSON input for testing
     */
    private function mockJsonInput(string $json): void
    {
        // Create a temporary stream with the JSON data
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $json);
        rewind($stream);
        
        // Mock php://input
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', TestStreamWrapper::class);
        TestStreamWrapper::$inputData = $json;
    }

    protected function tearDown(): void
    {
        // Clean up any test data
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }
        
        if (isset($_GET['service_id'])) {
            unset($_GET['service_id']);
        }

        // Restore original stream wrapper
        if (in_array('php', stream_get_wrappers())) {
            stream_wrapper_restore('php');
        }
    }
}

/**
 * Test stream wrapper for mocking php://input
 */
class TestStreamWrapper
{
    public static string $inputData = '';
    private $position = 0;

    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        if ($path === 'php://input') {
            $this->position = 0;
            return true;
        }
        return false;
    }

    public function stream_read($count): string
    {
        $ret = substr(self::$inputData, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$inputData);
    }

    public function stream_stat(): array
    {
        return [];
    }
}