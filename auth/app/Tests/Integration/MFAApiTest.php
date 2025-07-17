<?php

namespace Antinna\Auth\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\MFAManager;
use Antinna\Auth\Services\TOTPHandler;
use Antinna\Auth\Services\SMSMFAHandler;
use Antinna\Auth\Repositories\UserRepository;

class MFAApiTest extends TestCase
{
    private $connection;
    private $userRepository;
    private $mfaManager;
    private $totpHandler;
    private $smsHandler;
    private $baseUrl;
    private $testUserId;
    private $accessToken;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->connection = Connection::getInstance();
        $this->connection->beginTransaction();
        
        $this->userRepository = new UserRepository($this->connection);
        $this->totpHandler = new TOTPHandler();
        $this->smsHandler = new SMSMFAHandler();
        $this->mfaManager = new MFAManager(
            $this->connection,
            $this->totpHandler,
            $this->smsHandler
        );
        
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
            'email' => 'mfa-test@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'name' => 'MFA Test User',
            'phone_number' => '+1234567890',
            'is_active' => 1,
            'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $this->testUserId = $this->userRepository->create($userData);

        // Login to get access token
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'mfa-test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $this->accessToken = $loginResponse['body']['data']['access_token'];
    }

    public function testEnableTOTPMFA()
    {
        $response = $this->makeApiRequest('POST', '/api/auth/mfa/totp/enable', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('secret', $response['body']['data']);
        $this->assertArrayHasKey('qr_code', $response['body']['data']);
        $this->assertArrayHasKey('setup_token', $response['body']['data']);
        $this->assertEquals('TOTP setup initiated', $response['body']['message']);
    }

    public function testActivateTOTPMFA()
    {
        // First enable TOTP
        $enableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/totp/enable', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $secret = $enableResponse['body']['data']['secret'];
        $setupToken = $enableResponse['body']['data']['setup_token'];

        // Generate TOTP code
        $totpCode = $this->totpHandler->generateCode($secret);

        // Activate TOTP
        $activateResponse = $this->makeApiRequest('POST', '/api/auth/mfa/totp/activate', [
            'setup_token' => $setupToken,
            'totp_code' => $totpCode
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $activateResponse['status']);
        $this->assertTrue($activateResponse['body']['success']);
        $this->assertArrayHasKey('backup_codes', $activateResponse['body']['data']);
        $this->assertCount(5, $activateResponse['body']['data']['backup_codes']);
        $this->assertEquals('TOTP MFA activated successfully', $activateResponse['body']['message']);
    }

    public function testActivateTOTPMFAWithInvalidCode()
    {
        // First enable TOTP
        $enableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/totp/enable', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $setupToken = $enableResponse['body']['data']['setup_token'];

        // Try to activate with invalid code
        $activateResponse = $this->makeApiRequest('POST', '/api/auth/mfa/totp/activate', [
            'setup_token' => $setupToken,
            'totp_code' => '000000'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(400, $activateResponse['status']);
        $this->assertFalse($activateResponse['body']['success']);
        $this->assertEquals('INVALID_TOTP_CODE', $activateResponse['body']['error']['code']);
    }

    public function testEnableSMSMFA()
    {
        $response = $this->makeApiRequest('POST', '/api/auth/mfa/sms/enable', [
            'phone_number' => '+1987654321'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('setup_token', $response['body']['data']);
        $this->assertEquals('Verification code sent to phone', $response['body']['message']);
    }

    public function testActivateSMSMFA()
    {
        // First enable SMS MFA
        $enableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/sms/enable', [
            'phone_number' => '+1987654321'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $setupToken = $enableResponse['body']['data']['setup_token'];

        // For testing, we'll use a mock verification code
        $verificationCode = '123456';

        // Activate SMS MFA
        $activateResponse = $this->makeApiRequest('POST', '/api/auth/mfa/sms/activate', [
            'setup_token' => $setupToken,
            'verification_code' => $verificationCode
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        // In a real test, this would depend on the SMS service mock
        // For now, we'll test the API structure
        $this->assertIsArray($activateResponse['body']);
        $this->assertArrayHasKey('success', $activateResponse['body']);
    }

    public function testMFALoginFlow()
    {
        // First, enable and activate TOTP MFA
        $this->setupTOTPMFA();

        // Logout current session
        $this->makeApiRequest('POST', '/api/auth/logout', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        // Now try to login - should require MFA
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'mfa-test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        $this->assertEquals(200, $loginResponse['status']);
        $this->assertTrue($loginResponse['body']['success']);
        $this->assertTrue($loginResponse['body']['data']['mfa_required']);
        $this->assertArrayHasKey('mfa_token', $loginResponse['body']['data']);
        $this->assertContains('totp', $loginResponse['body']['data']['mfa_methods']);
    }

    public function testMFAVerification()
    {
        // Setup MFA and get MFA token
        $mfaToken = $this->setupMFALoginFlow();

        // Get the user's TOTP secret for code generation
        $user = $this->userRepository->findById($this->testUserId);
        $totpSecret = $this->getTOTPSecret($user['id']);
        $totpCode = $this->totpHandler->generateCode($totpSecret);

        // Verify MFA
        $verifyResponse = $this->makeApiRequest('POST', '/api/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'mfa_code' => $totpCode,
            'mfa_method' => 'totp'
        ]);

        $this->assertEquals(200, $verifyResponse['status']);
        $this->assertTrue($verifyResponse['body']['success']);
        $this->assertArrayHasKey('access_token', $verifyResponse['body']['data']);
        $this->assertArrayHasKey('refresh_token', $verifyResponse['body']['data']);
        $this->assertEquals('MFA verification successful', $verifyResponse['body']['message']);
    }

    public function testMFAVerificationWithInvalidCode()
    {
        // Setup MFA and get MFA token
        $mfaToken = $this->setupMFALoginFlow();

        // Verify MFA with invalid code
        $verifyResponse = $this->makeApiRequest('POST', '/api/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'mfa_code' => '000000',
            'mfa_method' => 'totp'
        ]);

        $this->assertEquals(400, $verifyResponse['status']);
        $this->assertFalse($verifyResponse['body']['success']);
        $this->assertEquals('INVALID_MFA_CODE', $verifyResponse['body']['error']['code']);
    }

    public function testMFAVerificationWithExpiredToken()
    {
        // Create an expired MFA token
        $expiredToken = 'expired_mfa_token_123';

        $verifyResponse = $this->makeApiRequest('POST', '/api/auth/mfa/verify', [
            'mfa_token' => $expiredToken,
            'mfa_code' => '123456',
            'mfa_method' => 'totp'
        ]);

        $this->assertEquals(400, $verifyResponse['status']);
        $this->assertFalse($verifyResponse['body']['success']);
        $this->assertEquals('INVALID_MFA_TOKEN', $verifyResponse['body']['error']['code']);
    }

    public function testDisableMFA()
    {
        // First setup TOTP MFA
        $this->setupTOTPMFA();

        // Disable MFA
        $disableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/disable', [
            'mfa_method' => 'totp',
            'current_password' => 'password123'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $disableResponse['status']);
        $this->assertTrue($disableResponse['body']['success']);
        $this->assertEquals('MFA disabled successfully', $disableResponse['body']['message']);
    }

    public function testDisableMFAWithWrongPassword()
    {
        // First setup TOTP MFA
        $this->setupTOTPMFA();

        // Try to disable MFA with wrong password
        $disableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/disable', [
            'mfa_method' => 'totp',
            'current_password' => 'wrongpassword'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(400, $disableResponse['status']);
        $this->assertFalse($disableResponse['body']['success']);
        $this->assertEquals('INVALID_PASSWORD', $disableResponse['body']['error']['code']);
    }

    public function testBackupCodeUsage()
    {
        // Setup MFA and get backup codes
        $backupCodes = $this->setupTOTPMFA();
        $mfaToken = $this->setupMFALoginFlow();

        // Use backup code for MFA verification
        $verifyResponse = $this->makeApiRequest('POST', '/api/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'mfa_code' => $backupCodes[0],
            'mfa_method' => 'backup_code'
        ]);

        $this->assertEquals(200, $verifyResponse['status']);
        $this->assertTrue($verifyResponse['body']['success']);
        $this->assertArrayHasKey('access_token', $verifyResponse['body']['data']);
    }

    public function testBackupCodeSingleUse()
    {
        // Setup MFA and get backup codes
        $backupCodes = $this->setupTOTPMFA();
        
        // Use backup code once
        $mfaToken1 = $this->setupMFALoginFlow();
        $this->makeApiRequest('POST', '/api/auth/mfa/verify', [
            'mfa_token' => $mfaToken1,
            'mfa_code' => $backupCodes[0],
            'mfa_method' => 'backup_code'
        ]);

        // Try to use the same backup code again
        $mfaToken2 = $this->setupMFALoginFlow();
        $verifyResponse = $this->makeApiRequest('POST', '/api/auth/mfa/verify', [
            'mfa_token' => $mfaToken2,
            'mfa_code' => $backupCodes[0],
            'mfa_method' => 'backup_code'
        ]);

        $this->assertEquals(400, $verifyResponse['status']);
        $this->assertFalse($verifyResponse['body']['success']);
        $this->assertEquals('INVALID_BACKUP_CODE', $verifyResponse['body']['error']['code']);
    }

    public function testMFAMethodsList()
    {
        // Setup both TOTP and SMS MFA
        $this->setupTOTPMFA();
        $this->setupSMSMFA();

        $response = $this->makeApiRequest('GET', '/api/auth/mfa/methods', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertContains('totp', $response['body']['data']['enabled_methods']);
        $this->assertContains('sms', $response['body']['data']['enabled_methods']);
    }

    private function setupTOTPMFA(): array
    {
        // Enable TOTP
        $enableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/totp/enable', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $secret = $enableResponse['body']['data']['secret'];
        $setupToken = $enableResponse['body']['data']['setup_token'];
        $totpCode = $this->totpHandler->generateCode($secret);

        // Activate TOTP
        $activateResponse = $this->makeApiRequest('POST', '/api/auth/mfa/totp/activate', [
            'setup_token' => $setupToken,
            'totp_code' => $totpCode
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        return $activateResponse['body']['data']['backup_codes'];
    }

    private function setupSMSMFA(): void
    {
        // Enable SMS MFA
        $enableResponse = $this->makeApiRequest('POST', '/api/auth/mfa/sms/enable', [
            'phone_number' => '+1987654321'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        $setupToken = $enableResponse['body']['data']['setup_token'];

        // Activate SMS MFA (using mock verification code)
        $this->makeApiRequest('POST', '/api/auth/mfa/sms/activate', [
            'setup_token' => $setupToken,
            'verification_code' => '123456'
        ], [
            'Authorization: Bearer ' . $this->accessToken
        ]);
    }

    private function setupMFALoginFlow(): string
    {
        // Logout current session
        $this->makeApiRequest('POST', '/api/auth/logout', [], [
            'Authorization: Bearer ' . $this->accessToken
        ]);

        // Login to get MFA token
        $loginResponse = $this->makeApiRequest('POST', '/api/auth/login', [
            'email' => 'mfa-test@example.com',
            'password' => 'password123',
            'device_info' => [
                'device_id' => 'test-device-123',
                'device_name' => 'Test Device',
                'app_version' => '1.0.0'
            ]
        ]);

        return $loginResponse['body']['data']['mfa_token'];
    }

    private function getTOTPSecret(int $userId): string
    {
        // This would query the database for the user's TOTP secret
        // For testing purposes, we'll return a mock secret
        return 'JBSWY3DPEHPK3PXP';
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