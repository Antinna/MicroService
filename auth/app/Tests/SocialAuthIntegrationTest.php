<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Controllers\SocialAuthController;
use Antinna\Auth\Routes\SocialRoutes;
use PHPUnit\Framework\TestCase;

class SocialAuthIntegrationTest extends TestCase
{
    private SocialAuthController $controller;
    private SocialRoutes $routes;

    protected function setUp(): void
    {
        $this->controller = new SocialAuthController();
        $this->routes = new SocialRoutes();
    }

    public function testSocialAuthControllerInstantiation()
    {
        $this->assertInstanceOf(SocialAuthController::class, $this->controller);
    }

    public function testSocialRoutesInstantiation()
    {
        $this->assertInstanceOf(SocialRoutes::class, $this->routes);
    }

    public function testGetSupportedProvidersRoute()
    {
        $routes = SocialRoutes::getRoutes();
        
        $this->assertIsArray($routes);
        $this->assertArrayHasKey('GET /auth/social/providers', $routes);
        $this->assertArrayHasKey('GET /auth/social/statistics', $routes);
        $this->assertArrayHasKey('GET /auth/social/accounts', $routes);
        $this->assertArrayHasKey('POST /auth/social/link', $routes);
    }

    public function testGetSupportedProviders()
    {
        $providers = SocialRoutes::getSupportedProviders();
        
        $this->assertIsArray($providers);
        $this->assertContains('google', $providers);
        $this->assertContains('facebook', $providers);
        $this->assertContains('apple', $providers);
        $this->assertContains('github', $providers);
        $this->assertContains('amazon', $providers);
        $this->assertContains('twitter', $providers);
        $this->assertContains('discord', $providers);
        $this->assertContains('microsoft', $providers);
    }

    public function testRouteMatching()
    {
        // Test static route matching
        $this->assertTrue($this->routes->handleRequest('/auth/social/providers', 'GET'));
        
        // Test dynamic provider route matching
        $this->assertTrue($this->routes->handleRequest('/auth/social/google', 'GET'));
        $this->assertTrue($this->routes->handleRequest('/auth/social/facebook/callback', 'POST'));
        $this->assertTrue($this->routes->handleRequest('/auth/social/github/unlink', 'DELETE'));
        
        // Test non-matching routes
        $this->assertFalse($this->routes->handleRequest('/auth/social/invalid', 'POST'));
        $this->assertFalse($this->routes->handleRequest('/auth/other/route', 'GET'));
    }

    public function testControllerMethodsExist()
    {
        $this->assertTrue(method_exists($this->controller, 'initiateAuth'));
        $this->assertTrue(method_exists($this->controller, 'handleCallback'));
        $this->assertTrue(method_exists($this->controller, 'linkAccount'));
        $this->assertTrue(method_exists($this->controller, 'unlinkAccount'));
        $this->assertTrue(method_exists($this->controller, 'getUserSocialAccounts'));
        $this->assertTrue(method_exists($this->controller, 'getSupportedProviders'));
        $this->assertTrue(method_exists($this->controller, 'getStatistics'));
    }

    public function testPrivateMethodsExist()
    {
        $reflection = new \ReflectionClass($this->controller);
        
        $this->assertTrue($reflection->hasMethod('sendJsonResponse'));
        $this->assertTrue($reflection->hasMethod('getRequestData'));
        $this->assertTrue($reflection->hasMethod('getAuthenticatedUserId'));
        
        // Check that private methods are actually private
        $sendJsonMethod = $reflection->getMethod('sendJsonResponse');
        $this->assertTrue($sendJsonMethod->isPrivate());
        
        $getRequestDataMethod = $reflection->getMethod('getRequestData');
        $this->assertTrue($getRequestDataMethod->isPrivate());
        
        $getAuthUserIdMethod = $reflection->getMethod('getAuthenticatedUserId');
        $this->assertTrue($getAuthUserIdMethod->isPrivate());
    }

    public function testGetRequestDataMethod()
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('getRequestData');
        $method->setAccessible(true);

        // Mock $_GET and $_POST
        $_GET = ['param1' => 'value1'];
        $_POST = ['param2' => 'value2'];

        $result = $method->invoke($this->controller);
        
        $this->assertIsArray($result);
        $this->assertEquals('value1', $result['param1']);
        $this->assertEquals('value2', $result['param2']);

        // Clean up
        $_GET = [];
        $_POST = [];
    }

    public function testGetAuthenticatedUserIdPlaceholder()
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('getAuthenticatedUserId');
        $method->setAccessible(true);

        // Test without Authorization header
        $result = $method->invoke($this->controller);
        $this->assertNull($result);

        // Test with Authorization header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test_token';
        $result = $method->invoke($this->controller);
        $this->assertEquals(1, $result); // Placeholder implementation returns 1

        // Clean up
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testRoutePatternMatching()
    {
        // Test provider route pattern
        $this->assertEquals(1, preg_match('#^/auth/social/([a-zA-Z]+)$#', '/auth/social/google'));
        $this->assertEquals(1, preg_match('#^/auth/social/([a-zA-Z]+)$#', '/auth/social/facebook'));
        $this->assertEquals(0, preg_match('#^/auth/social/([a-zA-Z]+)$#', '/auth/social/123'));
        $this->assertEquals(0, preg_match('#^/auth/social/([a-zA-Z]+)$#', '/auth/social/'));

        // Test callback route pattern
        $this->assertEquals(1, preg_match('#^/auth/social/([a-zA-Z]+)/callback$#', '/auth/social/google/callback'));
        $this->assertEquals(1, preg_match('#^/auth/social/([a-zA-Z]+)/callback$#', '/auth/social/github/callback'));
        $this->assertEquals(0, preg_match('#^/auth/social/([a-zA-Z]+)/callback$#', '/auth/social/google/other'));

        // Test unlink route pattern
        $this->assertEquals(1, preg_match('#^/auth/social/([a-zA-Z]+)/unlink$#', '/auth/social/facebook/unlink'));
        $this->assertEquals(0, preg_match('#^/auth/social/([a-zA-Z]+)/unlink$#', '/auth/social/facebook/link'));
    }

    protected function tearDown(): void
    {
        // Clean up any global state
        $_GET = [];
        $_POST = [];
        $_SERVER = array_filter($_SERVER, function($key) {
            return !str_starts_with($key, 'HTTP_');
        }, ARRAY_FILTER_USE_KEY);
    }
}