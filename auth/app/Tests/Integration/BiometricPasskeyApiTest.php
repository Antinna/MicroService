<?php

namespace Antinna\Auth\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\BiometricHandler;
use Antinna\Auth\Services\PasskeyHandler;
use Antinna\Auth\Repositories\UserRepository;

class BiometricPasskeyApiTest extends TestCase
{
    private $connection;
    private $userRepository;
    private $biometricHandler;
    private $passkeyHandler;
    private $baseUrl;
    private $testUserId;
    private $accessToken;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->connection = Connection::getInstance();
        $this->connection->beginTransaction();
        
        $this->userRepository = new UserRepository($this->connection);
        $this->biometricHandler = new BiometricHandler($this->connection);
        $this->passkeyHandler = new PasskeyHandler($this->connection);
        
        $this->baseUrl = 'http://localhost:8080/auth';
        
        $this->createTestUserAndLogin();
    }

    protected function tearDown(): void
    {
        $this->connection->rollback();
        parent::tearDown();
    }

    private function createTestUserAndLogin(): void
    {
        // Create test user
        $userData = [
            'email' => 'biometric-test@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'name' => 'Biometric Test User',
            'phone_number' => '+1234567890',
            'is_active' => 1,
            'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $this->testUserId = $this->userRepository->create($userData);

        // Login to get access token
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'biometric-test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $this->accessToken = $loginResponse['body']['data']['access_token'];
    }

    // Biometric Authentication Tests

    public function testBiometricEnrollment()
    {
        $enrollmentData = [
            'biometric_type' => 'fingerprint',
            'biometric_data' => [
                'template' => base64_encode('mock_biometric_template_data'),
                'quality_score' => 0.95
            ],
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'iPhone 13',
                'os_version' => 'iOS 15.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/biometric/enroll', $enrollmentData, [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('biometric_id', $response['body']['data']);
        $this->assertArrayHasKey('enrollment_token', $response['body']['data']);
        $this->assertEquals('fingerprint', $response['body']['data']['biometric_type']);
        $this->assertEquals('Biometric enrollment successful', $response['body']['message']);
    }

    public function testBiometricEnrollmentWithInvalidData()
    {
        $enrollmentData = [
            'biometric_type' => 'invalid_type',
            'biometric_data' => [
                'template' => '', // Empty template
                'quality_score' => 0.1 // Low quality
            ],
            'device_info' => [
                'device_id' => '',
                'device_name' => '',
                'os_version' => ''
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/biometric/enroll', $enrollmentData, [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_BIOMETRIC_DATA', $response['body']['error']['code']);
    }

    public function testBiometricAuthentication()
    {
        // First enroll biometric
        $this->enrollBiometric();

        $authData = [
            'biometric_type' => 'fingerprint',
            'biometric_data' => [
                'template' => base64_encode('mock_biometric_template_data'),
                'quality_score' => 0.95
            ],
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'iPhone 13',
                'os_version' => 'iOS 15.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/biometric/authenticate', $authData);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('user_id', $response['body']['data']);
        $this->assertArrayHasKey('auth_token', $response['body']['data']);
        $this->assertEquals('fingerprint', $response['body']['data']['biometric_type']);
        $this->assertEquals('Biometric authentication successful', $response['body']['message']);
    }

    public function testBiometricAuthenticationWithUnregisteredBiometric()
    {
        $authData = [
            'biometric_type' => 'fingerprint',
            'biometric_data' => [
                'template' => base64_encode('unregistered_biometric_template'),
                'quality_score' => 0.95
            ],
            'device_info' => [
                'device_id' => 'unknown-device-456',
                'device_name' => 'Unknown Device',
                'os_version' => 'iOS 15.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/biometric/authenticate', $authData);

        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('BIOMETRIC_NOT_RECOGNIZED', $response['body']['error']['code']);
    }

    public function testRemoveBiometric()
    {
        // First enroll biometric
        $biometricId = $this->enrollBiometric();

        $response = $this->makeApiRequest('DELETE', "/api/auth/biometric/{$biometricId}", [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals($biometricId, $response['body']['data']['biometric_id']);
        $this->assertEquals('Biometric removed successfully', $response['body']['message']);
    }

    public function testListBiometrics()
    {
        // Enroll multiple biometrics
        $this->enrollBiometric('fingerprint');
        $this->enrollBiometric('face');

        $response = $this->makeApiRequest('GET', '/api/auth/biometric/list', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertCount(2, $response['body']['data']['biometrics']);
        $this->assertContains('fingerprint', array_column($response['body']['data']['biometrics'], 'biometric_type'));
        $this->assertContains('face', array_column($response['body']['data']['biometrics'], 'biometric_type'));
    }

    // Passkey (WebAuthn) Tests

    public function testPasskeyRegistrationBegin()
    {
        $response = $this->makeApiRequest('POST', '/api/auth/passkey/register/begin', [
            'device_name' => 'MacBook Pro'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('challenge', $response['body']['data']);
        $this->assertArrayHasKey('rp', $response['body']['data']);
        $this->assertArrayHasKey('user', $response['body']['data']);
        $this->assertArrayHasKey('pubKeyCredParams', $response['body']['data']);
        $this->assertEquals('Registration challenge created', $response['body']['message']);
    }

    public function testPasskeyRegistrationComplete()
    {
        // First begin registration
        $beginResponse = $this->makeApiRequest('POST', '/api/auth/passkey/register/begin', [
            'device_name' => 'MacBook Pro'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $challenge = $beginResponse['body']['data']['challenge'];

        // Mock WebAuthn credential response
        $credentialData = [
            'id' => 'mock-credential-id-123',
            'rawId' => base64_encode('mock-raw-id'),
            'response' => [
                'clientDataJSON' => base64_encode(json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => base64_encode($challenge),
                    'origin' => 'https://example.com'
                ])),
                'attestationObject' => base64_encode('mock-attestation-object')
            ],
            'type' => 'public-key'
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/passkey/register/complete', $credentialData, [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('credential_id', $response['body']['data']);
        $this->assertEquals('MacBook Pro', $response['body']['data']['device_name']);
        $this->assertEquals('Passkey registered successfully', $response['body']['message']);
    }

    public function testPasskeyAuthenticationBegin()
    {
        $response = $this->makeApiRequest('POST', '/api/auth/passkey/authenticate/begin', [
            'email' => 'biometric-test@example.com'
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('challenge', $response['body']['data']);
        $this->assertArrayHasKey('allowCredentials', $response['body']['data']);
        $this->assertArrayHasKey('timeout', $response['body']['data']);
        $this->assertEquals('Authentication challenge created', $response['body']['message']);
    }

    public function testPasskeyAuthenticationComplete()
    {
        // First register a passkey
        $this->registerPasskey();

        // Begin authentication
        $beginResponse = $this->makeApiRequest('POST', '/api/auth/passkey/authenticate/begin', [
            'email' => 'biometric-test@example.com'
        ]);

        $challenge = $beginResponse['body']['data']['challenge'];

        // Mock WebAuthn authentication response
        $authData = [
            'id' => 'mock-credential-id-123',
            'rawId' => base64_encode('mock-raw-id'),
            'response' => [
                'clientDataJSON' => base64_encode(json_encode([
                    'type' => 'webauthn.get',
                    'challenge' => base64_encode($challenge),
                    'origin' => 'https://example.com'
                ])),
                'authenticatorData' => base64_encode('mock-authenticator-data'),
                'signature' => base64_encode('mock-signature'),
                'userHandle' => base64_encode((string)$this->testUserId)
            ],
            'type' => 'public-key'
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/passkey/authenticate/complete', $authData);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('access_token', $response['body']['data']);
        $this->assertArrayHasKey('refresh_token', $response['body']['data']);
        $this->assertEquals('Passkey authentication successful', $response['body']['message']);
    }

    public function testPasskeyAuthenticationWithInvalidCredential()
    {
        $authData = [
            'id' => 'invalid-credential-id',
            'rawId' => base64_encode('invalid-raw-id'),
            'response' => [
                'clientDataJSON' => base64_encode(json_encode([
                    'type' => 'webauthn.get',
                    'challenge' => base64_encode('invalid-challenge'),
                    'origin' => 'https://example.com'
                ])),
                'authenticatorData' => base64_encode('invalid-authenticator-data'),
                'signature' => base64_encode('invalid-signature'),
                'userHandle' => base64_encode('invalid-user-handle')
            ],
            'type' => 'public-key'
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/passkey/authenticate/complete', $authData);

        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_PASSKEY_CREDENTIAL', $response['body']['error']['code']);
    }

    public function testListPasskeys()
    {
        // Register multiple passkeys
        $this->registerPasskey('MacBook Pro');
        $this->registerPasskey('iPhone 13');

        $response = $this->makeApiRequest('GET', '/api/auth/passkey/list', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertCount(2, $response['body']['data']['passkeys']);
        $this->assertContains('MacBook Pro', array_column($response['body']['data']['passkeys'], 'device_name'));
        $this->assertContains('iPhone 13', array_column($response['body']['data']['passkeys'], 'device_name'));
    }

    public function testRemovePasskey()
    {
        // First register a passkey
        $credentialId = $this->registerPasskey();

        $response = $this->makeApiRequest('DELETE', "/api/auth/passkey/{$credentialId}", [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals($credentialId, $response['body']['data']['credential_id']);
        $this->assertEquals('Passkey removed successfully', $response['body']['message']);
    }

    public function testPasskeyWithUserVerification()
    {
        // Begin authentication with user verification required
        $beginResponse = $this->makeApiRequest('POST', '/api/auth/passkey/authenticate/begin', [
            'email' => 'biometric-test@example.com',
            'user_verification' => 'required'
        ]);

        $this->assertEquals(200, $beginResponse['status']);
        $this->assertEquals('required', $beginResponse['body']['data']['userVerification']);
    }

    public function testCrossOriginPasskeyAuthentication()
    {
        // Test passkey authentication from different origins
        $beginResponse = $this->makeApiRequest('POST', '/api/auth/passkey/authenticate/begin', [
            'email' => 'biometric-test@example.com'
        ], [
            'Origin: https://different-origin.com'
        ]);

        // Should still work but with proper origin validation
        $this->assertEquals(200, $beginResponse['status']);
        $this->assertTrue($beginResponse['body']['success']);
    }

    private function enrollBiometric(string $type = 'fingerprint'): int
    {
        $enrollmentData = [
            'biometric_type' => $type,
            'biometric_data' => [
                'template' => base64_encode("mock_{$type}_template_data"),
                'quality_score' => 0.95
            ],
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'os_version' => 'iOS 15.0'
            ]
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/biometric/enroll', $enrollmentData, [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        return $response['body']['data']['biometric_id'];
    }

    private function registerPasskey(string $deviceName = 'MacBook Pro'): string
    {
        // Begin registration
        $beginResponse = $this->makeApiRequest('POST', '/api/auth/passkey/register/begin', [
            'device_name' => $deviceName
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $challenge = $beginResponse['body']['data']['challenge'];
        $credentialId = 'mock-credential-id-' . uniqid();

        // Complete registration
        $credentialData = [
            'id' => $credentialId,
            'rawId' => base64_encode($credentialId),
            'response' => [
                'clientDataJSON' => base64_encode(json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => base64_encode($challenge),
                    'origin' => 'https://example.com'
                ])),
                'attestationObject' => base64_encode('mock-attestation-object')
            ],
            'type' => 'public-key'
        ];

        $response = $this->makeApiRequest('POST', '/api/auth/passkey/register/complete', $credentialData, [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        return $response['body']['data']['credential_id'];
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
}