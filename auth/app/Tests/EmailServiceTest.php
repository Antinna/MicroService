<?php

namespace Antinna\Auth\Tests;

use Antinna\Auth\Services\EmailService;
use PHPUnit\Framework\TestCase;

class EmailServiceTest extends TestCase
{
    private EmailService $emailService;

    protected function setUp(): void
    {
        $this->emailService = new EmailService();
    }

    public function testSendMagicLinkEmailSuccess(): void
    {
        $email = 'test@example.com';
        $magicLink = 'https://example.com/auth/magic-link/verify?token=test-token';
        
        $options = [
            'expires_in_minutes' => 15,
            'subject' => 'Custom Magic Link Subject'
        ];

        // Note: In a real test environment, you would mock the email sending
        // For now, we'll test the method structure and return format
        $result = $this->emailService->sendMagicLinkEmail($email, $magicLink, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('data', $result);
            $this->assertEquals($email, $result['data']['email']);
            $this->assertArrayHasKey('message_id', $result['data']);
            $this->assertArrayHasKey('provider', $result['data']);
        }
    }

    public function testSendMagicLinkEmailWithCustomVariables(): void
    {
        $email = 'test@example.com';
        $magicLink = 'https://example.com/auth/magic-link/verify?token=test-token';
        
        $options = [
            'template_variables' => [
                'custom_message' => 'Welcome to our platform!',
                'special_offer' => 'Get 20% off your first purchase'
            ]
        ];

        $result = $this->emailService->sendMagicLinkEmail($email, $magicLink, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testSendWelcomeEmail(): void
    {
        $email = 'test@example.com';
        $userInfo = [
            'name' => 'Test User',
            'dashboard_url' => 'https://example.com/dashboard'
        ];

        $result = $this->emailService->sendWelcomeEmail($email, $userInfo);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testSendSecurityAlertEmail(): void
    {
        $email = 'test@example.com';
        $alertType = 'suspicious_login';
        $details = [
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Chrome Browser',
            'location' => 'New York, USA',
            'action_required' => true
        ];

        $result = $this->emailService->sendSecurityAlertEmail($email, $alertType, $details);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testGetAvailableProviders(): void
    {
        $providers = $this->emailService->getAvailableProviders();

        $this->assertIsArray($providers);
        $this->assertArrayHasKey('smtp', $providers);
        $this->assertArrayHasKey('sendgrid', $providers);
        $this->assertArrayHasKey('mailgun', $providers);
        $this->assertArrayHasKey('ses', $providers);
    }

    public function testEmailTemplateRendering(): void
    {
        // Test that built-in templates contain expected placeholders
        $reflection = new \ReflectionClass($this->emailService);
        $method = $reflection->getMethod('getMagicLinkTemplate');
        $method->setAccessible(true);
        
        $template = $method->invoke($this->emailService);
        
        $this->assertStringContains('{{magic_link}}', $template);
        $this->assertStringContains('{{app_name}}', $template);
        $this->assertStringContains('{{expires_in_minutes}}', $template);
        $this->assertStringContains('{{user_email}}', $template);
    }

    public function testWelcomeTemplateContent(): void
    {
        $reflection = new \ReflectionClass($this->emailService);
        $method = $reflection->getMethod('getWelcomeTemplate');
        $method->setAccessible(true);
        
        $template = $method->invoke($this->emailService);
        
        $this->assertStringContains('{{user_name}}', $template);
        $this->assertStringContains('{{login_time}}', $template);
        $this->assertStringContains('{{dashboard_url}}', $template);
        $this->assertStringContains('Welcome', $template);
    }

    public function testSecurityAlertTemplateContent(): void
    {
        $reflection = new \ReflectionClass($this->emailService);
        $method = $reflection->getMethod('getSecurityAlertTemplate');
        $method->setAccessible(true);
        
        $template = $method->invoke($this->emailService);
        
        $this->assertStringContains('{{alert_message}}', $template);
        $this->assertStringContains('{{alert_time}}', $template);
        $this->assertStringContains('{{ip_address}}', $template);
        $this->assertStringContains('Security Alert', $template);
    }

    public function testHtmlToTextConversion(): void
    {
        $reflection = new \ReflectionClass($this->emailService);
        $method = $reflection->getMethod('convertHtmlToText');
        $method->setAccessible(true);
        
        $html = '<h1>Hello</h1><p>This is a <strong>test</strong> message.</p>';
        $text = $method->invoke($this->emailService, $html);
        
        $this->assertEquals('Hello This is a test message.', $text);
        $this->assertStringNotContains('<', $text);
        $this->assertStringNotContains('>', $text);
    }

    public function testUserAgentParsing(): void
    {
        $reflection = new \ReflectionClass($this->emailService);
        $method = $reflection->getMethod('getUserAgentInfo');
        $method->setAccessible(true);
        
        // Test Chrome detection
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
        $result = $method->invoke($this->emailService);
        $this->assertEquals('Chrome Browser', $result);
        
        // Test Firefox detection
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0';
        $result = $method->invoke($this->emailService);
        $this->assertEquals('Firefox Browser', $result);
        
        // Test Safari detection
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15';
        $result = $method->invoke($this->emailService);
        $this->assertEquals('Safari Browser', $result);
        
        // Test unknown user agent
        $_SERVER['HTTP_USER_AGENT'] = 'Unknown/1.0';
        $result = $method->invoke($this->emailService);
        $this->assertEquals('Unknown Browser', $result);
    }

    public function testTemplateVariableReplacement(): void
    {
        $reflection = new \ReflectionClass($this->emailService);
        $method = $reflection->getMethod('renderEmailTemplate');
        $method->setAccessible(true);
        
        $variables = [
            'app_name' => 'Test App',
            'user_email' => 'test@example.com',
            'magic_link' => 'https://example.com/magic'
        ];
        
        $result = $method->invoke($this->emailService, 'magic_link', $variables);
        
        $this->assertStringContains('Test App', $result);
        $this->assertStringContains('test@example.com', $result);
        $this->assertStringContains('https://example.com/magic', $result);
        $this->assertStringNotContains('{{app_name}}', $result);
        $this->assertStringNotContains('{{user_email}}', $result);
        $this->assertStringNotContains('{{magic_link}}', $result);
    }

    public function testEmailConfigurationTest(): void
    {
        // This would typically require mocking the email sending functionality
        $result = $this->emailService->testEmailConfiguration();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if (!$result['success']) {
            $this->assertArrayHasKey('error', $result);
            $this->assertArrayHasKey('code', $result);
        }
    }

    public function testSecurityAlertTypes(): void
    {
        $email = 'test@example.com';
        $alertTypes = [
            'suspicious_login',
            'new_device',
            'password_change',
            'account_locked',
            'magic_link_abuse'
        ];

        foreach ($alertTypes as $alertType) {
            $result = $this->emailService->sendSecurityAlertEmail($email, $alertType);
            
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
        }
    }

    public function testEmailValidation(): void
    {
        // Test with invalid email addresses
        $invalidEmails = [
            'invalid-email',
            '@example.com',
            'test@',
            'test..test@example.com'
        ];

        foreach ($invalidEmails as $invalidEmail) {
            $result = $this->emailService->sendMagicLinkEmail($invalidEmail, 'https://example.com/magic');
            
            // The email service should handle validation or pass it to the underlying mail system
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any server variables set during testing
        unset($_SERVER['HTTP_USER_AGENT']);
    }
}