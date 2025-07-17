<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Services\AuditLogger;
use Exception;

/**
 * Email Service for sending authentication emails
 */
class EmailService
{
    private AuditLogger $auditLogger;
    private array $emailProviders;

    public function __construct()
    {
        Environment::load();
        $this->auditLogger = new AuditLogger();
        $this->initializeEmailProviders();
    }

    /**
     * Send magic link email
     */
    public function sendMagicLinkEmail(string $email, string $magicLink, array $options = []): array
    {
        try {
            // Prepare email data
            $emailData = [
                'to' => $email,
                'subject' => $options['subject'] ?? 'Your Magic Link - Sign In Instantly',
                'template' => 'magic_link',
                'variables' => [
                    'magic_link' => $magicLink,
                    'user_email' => $email,
                    'expires_in_minutes' => $options['expires_in_minutes'] ?? 15,
                    'app_name' => Environment::get('SERVICE_NAME', 'Auth Service'),
                    'app_url' => Environment::get('APP_URL', 'https://localhost'),
                    'support_email' => Environment::get('SUPPORT_EMAIL', 'support@example.com'),
                    'company_name' => Environment::get('COMPANY_NAME', 'Your Company'),
                    'current_year' => date('Y'),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                    'user_agent' => $this->getUserAgentInfo(),
                    'timestamp' => date('F j, Y \a\t g:i A T')
                ]
            ];

            // Add custom variables if provided
            if (isset($options['template_variables'])) {
                $emailData['variables'] = array_merge($emailData['variables'], $options['template_variables']);
            }

            // Send email
            $result = $this->sendEmail($emailData);

            if ($result['success']) {
                $this->auditLogger->log(
                    'magic_link_email_sent',
                    "Magic link email sent to $email",
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                );

                return [
                    'success' => true,
                    'message' => 'Magic link email sent successfully',
                    'data' => [
                        'email' => $email,
                        'message_id' => $result['message_id'] ?? null,
                        'provider' => $result['provider'] ?? 'default'
                    ]
                ];
            } else {
                return $result;
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'magic_link_email_error',
                'Magic link email error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to send magic link email',
                'code' => 'EMAIL_SEND_ERROR'
            ];
        }
    }

    /**
     * Send welcome email after successful authentication
     */
    public function sendWelcomeEmail(string $email, array $userInfo = []): array
    {
        try {
            $emailData = [
                'to' => $email,
                'subject' => 'Welcome! You\'ve successfully signed in',
                'template' => 'welcome',
                'variables' => [
                    'user_email' => $email,
                    'user_name' => $userInfo['name'] ?? $email,
                    'app_name' => Environment::get('SERVICE_NAME', 'Auth Service'),
                    'app_url' => Environment::get('APP_URL', 'https://localhost'),
                    'dashboard_url' => $userInfo['dashboard_url'] ?? Environment::get('APP_URL', 'https://localhost'),
                    'support_email' => Environment::get('SUPPORT_EMAIL', 'support@example.com'),
                    'company_name' => Environment::get('COMPANY_NAME', 'Your Company'),
                    'current_year' => date('Y'),
                    'login_time' => date('F j, Y \a\t g:i A T'),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                    'user_agent' => $this->getUserAgentInfo()
                ]
            ];

            $result = $this->sendEmail($emailData);

            if ($result['success']) {
                $this->auditLogger->log(
                    'welcome_email_sent',
                    "Welcome email sent to $email",
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                );
            }

            return $result;

        } catch (Exception $e) {
            $this->auditLogger->log(
                'welcome_email_error',
                'Welcome email error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to send welcome email',
                'code' => 'EMAIL_SEND_ERROR'
            ];
        }
    }

    /**
     * Send security alert email
     */
    public function sendSecurityAlertEmail(string $email, string $alertType, array $details = []): array
    {
        try {
            $alertMessages = [
                'suspicious_login' => 'We detected a suspicious login attempt on your account',
                'new_device' => 'A new device was used to access your account',
                'password_change' => 'Your account password was changed',
                'account_locked' => 'Your account has been temporarily locked due to suspicious activity',
                'magic_link_abuse' => 'Multiple magic link requests detected from your account'
            ];

            $emailData = [
                'to' => $email,
                'subject' => 'Security Alert - ' . ($alertMessages[$alertType] ?? 'Account Activity'),
                'template' => 'security_alert',
                'variables' => [
                    'user_email' => $email,
                    'alert_type' => $alertType,
                    'alert_message' => $alertMessages[$alertType] ?? 'Unusual account activity detected',
                    'app_name' => Environment::get('SERVICE_NAME', 'Auth Service'),
                    'app_url' => Environment::get('APP_URL', 'https://localhost'),
                    'support_email' => Environment::get('SUPPORT_EMAIL', 'support@example.com'),
                    'company_name' => Environment::get('COMPANY_NAME', 'Your Company'),
                    'current_year' => date('Y'),
                    'alert_time' => date('F j, Y \a\t g:i A T'),
                    'ip_address' => $details['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                    'user_agent' => $details['user_agent'] ?? $this->getUserAgentInfo(),
                    'location' => $details['location'] ?? 'Unknown',
                    'action_required' => $details['action_required'] ?? false
                ]
            ];

            $result = $this->sendEmail($emailData);

            if ($result['success']) {
                $this->auditLogger->log(
                    'security_alert_email_sent',
                    "Security alert email sent to $email (type: $alertType)",
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                );
            }

            return $result;

        } catch (Exception $e) {
            $this->auditLogger->log(
                'security_alert_email_error',
                'Security alert email error: ' . $e->getMessage(),
                null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to send security alert email',
                'code' => 'EMAIL_SEND_ERROR'
            ];
        }
    }

    /**
     * Send email using configured provider
     */
    private function sendEmail(array $emailData): array
    {
        $provider = Environment::get('EMAIL_SERVICE_PROVIDER', 'smtp');

        switch ($provider) {
            case 'smtp':
                return $this->sendViaSMTP($emailData);
            case 'sendgrid':
                return $this->sendViaSendGrid($emailData);
            case 'mailgun':
                return $this->sendViaMailgun($emailData);
            case 'ses':
                return $this->sendViaSES($emailData);
            default:
                return $this->sendViaDefault($emailData);
        }
    }

    /**
     * Send email via SMTP
     */
    private function sendViaSMTP(array $emailData): array
    {
        try {
            // For production, you would use a proper SMTP library like PHPMailer or SwiftMailer
            // This is a simplified implementation for demonstration

            $to = $emailData['to'];
            $subject = $emailData['subject'];
            $htmlBody = $this->renderEmailTemplate($emailData['template'], $emailData['variables']);
            $textBody = $this->convertHtmlToText($htmlBody);

            // Prepare headers
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="boundary-' . uniqid() . '"',
                'From: ' . Environment::get('EMAIL_FROM_ADDRESS', 'noreply@example.com'),
                'Reply-To: ' . Environment::get('EMAIL_REPLY_TO', 'noreply@example.com'),
                'X-Mailer: Auth Service Email System',
                'X-Priority: 3'
            ];

            $boundary = 'boundary-' . uniqid();
            $headers[1] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            // Prepare multipart message
            $message = "--$boundary\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $textBody . "\r\n\r\n";

            $message .= "--$boundary\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $htmlBody . "\r\n\r\n";

            $message .= "--$boundary--";

            // Send email
            $success = mail($to, $subject, $message, implode("\r\n", $headers));

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Email sent successfully via SMTP',
                    'provider' => 'smtp',
                    'message_id' => uniqid('smtp_')
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to send email via SMTP',
                    'code' => 'SMTP_SEND_FAILED'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'SMTP error: ' . $e->getMessage(),
                'code' => 'SMTP_ERROR'
            ];
        }
    }

    /**
     * Send email via SendGrid API
     */
    private function sendViaSendGrid(array $emailData): array
    {
        try {
            $apiKey = Environment::get('EMAIL_API_KEY');
            if (!$apiKey) {
                return [
                    'success' => false,
                    'error' => 'SendGrid API key not configured',
                    'code' => 'SENDGRID_CONFIG_ERROR'
                ];
            }

            $data = [
                'personalizations' => [
                    [
                        'to' => [['email' => $emailData['to']]],
                        'dynamic_template_data' => $emailData['variables']
                    ]
                ],
                'from' => [
                    'email' => Environment::get('EMAIL_FROM_ADDRESS', 'noreply@example.com'),
                    'name' => Environment::get('EMAIL_FROM_NAME', 'Auth Service')
                ],
                'subject' => $emailData['subject'],
                'content' => [
                    [
                        'type' => 'text/html',
                        'value' => $this->renderEmailTemplate($emailData['template'], $emailData['variables'])
                    ]
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.sendgrid.com/v3/mail/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'message' => 'Email sent successfully via SendGrid',
                    'provider' => 'sendgrid',
                    'message_id' => uniqid('sendgrid_')
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'SendGrid API error: HTTP ' . $httpCode,
                    'code' => 'SENDGRID_API_ERROR'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'SendGrid error: ' . $e->getMessage(),
                'code' => 'SENDGRID_ERROR'
            ];
        }
    }

    /**
     * Send email via default method (fallback)
     */
    private function sendViaDefault(array $emailData): array
    {
        // Fallback to basic mail() function
        return $this->sendViaSMTP($emailData);
    }

    /**
     * Render email template with variables
     */
    private function renderEmailTemplate(string $template, array $variables): string
    {
        $templatePath = __DIR__ . '/../Templates/Email/' . $template . '.html';

        if (file_exists($templatePath)) {
            $content = file_get_contents($templatePath);
        } else {
            // Use built-in template if file doesn't exist
            $content = $this->getBuiltInTemplate($template);
        }

        // Replace variables in template
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', htmlspecialchars($value), $content);
        }

        return $content;
    }

    /**
     * Get built-in email template
     */
    private function getBuiltInTemplate(string $template): string
    {
        switch ($template) {
            case 'magic_link':
                return $this->getMagicLinkTemplate();
            case 'welcome':
                return $this->getWelcomeTemplate();
            case 'security_alert':
                return $this->getSecurityAlertTemplate();
            default:
                return $this->getDefaultTemplate();
        }
    }

    /**
     * Magic link email template
     */
    private function getMagicLinkTemplate(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Magic Link</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        .security-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{app_name}}</h1>
        <p>Your secure sign-in link is ready</p>
    </div>
    
    <div class="content">
        <h2>Hello!</h2>
        <p>You requested a magic link to sign in to your account. Click the button below to sign in instantly:</p>
        
        <div style="text-align: center;">
            <a href="{{magic_link}}" class="button">Sign In Now</a>
        </div>
        
        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; background: #e9ecef; padding: 10px; border-radius: 3px;">{{magic_link}}</p>
        
        <div class="security-info">
            <strong>Security Information:</strong>
            <ul>
                <li>This link expires in {{expires_in_minutes}} minutes</li>
                <li>It can only be used once</li>
                <li>Requested from IP: {{ip_address}}</li>
                <li>Time: {{timestamp}}</li>
            </ul>
        </div>
        
        <p>If you didn\'t request this link, you can safely ignore this email. Your account remains secure.</p>
    </div>
    
    <div class="footer">
        <p>This email was sent to {{user_email}} by {{company_name}}.</p>
        <p>If you have questions, contact us at {{support_email}}</p>
        <p>&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</body>
</html>';
    }

    /**
     * Welcome email template
     */
    private function getWelcomeTemplate(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome!</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Welcome to {{app_name}}!</h1>
        <p>You\'ve successfully signed in</p>
    </div>
    
    <div class="content">
        <h2>Hello {{user_name}}!</h2>
        <p>Great news! You\'ve successfully signed in to your account using our secure magic link authentication.</p>
        
        <p><strong>Sign-in Details:</strong></p>
        <ul>
            <li>Time: {{login_time}}</li>
            <li>IP Address: {{ip_address}}</li>
            <li>Device: {{user_agent}}</li>
        </ul>
        
        <div style="text-align: center;">
            <a href="{{dashboard_url}}" class="button">Go to Dashboard</a>
        </div>
        
        <p>If this wasn\'t you, please contact our support team immediately.</p>
    </div>
    
    <div class="footer">
        <p>This email was sent to {{user_email}} by {{company_name}}.</p>
        <p>If you have questions, contact us at {{support_email}}</p>
        <p>&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</body>
</html>';
    }

    /**
     * Security alert email template
     */
    private function getSecurityAlertTemplate(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Alert</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .alert { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .button { display: inline-block; background: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔒 Security Alert</h1>
        <p>Important account activity detected</p>
    </div>
    
    <div class="content">
        <div class="alert">
            <strong>Alert:</strong> {{alert_message}}
        </div>
        
        <p><strong>Activity Details:</strong></p>
        <ul>
            <li>Time: {{alert_time}}</li>
            <li>IP Address: {{ip_address}}</li>
            <li>Device: {{user_agent}}</li>
            <li>Location: {{location}}</li>
        </ul>
        
        <p>If this was you, no action is needed. If you don\'t recognize this activity, please secure your account immediately.</p>
        
        <div style="text-align: center;">
            <a href="{{app_url}}" class="button">Secure My Account</a>
        </div>
    </div>
    
    <div class="footer">
        <p>This email was sent to {{user_email}} by {{company_name}}.</p>
        <p>If you have questions, contact us at {{support_email}}</p>
        <p>&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</body>
</html>';
    }

    /**
     * Default email template
     */
    private function getDefaultTemplate(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{app_name}}</title>
</head>
<body>
    <h1>{{app_name}}</h1>
    <p>This is a default email template.</p>
</body>
</html>';
    }

    /**
     * Convert HTML to plain text
     */
    private function convertHtmlToText(string $html): string
    {
        // Simple HTML to text conversion
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Get user agent information
     */
    private function getUserAgentInfo(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // Simple user agent parsing
        if (strpos($userAgent, 'Chrome') !== false) {
            return 'Chrome Browser';
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            return 'Firefox Browser';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            return 'Safari Browser';
        } elseif (strpos($userAgent, 'Edge') !== false) {
            return 'Edge Browser';
        } else {
            return 'Unknown Browser';
        }
    }

    /**
     * Initialize email providers
     */
    private function initializeEmailProviders(): void
    {
        $this->emailProviders = [
            'smtp' => 'SMTP Server',
            'sendgrid' => 'SendGrid API',
            'mailgun' => 'Mailgun API',
            'ses' => 'Amazon SES'
        ];
    }

    /**
     * Get available email providers
     */
    public function getAvailableProviders(): array
    {
        return $this->emailProviders;
    }

    /**
     * Test email configuration
     */
    public function testEmailConfiguration(): array
    {
        try {
            $testEmail = Environment::get('EMAIL_TEST_ADDRESS', 'test@example.com');

            $result = $this->sendEmail([
                'to' => $testEmail,
                'subject' => 'Email Configuration Test',
                'template' => 'default',
                'variables' => [
                    'app_name' => Environment::get('SERVICE_NAME', 'Auth Service'),
                    'test_time' => date('Y-m-d H:i:s')
                ]
            ]);

            return $result;

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Email configuration test failed: ' . $e->getMessage(),
                'code' => 'CONFIG_TEST_ERROR'
            ];
        }
    }
}