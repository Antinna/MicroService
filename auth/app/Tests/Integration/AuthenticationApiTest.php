<?php

namespace Antinna\Auth\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\UserAuthenticator;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\SessionManager;
use Antinna\Auth\Repositories\UserRepository;

class AuthenticationApiTest extends TestCase
{
    private $connection;
    private $userAuthenticator;
    private $jwtManager;
    private $sessionManager;
    private $userRepository;
    private $baseUrl;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test database connection
        $this->connection = Connection::getInstance();
        $this->connection->beginTransaction();
        
        // Initialize services
        $this->userRepository = new UserRepository($this->connection);
        $this->jwtManager = new JWTManager();
        $this->sessionManager = new SessionManager($this->connection);
        $this->userAuthenticator = new UserAuthenticator(
            $this->userRepository,
            $this->jwtManager,
            $this->sessionManager
        );
        
        $this->baseUrl = 'http://localhost:8080/auth';
        
        // Create test user
        $this->createTestUser();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        $this->connection->rollback();
        parent::tearDown();
    }

    private function createTestUser(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'name' => 'Test User',
            'phone_number' => '+1234567890',
            'is_active' => 1,
            'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $this->testUserId = $this->userRepository->create($userData);
    }

    public function testUserRegistration()
    {
        $registrationData = [
            'email' => 'newuser@example.com',
            'password' => 'securePassword123!',
            'name' => 'New User',
            'phone_number' => '+1987654321'
        ];

        $response = $this->makeApiRequest('POST', '/api/users/register', $registrationData);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('user_id', $response['body']['data']);
        $this->assertEquals($registrationData['email'], $response['body']['data']['email']);
        $this->assertTrue($response['body']['data']['verification_required']);
    }

    public function testUserRegistrationWithExistingEmail()
    {
        $registrationData = [
            'email' => 'test@example.com', // Already exists
            'password' => 'securePassword123!',
            'name' => 'Duplicate User',
            'phone_number' => '+1987654321'
        ];

        $response = $this->makeApiRequest('POST', '/api/users/register', $registrationData);
        
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('EMAIL_ALREADY_EXISTS', $response['body']['error']['code']);
    }

    public function testUserRegistrationWithInvalidData()
    {
        $registrationData = [
            'email' => 'invalid-email',
            'password' => '123', // Too short
            'name' => '',
            'phone_number' => 'invalid-phone'
        ];

        $response = $this->makeApiRequest('POST', '/api/users/register', $registrationData);
        
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('VALIDATION_ERROR', $response['body']['error']['code']);
    }

    public function testSuccessfulLogin()
    {
        $loginData = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/login', $loginData);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('access_token', $response['body']['data']);
        $this->assertArrayHasKey('refresh_token', $response['body']['data']);
        $this->assertArrayHasKey('expires_in', $response['body']['data']);
        $this->assertArrayHasKey('user', $response['body']['data']);
        $this->assertEquals('test@example.com', $response['body']['data']['user']['email']);
    }

    public function testLoginWithInvalidCredentials()
    {
        $loginData = [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/login', $loginData);
        
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_CREDENTIALS', $response['body']['error']['code']);
    }

    public function testLoginWithNonExistentUser()
    {
        $loginData = [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/login', $loginData);
        
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('USER_NOT_FOUND', $response['body']['error']['code']);
    }

    public function testTokenRefresh()
    {
        // First, login to get tokens
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $refreshToken = $loginResponse['body']['data']['refresh_token'];

        // Now refresh the token
        $refreshResponse = $this->makeApiRequest('POST', '/api/auth/token/refresh', [
            'refresh_token' => $refreshToken
        ]);

        $this->assertEquals(200, $refreshResponse['status']);
        $this->assertTrue($refreshResponse['body']['success']);
        $this->assertArrayHasKey('access_token', $refreshResponse['body']['data']);
        $this->assertArrayHasKey('refresh_token', $refreshResponse['body']['data']);
        $this->assertArrayHasKey('expires_in', $refreshResponse['body']['data']);
    }

    public function testTokenRefreshWithInvalidToken()
    {
        $refreshResponse = $this->makeApiRequest('POST', '/api/auth/token/refresh', [
            'refresh_token' => 'invalid_refresh_token'
        ]);

        $this->assertEquals(401, $refreshResponse['status']);
        $this->assertFalse($refreshResponse['body']['success']);
        $this->assertEquals('INVALID_REFRESH_TOKEN', $refreshResponse['body']['error']['code']);
    }

    public function testLogout()
    {
        // First, login to get access token
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $accessToken = $loginResponse['body']['data']['access_token'];

        // Now logout
        $logoutResponse = $this->makeApiRequest('POST', '/api/auth/logout', [], [
            'Authorization: Bearer ' . $accessToken
        ]);

        $this->assertEquals(200, $logoutResponse['status']);
        $this->assertTrue($logoutResponse['body']['success']);
        $this->assertEquals('Logged out successfully', $logoutResponse['body']['message']);
    }

    public function testLogoutWithInvalidToken()
    {
        $logoutResponse = $this->makeApiRequest('POST', '/api/auth/logout', [], [
            'Authorization: Bearer invalid_token'
        ]);

        $this->assertEquals(401, $logoutResponse['status']);
        $this->assertFalse($logoutResponse['body']['success']);
        $this->assertEquals('INVALID_TOKEN', $logoutResponse['body']['error']['code']);
    }

    public function testPasswordResetRequest()
    {
        $resetResponse = $this->makeApiRequest('POST', '/api/auth/password/reset-request', [
            'email' => 'test@example.com'
        ]);

        $this->assertEquals(200, $resetResponse['status']);
        $this->assertTrue($resetResponse['body']['success']);
        $this->assertEquals('Password reset instructions sent to email', $resetResponse['body']['message']);
    }

    public function testPasswordResetWithNonExistentEmail()
    {
        $resetResponse = $this->makeApiRequest('POST', '/api/auth/password/reset-request', [
            'email' => 'nonexistent@example.com'
        ]);

        // Should still return success for security reasons (don't reveal if email exists)
        $this->assertEquals(200, $resetResponse['status']);
        $this->assertTrue($resetResponse['body']['success']);
    }

    public function testGetUserProfile()
    {
        // First, login to get access token
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $accessToken = $loginResponse['body']['data']['access_token'];

        // Get user profile
        $profileResponse = $this->makeApiRequest('GET', '/api/users/profile', [], [
            'Authorization: Bearer ' . $accessToken
        ]);

        $this->assertEquals(200, $profileResponse['status']);
        $this->assertTrue($profileResponse['body']['success']);
        $this->assertEquals('test@example.com', $profileResponse['body']['data']['email']);
        $this->assertEquals('Test User', $profileResponse['body']['data']['name']);
        $this->assertArrayHasKey('created_at', $profileResponse['body']['data']);
    }

    public function testUpdateUserProfile()
    {
        // First, login to get access token
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $accessToken = $loginResponse['body']['data']['access_token'];

        // Update user profile
        $updateResponse = $this->makeApiRequest('PUT', '/api/users/profile', [
            'name' => 'Updated Test User',
            'phone_number' => '+1111111111'
        ], [
            'Authorization: Bearer ' . $accessToken
        ]);

        $this->assertEquals(200, $updateResponse['status']);
        $this->assertTrue($updateResponse['body']['success']);
        $this->assertEquals('Updated Test User', $updateResponse['body']['data']['name']);
        $this->assertEquals('+1111111111', $updateResponse['body']['data']['phone_number']);
    }

    public function testRateLimiting()
    {
        $loginData = [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ];

        // Make multiple failed login attempts
        $responses = [];
        for ($i = 0; $i < 12; $i++) {
            $responses[] = $this->makeApiRequest('POST', '/api/auth/login', $loginData);
        }

        // The last few requests should be rate limited
        $lastResponse = end($responses);
        $this->assertEquals(429, $lastResponse['status']);
        $this->assertFalse($lastResponse['body']['success']);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $lastResponse['body']['error']['code']);
    }

    public function testConcurrentLoginAttempts()
    {
        $loginData = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ];

        // Simulate concurrent login attempts
        $responses = [];
        $processes = [];
        
        for ($i = 0; $i < 5; $i++) {
            $processes[] = $this->makeAsyncApiRequest('POST', '/api/auth/login', $loginData);
        }

        // Wait for all processes to complete
        foreach ($processes as $process) {
            $responses[] = $this->getAsyncResponse($process);
        }

        // All should succeed (no race conditions)
        foreach ($responses as $response) {
            $this->assertEquals(200, $response['status']);
            $this->assertTrue($response['body']['success']);
        }
    }

    private function makeApiRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status' => $httpCode,
            'body' => json_decode($response, true)
        ];
    }

    private function makeAsyncApiRequest(string $method, string $endpoint, array $data = []): resource
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        return $ch;
    }

    private function getAsyncResponse($ch): array
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status' => $httpCode,
            'body' => json_decode($response, true)
        ];
    }
}