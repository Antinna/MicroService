<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\OAuth2Handler;
use PHPUnit\Framework\TestCase;

class OAuth2HandlerTest extends TestCase
{
    private OAuth2Handler $oauth2Handler;

    protected function setUp(): void
    {
        $this->oauth2Handler = new OAuth2Handler();
    }

    public function testOAuth2HandlerInstantiation()
    {
        $this->assertInstanceOf(OAuth2Handler::class, $this->oauth2Handler);
    }

    public function testGetSupportedProviders()
    {
        $providers = $this->oauth2Handler->getSupportedProviders();

        $this->assertIsArray($providers);
        $this->assertContains('google', $providers);
        $this->assertContains('facebook', $providers);
        $this->assertContains('apple', $providers);
        $this->assertContains('github', $providers);
        $this->assertContains('amazon', $providers);
        $this->assertContains('twitter', $providers);
        $this->assertContains('discord', $providers);
        $this->assertContains('microsoft', $providers);
        $this->assertCount(8, $providers);
    }

    public function testGetAuthorizationUrlWithUnsupportedProvider()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported OAuth2 provider: invalid_provider');
        
        $this->oauth2Handler->getAuthorizationUrl('invalid_provider');
    }

    public function testGetAuthorizationUrlStructure()
    {
        // This test will fail without proper configuration, but we can test the structure
        try {
            $url = $this->oauth2Handler->getAuthorizationUrl('google');
            $this->assertIsString($url);
            $this->assertStringContains('accounts.google.com', $url);
            $this->assertStringContains('client_id=', $url);
            $this->assertStringContains('redirect_uri=', $url);
            $this->assertStringContains('scope=', $url);
            $this->assertStringContains('response_type=code', $url);
            $this->assertStringContains('state=', $url);
        } catch (\Exception $e) {
            // Expected if client ID not configured
            $this->assertStringContains('Client ID not configured', $e->getMessage());
        }
    }

    public function testHandleCallbackWithUnsupportedProvider()
    {
        $result = $this->oauth2Handler->handleCallback('invalid_provider', 'test_code');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('UNSUPPORTED_PROVIDER', $result['code']);
        $this->assertStringContains('Unsupported OAuth2 provider', $result['error']);
    }

    public function testGetUserProfileWithUnsupportedProvider()
    {
        $result = $this->oauth2Handler->getUserProfile('invalid_provider', 'test_token');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContains('Unsupported OAuth2 provider', $result['error']);
    }

    public function testValidateState()
    {
        $reflection = new \ReflectionClass($this->oauth2Handler);
        $generateMethod = $reflection->getMethod('generateState');
        $generateMethod->setAccessible(true);

        // Generate a valid state
        $state = $generateMethod->invoke($this->oauth2Handler, 'google');
        
        // Test valid state
        $this->assertTrue($this->oauth2Handler->validateState($state, 'google'));
        
        // Test invalid provider
        $this->assertFalse($this->oauth2Handler->validateState($state, 'facebook'));
        
        // Test invalid state format
        $this->assertFalse($this->oauth2Handler->validateState('invalid_state', 'google'));
        
        // Test empty state
        $this->assertFalse($this->oauth2Handler->validateState('', 'google'));
    }

    public function testNormalizeUserProfile()
    {
        $reflection = new \ReflectionClass($this->oauth2Handler);
        $method = $reflection->getMethod('normalizeUserProfile');
        $method->setAccessible(true);

        // Test Google profile normalization
        $googleData = [
            'id' => '123456789',
            'email' => 'test@gmail.com',
            'name' => 'Test User',
            'given_name' => 'Test',
            'family_name' => 'User',
            'picture' => 'https://example.com/avatar.jpg',
            'verified_email' => true
        ];

        $normalized = $method->invoke($this->oauth2Handler, 'google', $googleData);

        $this->assertEquals('google', $normalized['provider']);
        $this->assertEquals('123456789', $normalized['provider_id']);
        $this->assertEquals('test@gmail.com', $normalized['email']);
        $this->assertEquals('Test User', $normalized['name']);
        $this->assertEquals('Test', $normalized['first_name']);
        $this->assertEquals('User', $normalized['last_name']);
        $this->assertEquals('https://example.com/avatar.jpg', $normalized['avatar_url']);
        $this->assertTrue($normalized['verified']);

        // Test Facebook profile normalization
        $facebookData = [
            'id' => '987654321',
            'email' => 'test@facebook.com',
            'name' => 'Facebook User',
            'first_name' => 'Facebook',
            'last_name' => 'User'
        ];

        $normalized = $method->invoke($this->oauth2Handler, 'facebook', $facebookData);

        $this->assertEquals('facebook', $normalized['provider']);
        $this->assertEquals('987654321', $normalized['provider_id']);
        $this->assertEquals('test@facebook.com', $normalized['email']);
        $this->assertEquals('Facebook User', $normalized['name']);
        $this->assertStringContains('graph.facebook.com', $normalized['avatar_url']);
    }

    public function testIsProviderSupported()
    {
        $reflection = new \ReflectionClass($this->oauth2Handler);
        $method = $reflection->getMethod('isProviderSupported');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->oauth2Handler, 'google'));
        $this->assertTrue($method->invoke($this->oauth2Handler, 'facebook'));
        $this->assertTrue($method->invoke($this->oauth2Handler, 'github'));
        $this->assertFalse($method->invoke($this->oauth2Handler, 'invalid_provider'));
        $this->assertFalse($method->invoke($this->oauth2Handler, ''));
    }

    public function testGetClientIdAndSecret()
    {
        $reflection = new \ReflectionClass($this->oauth2Handler);
        $getClientIdMethod = $reflection->getMethod('getClientId');
        $getClientIdMethod->setAccessible(true);
        $getClientSecretMethod = $reflection->getMethod('getClientSecret');
        $getClientSecretMethod->setAccessible(true);

        // Test that methods return strings (empty if not configured)
        $clientId = $getClientIdMethod->invoke($this->oauth2Handler, 'google');
        $clientSecret = $getClientSecretMethod->invoke($this->oauth2Handler, 'google');

        $this->assertIsString($clientId);
        $this->assertIsString($clientSecret);

        // Test invalid provider
        $invalidClientId = $getClientIdMethod->invoke($this->oauth2Handler, 'invalid_provider');
        $this->assertEquals('', $invalidClientId);
    }

    public function testGetRedirectUri()
    {
        $reflection = new \ReflectionClass($this->oauth2Handler);
        $method = $reflection->getMethod('getRedirectUri');
        $method->setAccessible(true);

        $redirectUri = $method->invoke($this->oauth2Handler, 'google');
        
        $this->assertIsString($redirectUri);
        $this->assertStringContains('/auth/social/google/callback', $redirectUri);
        $this->assertStringStartsWith('http', $redirectUri);
    }

    public function testRefreshTokenWithUnsupportedProvider()
    {
        $result = $this->oauth2Handler->refreshToken('invalid_provider', 'refresh_token');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContains('Unsupported OAuth2 provider', $result['error']);
    }

    public function testLinkAndUnlinkAccount()
    {
        // These methods are placeholders and should return false
        $linkResult = $this->oauth2Handler->linkAccount(1, 'google', []);
        $unlinkResult = $this->oauth2Handler->unlinkAccount(1, 'google');

        $this->assertFalse($linkResult);
        $this->assertFalse($unlinkResult);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}