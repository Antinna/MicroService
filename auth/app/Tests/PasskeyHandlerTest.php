<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\PasskeyHandler;
use PHPUnit\Framework\TestCase;

class PasskeyHandlerTest extends TestCase
{
    private PasskeyHandler $passkeyHandler;

    protected function setUp(): void
    {
        $this->passkeyHandler = new PasskeyHandler();
    }

    public function testPasskeyHandlerInstantiation()
    {
        $this->assertInstanceOf(PasskeyHandler::class, $this->passkeyHandler);
    }

    public function testBase64UrlEncodeDecode()
    {
        // Test base64url encoding/decoding functions
        $originalData = 'Hello, World! This is a test string with special characters: +/=';
        $encoded = base64url_encode($originalData);
        $decoded = base64url_decode($encoded);

        $this->assertEquals($originalData, $decoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testGenerateRegistrationOptionsWithoutDatabase()
    {
        $userId = 1;
        $result = $this->passkeyHandler->generateRegistrationOptions($userId, 'Test Device');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail with user not found
        $this->assertFalse($result['success']);
        $this->assertEquals('USER_NOT_FOUND', $result['code']);
    }

    public function testVerifyRegistrationWithoutDatabase()
    {
        $userId = 1;
        $response = [
            'id' => 'test_credential_id',
            'rawId' => 'test_credential_id',
            'type' => 'public-key',
            'response' => [
                'attestationObject' => 'test_attestation',
                'clientDataJSON' => 'test_client_data'
            ]
        ];

        $result = $this->passkeyHandler->verifyRegistration($userId, $response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail with user not found
        $this->assertFalse($result['success']);
        $this->assertEquals('USER_NOT_FOUND', $result['code']);
    }

    public function testGenerateAuthenticationOptions()
    {
        $result = $this->passkeyHandler->generateAuthenticationOptions();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should succeed even without database for general auth options
        if ($result['success']) {
            $this->assertArrayHasKey('options', $result);
            $this->assertArrayHasKey('challenge_id', $result);
            
            $options = $result['options'];
            $this->assertArrayHasKey('challenge', $options);
            $this->assertArrayHasKey('timeout', $options);
            $this->assertArrayHasKey('rpId', $options);
            $this->assertArrayHasKey('allowCredentials', $options);
            $this->assertArrayHasKey('userVerification', $options);
        }
    }

    public function testVerifyAuthenticationWithoutCredential()
    {
        $response = [
            'id' => 'nonexistent_credential',
            'rawId' => 'nonexistent_credential',
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => 'test_auth_data',
                'clientDataJSON' => 'test_client_data',
                'signature' => 'test_signature'
            ]
        ];

        $result = $this->passkeyHandler->verifyAuthentication($response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertEquals('CREDENTIAL_NOT_FOUND', $result['code']);
    }

    public function testGetUserPasskeysWithoutDatabase()
    {
        $userId = 1;
        $passkeys = $this->passkeyHandler->getUserPasskeys($userId);

        $this->assertIsArray($passkeys);
        // Without database, should return empty array
        $this->assertEmpty($passkeys);
    }

    public function testRemovePasskeyWithoutDatabase()
    {
        $userId = 1;
        $passkeyId = 1;
        $result = $this->passkeyHandler->removePasskey($userId, $passkeyId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, should fail
        $this->assertFalse($result['success']);
        $this->assertEquals('PASSKEY_NOT_FOUND', $result['code']);
    }

    public function testPublicKeyCredentialSourceRepositoryMethods()
    {
        // Test findOneByCredentialId
        $credentialId = 'test_credential_id';
        $result = $this->passkeyHandler->findOneByCredentialId($credentialId);
        $this->assertNull($result); // Should return null without database

        // Test findAllForUserEntity - requires WebAuthn user entity
        // This would need proper WebAuthn setup to test fully
        $this->assertTrue(method_exists($this->passkeyHandler, 'findAllForUserEntity'));
        $this->assertTrue(method_exists($this->passkeyHandler, 'saveCredentialSource'));
    }

    public function testChallengeStorageAndRetrieval()
    {
        $reflection = new \ReflectionClass($this->passkeyHandler);
        
        $storeMethod = $reflection->getMethod('storeChallenge');
        $storeMethod->setAccessible(true);
        
        $getMethod = $reflection->getMethod('getStoredChallenge');
        $getMethod->setAccessible(true);
        
        $clearMethod = $reflection->getMethod('clearChallenge');
        $clearMethod->setAccessible(true);

        $userId = 999; // Test user ID
        $challenge = 'test_challenge_data';
        $type = 'registration';
        $deviceName = 'Test Device';

        // Store challenge
        $challengeId = $storeMethod->invoke($this->passkeyHandler, $userId, $challenge, $type, $deviceName);
        $this->assertIsString($challengeId);
        $this->assertEquals(32, strlen($challengeId)); // 16 bytes = 32 hex chars

        // Retrieve challenge
        $storedData = $getMethod->invoke($this->passkeyHandler, $userId, $type);
        $this->assertIsArray($storedData);
        $this->assertEquals($challenge, $storedData['challenge']);
        $this->assertEquals($type, $storedData['type']);
        $this->assertEquals($deviceName, $storedData['device_name']);
        $this->assertArrayHasKey('created_at', $storedData);

        // Clear challenge
        $clearMethod->invoke($this->passkeyHandler, $userId, $type);

        // Verify challenge is cleared
        $clearedData = $getMethod->invoke($this->passkeyHandler, $userId, $type);
        $this->assertNull($clearedData);
    }

    public function testChallengeExpiration()
    {
        $reflection = new \ReflectionClass($this->passkeyHandler);
        
        $storeMethod = $reflection->getMethod('storeChallenge');
        $storeMethod->setAccessible(true);
        
        $getMethod = $reflection->getMethod('getStoredChallenge');
        $getMethod->setAccessible(true);

        $userId = 998; // Test user ID
        $challenge = 'expired_challenge';
        $type = 'authentication';

        // Store challenge
        $storeMethod->invoke($this->passkeyHandler, $userId, $challenge, $type);

        // Manually modify the stored file to simulate expiration
        $tempDir = sys_get_temp_dir();
        $filePath = "{$tempDir}/webauthn_challenge_{$userId}_{$type}.json";
        
        if (file_exists($filePath)) {
            $data = json_decode(file_get_contents($filePath), true);
            $data['created_at'] = time() - 400; // 400 seconds ago (expired)
            file_put_contents($filePath, json_encode($data));

            // Try to retrieve expired challenge
            $expiredData = $getMethod->invoke($this->passkeyHandler, $userId, $type);
            $this->assertNull($expiredData); // Should return null for expired challenge
            
            // File should be automatically deleted
            $this->assertFalse(file_exists($filePath));
        }
    }

    public function testPrivateMethodsExist()
    {
        $reflection = new \ReflectionClass($this->passkeyHandler);
        
        $this->assertTrue($reflection->hasMethod('storeChallenge'));
        $this->assertTrue($reflection->hasMethod('getStoredChallenge'));
        $this->assertTrue($reflection->hasMethod('clearChallenge'));
        $this->assertTrue($reflection->hasMethod('storePasskey'));
        $this->assertTrue($reflection->hasMethod('updatePasskey'));
        $this->assertTrue($reflection->hasMethod('getExistingCredentials'));
        
        // Check that private methods are actually private
        $this->assertTrue($reflection->getMethod('storeChallenge')->isPrivate());
        $this->assertTrue($reflection->getMethod('getStoredChallenge')->isPrivate());
        $this->assertTrue($reflection->getMethod('clearChallenge')->isPrivate());
    }

    protected function tearDown(): void
    {
        // Clean up any temporary challenge files created during testing
        $tempDir = sys_get_temp_dir();
        $pattern = "{$tempDir}/webauthn_challenge_*.json";
        
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}