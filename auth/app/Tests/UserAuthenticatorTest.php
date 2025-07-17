<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\UserAuthenticator;
use PHPUnit\Framework\TestCase;

class UserAuthenticatorTest extends TestCase
{
    private UserAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = new UserAuthenticator();
    }

    public function testAuthenticatorInstantiation()
    {
        $this->assertInstanceOf(UserAuthenticator::class, $this->authenticator);
    }

    public function testGetMethodName()
    {
        $this->assertEquals('password', $this->authenticator->getMethodName());
    }

    public function testValidateCredentials()
    {
        // Test valid credentials format
        $validCredentials = [
            'email' => 'test@example.com',
            'password' => 'password123'
        ];
        $this->assertTrue($this->authenticator->validate($validCredentials));

        // Test invalid email
        $invalidEmail = [
            'email' => 'invalid-email',
            'password' => 'password123'
        ];
        $this->assertFalse($this->authenticator->validate($invalidEmail));

        // Test missing email
        $missingEmail = [
            'password' => 'password123'
        ];
        $this->assertFalse($this->authenticator->validate($missingEmail));

        // Test missing password
        $missingPassword = [
            'email' => 'test@example.com'
        ];
        $this->assertFalse($this->authenticator->validate($missingPassword));
    }

    public function testPasswordValidation()
    {
        $reflection = new \ReflectionClass($this->authenticator);
        $method = $reflection->getMethod('validatePassword');
        $method->setAccessible(true);

        // Test strong password
        $strongPassword = 'StrongP@ssw0rd123';
        $result = $method->invoke($this->authenticator, $strongPassword);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        // Test weak password (too short)
        $weakPassword = '123';
        $result = $method->invoke($this->authenticator, $weakPassword);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);

        // Test password without uppercase
        $noUppercase = 'password123!';
        $result = $method->invoke($this->authenticator, $noUppercase);
        $this->assertFalse($result['valid']);
        $this->assertContains('Password must contain at least one uppercase letter', $result['errors']);

        // Test password without lowercase
        $noLowercase = 'PASSWORD123!';
        $result = $method->invoke($this->authenticator, $noLowercase);
        $this->assertFalse($result['valid']);
        $this->assertContains('Password must contain at least one lowercase letter', $result['errors']);

        // Test password without numbers
        $noNumbers = 'Password!';
        $result = $method->invoke($this->authenticator, $noNumbers);
        $this->assertFalse($result['valid']);
        $this->assertContains('Password must contain at least one number', $result['errors']);

        // Test password without special characters
        $noSpecial = 'Password123';
        $result = $method->invoke($this->authenticator, $noSpecial);
        $this->assertFalse($result['valid']);
        $this->assertContains('Password must contain at least one special character', $result['errors']);
    }

    public function testRegistrationValidation()
    {
        $reflection = new \ReflectionClass($this->authenticator);
        $method = $reflection->getMethod('validateRegistration');
        $method->setAccessible(true);

        // Test valid registration data
        $validData = [
            'email' => 'test@example.com',
            'password' => 'StrongP@ssw0rd123',
            'role' => 'customer'
        ];
        $result = $method->invoke($this->authenticator, $validData);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        // Test invalid email
        $invalidEmailData = [
            'email' => 'invalid-email',
            'password' => 'StrongP@ssw0rd123'
        ];
        $result = $method->invoke($this->authenticator, $invalidEmailData);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('email', $result['errors']);

        // Test missing email
        $missingEmailData = [
            'password' => 'StrongP@ssw0rd123'
        ];
        $result = $method->invoke($this->authenticator, $missingEmailData);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('email', $result['errors']);

        // Test invalid role
        $invalidRoleData = [
            'email' => 'test@example.com',
            'password' => 'StrongP@ssw0rd123',
            'role' => 'invalid_role'
        ];
        $result = $method->invoke($this->authenticator, $invalidRoleData);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('role', $result['errors']);
    }

    public function testPasswordHashing()
    {
        $reflection = new \ReflectionClass($this->authenticator);
        $hashMethod = $reflection->getMethod('hashPassword');
        $hashMethod->setAccessible(true);
        $verifyMethod = $reflection->getMethod('verifyPassword');
        $verifyMethod->setAccessible(true);

        $password = 'TestPassword123!';
        $hash = $hashMethod->invoke($this->authenticator, $password);

        // Test that hash is generated
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
        $this->assertNotEquals($password, $hash);

        // Test password verification
        $this->assertTrue($verifyMethod->invoke($this->authenticator, $password, $hash));
        $this->assertFalse($verifyMethod->invoke($this->authenticator, 'WrongPassword', $hash));
    }

    public function testAuthenticationWithoutDatabase()
    {
        // Test authentication method structure without database
        $credentials = [
            'email' => 'test@example.com',
            'password' => 'password123'
        ];

        $result = $this->authenticator->authenticate($credentials);
        
        // Should return an array with expected structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Without database, this should fail
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}