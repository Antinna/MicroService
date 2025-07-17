<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Services\Logger;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\ErrorHandler;
use Antinna\Auth\Repositories\UserRepository;
use Exception;

/**
 * Push Notification Service for Authentication Alerts
 */
class PushNotificationService
{
    private Logger $logger;
    private AuditLogger $auditLogger;
    private ErrorHandler $errorHandler;
    private UserRepository $userRepository;
    private array $config;

    // Notification types
    public const TYPE_LOGIN_ALERT = 'login_alert';
    public const TYPE_SECURITY_ALERT = 'security_alert';
    public const TYPE_MFA_REQUEST = 'mfa_request';
    public const TYPE_PASSWORD_RESET = 'password_reset';
    public const TYPE_ACCOUNT_LOCKED = 'account_locked';
    public const TYPE_NEW_DEVICE = 'new_device';
    public const TYPE_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const TYPE_BIOMETRIC_ENROLLED = 'biometric_enrolled';

    // Platforms
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_WEB = 'web';

    // Priority levels
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    public function __construct()
    {
        $this->logger = new Logger();
        $this->auditLogger = new AuditLogger();
        $this->errorHandler = new ErrorHandler();
        $this->userRepository = new UserRepository();

        $this->config = [
            'firebase' => [
                'project_id' => $_ENV['FIREBASE_PROJECT_ID'] ?? '',
                'service_account_path' => $_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? '',
                'api_url' => 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send',
                'oauth_url' => 'https://oauth2.googleapis.com/token',
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging'
            ],
            'apns' => [
                'key_id' => $_ENV['APNS_KEY_ID'] ?? '',
                'team_id' => $_ENV['APNS_TEAM_ID'] ?? '',
                'bundle_id' => $_ENV['APNS_BUNDLE_ID'] ?? '',
                'private_key_path' => $_ENV['APNS_PRIVATE_KEY_PATH'] ?? '',
                'environment' => $_ENV['APNS_ENVIRONMENT'] ?? 'sandbox'
            ],
            'web_push' => [
                'vapid_public_key' => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                'vapid_private_key' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
                'vapid_subject' => $_ENV['VAPID_SUBJECT'] ?? 'mailto:admin@example.com'
            ],
            'retry_attempts' => 3,
            'retry_delay' => 5, // seconds
            'batch_size' => 100
        ];
    }

    /**
     * Send authentication alert notification
     */
    public function sendAuthAlert(int $userId, string $alertType, array $context = []): array
    {
        try {
            // Get user's notification preferences and devices
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // Check if user has notifications enabled
            if (!$this->isNotificationEnabled($userId, $alertType)) {
                return [
                    'success' => true,
                    'message' => 'Notification disabled by user preferences',
                    'code' => 'NOTIFICATION_DISABLED'
                ];
            }

            // Get user's registered devices
            $devices = $this->getUserDevices($userId);
            if (empty($devices)) {
                return [
                    'success' => false,
                    'message' => 'No registered devices found',
                    'code' => 'NO_DEVICES'
                ];
            }

            // Create notification content
            $notification = $this->createNotificationContent($alertType, $context, $user);

            // Send to all user devices
            $results = [];
            foreach ($devices as $device) {
                $result = $this->sendToDevice($device, $notification, $alertType);
                $results[] = $result;
            }

            // Log notification attempt
            $this->auditLogger->logSystemEvent(
                'push_notification_sent',
                "Push notification sent: {$alertType}",
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'alert_type' => $alertType,
                    'device_count' => count($devices),
                    'context' => $context,
                    'results' => $results
                ]
            );

            $successCount = count(array_filter($results, fn($r) => $r['success']));

            return [
                'success' => $successCount > 0,
                'message' => "Notification sent to {$successCount} of " . count($devices) . " devices",
                'devices_targeted' => count($devices),
                'devices_successful' => $successCount,
                'results' => $results
            ];

        } catch (Exception $e) {
            $this->logger->error('Push notification error', [
                'user_id' => $userId,
                'alert_type' => $alertType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], Logger::CHANNEL_EXTERNAL);

            return [
                'success' => false,
                'message' => 'Failed to send push notification',
                'code' => 'NOTIFICATION_FAILED',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send login alert notification
     */
    public function sendLoginAlert(int $userId, array $loginContext): array
    {
        $context = [
            'device' => $loginContext['device'] ?? 'Unknown Device',
            'location' => $loginContext['location'] ?? 'Unknown Location',
            'ip_address' => $loginContext['ip_address'] ?? 'Unknown IP',
            'timestamp' => date('Y-m-d H:i:s'),
            'login_method' => $loginContext['method'] ?? 'password'
        ];

        return $this->sendAuthAlert($userId, self::TYPE_LOGIN_ALERT, $context);
    }

    /**
     * Send security alert notification
     */
    public function sendSecurityAlert(int $userId, string $alertMessage, array $securityContext = []): array
    {
        $context = array_merge([
            'alert_message' => $alertMessage,
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => 'high'
        ], $securityContext);

        return $this->sendAuthAlert($userId, self::TYPE_SECURITY_ALERT, $context);
    }

    /**
     * Send MFA request notification
     */
    public function sendMFARequest(int $userId, string $mfaCode, array $requestContext = []): array
    {
        $context = array_merge([
            'mfa_code' => $mfaCode,
            'expires_in' => 300, // 5 minutes
            'timestamp' => date('Y-m-d H:i:s')
        ], $requestContext);

        return $this->sendAuthAlert($userId, self::TYPE_MFA_REQUEST, $context);
    }

    /**
     * Send password reset notification
     */
    public function sendPasswordResetAlert(int $userId, array $resetContext = []): array
    {
        $context = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ], $resetContext);

        return $this->sendAuthAlert($userId, self::TYPE_PASSWORD_RESET, $context);
    }

    /**
     * Send account locked notification
     */
    public function sendAccountLockedAlert(int $userId, array $lockContext = []): array
    {
        $context = array_merge([
            'reason' => 'Multiple failed login attempts',
            'timestamp' => date('Y-m-d H:i:s'),
            'unlock_time' => date('Y-m-d H:i:s', time() + 1800) // 30 minutes
        ], $lockContext);

        return $this->sendAuthAlert($userId, self::TYPE_ACCOUNT_LOCKED, $context);
    }

    /**
     * Send new device notification
     */
    public function sendNewDeviceAlert(int $userId, array $deviceContext): array
    {
        $context = [
            'device_name' => $deviceContext['device_name'] ?? 'Unknown Device',
            'device_type' => $deviceContext['device_type'] ?? 'Unknown',
            'location' => $deviceContext['location'] ?? 'Unknown Location',
            'ip_address' => $deviceContext['ip_address'] ?? 'Unknown IP',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        return $this->sendAuthAlert($userId, self::TYPE_NEW_DEVICE, $context);
    }

    /**
     * Send suspicious activity notification
     */
    public function sendSuspiciousActivityAlert(int $userId, string $activityType, array $activityContext = []): array
    {
        $context = array_merge([
            'activity_type' => $activityType,
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => 'high'
        ], $activityContext);

        return $this->sendAuthAlert($userId, self::TYPE_SUSPICIOUS_ACTIVITY, $context);
    }

    /**
     * Register device for push notifications
     */
    public function registerDevice(int $userId, array $deviceData): array
    {
        try {
            $requiredFields = ['token', 'platform', 'device_name'];
            foreach ($requiredFields as $field) {
                if (!isset($deviceData[$field]) || empty($deviceData[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ];
                }
            }

            // Validate platform
            if (!in_array($deviceData['platform'], [self::PLATFORM_IOS, self::PLATFORM_ANDROID, self::PLATFORM_WEB])) {
                return [
                    'success' => false,
                    'message' => 'Invalid platform',
                    'code' => 'INVALID_PLATFORM'
                ];
            }

            // Check if device already exists
            $existingDevice = $this->getDeviceByToken($deviceData['token']);
            if ($existingDevice) {
                // Update existing device
                $deviceId = $this->updateDevice($existingDevice['id'], $deviceData);
                $action = 'updated';
            } else {
                // Register new device
                $deviceId = $this->storeDevice($userId, $deviceData);
                $action = 'registered';
            }

            // Log device registration
            $this->auditLogger->logSystemEvent(
                'push_device_registered',
                "Push notification device {$action}",
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'platform' => $deviceData['platform'],
                    'device_name' => $deviceData['device_name'],
                    'action' => $action
                ]
            );

            return [
                'success' => true,
                'message' => "Device {$action} successfully",
                'device_id' => $deviceId,
                'action' => $action
            ];

        } catch (Exception $e) {
            $this->logger->error('Device registration error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ], Logger::CHANNEL_EXTERNAL);

            return [
                'success' => false,
                'message' => 'Failed to register device',
                'code' => 'REGISTRATION_FAILED'
            ];
        }
    }

    /**
     * Unregister device from push notifications
     */
    public function unregisterDevice(int $userId, string $deviceToken): array
    {
        try {
            $device = $this->getDeviceByToken($deviceToken);
            if (!$device || $device['user_id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => 'Device not found',
                    'code' => 'DEVICE_NOT_FOUND'
                ];
            }

            $success = $this->removeDevice($device['id']);
            if (!$success) {
                return [
                    'success' => false,
                    'message' => 'Failed to unregister device',
                    'code' => 'UNREGISTRATION_FAILED'
                ];
            }

            // Log device unregistration
            $this->auditLogger->logSystemEvent(
                'push_device_unregistered',
                'Push notification device unregistered',
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'device_id' => $device['id'],
                    'platform' => $device['platform'],
                    'device_name' => $device['device_name']
                ]
            );

            return [
                'success' => true,
                'message' => 'Device unregistered successfully',
                'device_id' => $device['id']
            ];

        } catch (Exception $e) {
            $this->logger->error('Device unregistration error', [
                'user_id' => $userId,
                'device_token' => $deviceToken,
                'error' => $e->getMessage()
            ], Logger::CHANNEL_EXTERNAL);

            return [
                'success' => false,
                'message' => 'Failed to unregister device',
                'code' => 'UNREGISTRATION_FAILED'
            ];
        }
    }

    /**
     * Get user's notification preferences
     */
    public function getNotificationPreferences(int $userId): array
    {
        try {
            $preferences = $this->getUserNotificationPreferences($userId);

            return [
                'success' => true,
                'preferences' => $preferences
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get notification preferences',
                'code' => 'PREFERENCES_FAILED'
            ];
        }
    }

    /**
     * Update user's notification preferences
     */
    public function updateNotificationPreferences(int $userId, array $preferences): array
    {
        try {
            $validTypes = [
                self::TYPE_LOGIN_ALERT,
                self::TYPE_SECURITY_ALERT,
                self::TYPE_MFA_REQUEST,
                self::TYPE_PASSWORD_RESET,
                self::TYPE_ACCOUNT_LOCKED,
                self::TYPE_NEW_DEVICE,
                self::TYPE_SUSPICIOUS_ACTIVITY,
                self::TYPE_BIOMETRIC_ENROLLED
            ];

            // Validate preferences
            foreach ($preferences as $type => $enabled) {
                if (!in_array($type, $validTypes)) {
                    return [
                        'success' => false,
                        'message' => "Invalid notification type: {$type}",
                        'code' => 'INVALID_TYPE'
                    ];
                }
            }

            $success = $this->storeNotificationPreferences($userId, $preferences);
            if (!$success) {
                return [
                    'success' => false,
                    'message' => 'Failed to update preferences',
                    'code' => 'UPDATE_FAILED'
                ];
            }

            // Log preference update
            $this->auditLogger->logSystemEvent(
                'notification_preferences_updated',
                'User updated notification preferences',
                AuditLogger::SEVERITY_INFO,
                [
                    'user_id' => $userId,
                    'preferences' => $preferences
                ]
            );

            return [
                'success' => true,
                'message' => 'Notification preferences updated successfully',
                'preferences' => $preferences
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update notification preferences',
                'code' => 'UPDATE_FAILED'
            ];
        }
    }

    /**
     * Send batch notifications
     */
    public function sendBatchNotifications(array $notifications): array
    {
        try {
            $results = [];
            $batches = array_chunk($notifications, $this->config['batch_size']);

            foreach ($batches as $batch) {
                $batchResults = $this->processBatch($batch);
                $results = array_merge($results, $batchResults);
            }

            $successCount = count(array_filter($results, fn($r) => $r['success']));

            return [
                'success' => $successCount > 0,
                'message' => "Sent {$successCount} of " . count($notifications) . " notifications",
                'total_notifications' => count($notifications),
                'successful_notifications' => $successCount,
                'results' => $results
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Batch notification failed',
                'code' => 'BATCH_FAILED',
                'error' => $e->getMessage()
            ];
        }
    }

    // Private helper methods

    /**
     * Create notification content based on type
     */
    private function createNotificationContent(string $type, array $context, array $user): array
    {
        $templates = [
            self::TYPE_LOGIN_ALERT => [
                'title' => 'New Login Detected',
                'body' => 'Your account was accessed from {device} at {location}',
                'priority' => self::PRIORITY_NORMAL,
                'category' => 'security'
            ],
            self::TYPE_SECURITY_ALERT => [
                'title' => 'Security Alert',
                'body' => '{alert_message}',
                'priority' => self::PRIORITY_HIGH,
                'category' => 'security'
            ],
            self::TYPE_MFA_REQUEST => [
                'title' => 'Authentication Code',
                'body' => 'Your verification code is: {mfa_code}',
                'priority' => self::PRIORITY_HIGH,
                'category' => 'authentication'
            ],
            self::TYPE_PASSWORD_RESET => [
                'title' => 'Password Reset',
                'body' => 'Your password was reset successfully',
                'priority' => self::PRIORITY_HIGH,
                'category' => 'security'
            ],
            self::TYPE_ACCOUNT_LOCKED => [
                'title' => 'Account Locked',
                'body' => 'Your account has been locked due to {reason}',
                'priority' => self::PRIORITY_CRITICAL,
                'category' => 'security'
            ],
            self::TYPE_NEW_DEVICE => [
                'title' => 'New Device Added',
                'body' => 'A new device ({device_name}) was added to your account',
                'priority' => self::PRIORITY_NORMAL,
                'category' => 'security'
            ],
            self::TYPE_SUSPICIOUS_ACTIVITY => [
                'title' => 'Suspicious Activity',
                'body' => 'Suspicious activity detected: {activity_type}',
                'priority' => self::PRIORITY_HIGH,
                'category' => 'security'
            ],
            self::TYPE_BIOMETRIC_ENROLLED => [
                'title' => 'Biometric Added',
                'body' => 'New biometric authentication method added to your account',
                'priority' => self::PRIORITY_NORMAL,
                'category' => 'security'
            ]
        ];

        $template = $templates[$type] ?? [
            'title' => 'Security Notification',
            'body' => 'Security notification from your account',
            'priority' => self::PRIORITY_NORMAL,
            'category' => 'security'
        ];

        // Replace placeholders in body
        $body = $template['body'];
        foreach ($context as $key => $value) {
            $body = str_replace('{' . $key . '}', $value, $body);
        }

        return [
            'title' => $template['title'],
            'body' => $body,
            'priority' => $template['priority'],
            'category' => $template['category'],
            'data' => [
                'type' => $type,
                'context' => $context,
                'user_id' => $user['id'],
                'timestamp' => time()
            ]
        ];
    }

    /**
     * Send notification to specific device
     */
    private function sendToDevice(array $device, array $notification, string $alertType): array
    {
        try {
            switch ($device['platform']) {
                case self::PLATFORM_ANDROID:
                    return $this->sendToAndroid($device, $notification);
                case self::PLATFORM_IOS:
                    return $this->sendToIOS($device, $notification);
                case self::PLATFORM_WEB:
                    return $this->sendToWeb($device, $notification);
                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported platform',
                        'device_id' => $device['id']
                    ];
            }
        } catch (Exception $e) {
            $this->logger->error('Device notification error', [
                'device_id' => $device['id'],
                'platform' => $device['platform'],
                'alert_type' => $alertType,
                'error' => $e->getMessage()
            ], Logger::CHANNEL_EXTERNAL);

            return [
                'success' => false,
                'message' => 'Failed to send to device',
                'device_id' => $device['id'],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send notification to Android device via Firebase v1 API
     */
    private function sendToAndroid(array $device, array $notification): array
    {
        $message = [
            'token' => $device['token'],
            'notification' => [
                'title' => $notification['title'],
                'body' => $notification['body']
            ],
            'data' => array_map('strval', $notification['data']), // FCM v1 requires string values
            'android' => [
                'priority' => $this->mapPriorityToAndroid($notification['priority']),
                'notification' => [
                    'icon' => 'ic_notification',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'channel_id' => 'auth_notifications'
                ]
            ]
        ];

        return $this->sendFirebaseV1Notification($message, $device['id']);
    }

    /**
     * Send notification to iOS device via Firebase v1 API
     */
    private function sendToIOS(array $device, array $notification): array
    {
        $message = [
            'token' => $device['token'],
            'notification' => [
                'title' => $notification['title'],
                'body' => $notification['body']
            ],
            'data' => array_map('strval', $notification['data']), // FCM v1 requires string values
            'apns' => [
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $notification['title'],
                            'body' => $notification['body']
                        ],
                        'sound' => 'default',
                        'badge' => 1,
                        'category' => $notification['category']
                    ]
                ]
            ]
        ];

        return $this->sendFirebaseV1Notification($message, $device['id']);
    }

    /**
     * Send notification to web browser via Firebase v1 API
     */
    private function sendToWeb(array $device, array $notification): array
    {
        $message = [
            'token' => $device['token'],
            'notification' => [
                'title' => $notification['title'],
                'body' => $notification['body']
            ],
            'data' => array_map('strval', $notification['data']), // FCM v1 requires string values
            'webpush' => [
                'notification' => [
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                    'icon' => '/icon-192x192.png',
                    'badge' => '/badge-72x72.png',
                    'requireInteraction' => $notification['priority'] === self::PRIORITY_CRITICAL
                ],
                'fcm_options' => [
                    'link' => '/'
                ]
            ]
        ];

        return $this->sendFirebaseV1Notification($message, $device['id']);
    }

    /**
     * Send notification via Firebase v1 API with OAuth2 authentication
     */
    private function sendFirebaseV1Notification(array $message, int $deviceId): array
    {
        try {
            // Get OAuth2 access token
            $accessToken = $this->getFirebaseAccessToken();
            if (!$accessToken) {
                return [
                    'success' => false,
                    'message' => 'Failed to get Firebase access token',
                    'device_id' => $deviceId
                ];
            }

            // Prepare the API URL
            $apiUrl = str_replace('{project_id}', $this->config['firebase']['project_id'], $this->config['firebase']['api_url']);

            // Prepare the payload
            $payload = [
                'message' => $message
            ];

            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("cURL error: {$error}");
            }

            $responseData = json_decode($response, true);

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Notification sent successfully',
                    'device_id' => $deviceId,
                    'response' => $responseData
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to send notification',
                    'device_id' => $deviceId,
                    'http_code' => $httpCode,
                    'response' => $responseData
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Firebase notification error: ' . $e->getMessage(),
                'device_id' => $deviceId
            ];
        }
    }

    /**
     * Get Firebase OAuth2 access token using service account
     */
    private function getFirebaseAccessToken(): ?string
    {
        try {
            // Check if service account file exists
            if (!file_exists($this->config['firebase']['service_account_path'])) {
                $this->logger->error('Firebase service account file not found', [
                    'path' => $this->config['firebase']['service_account_path']
                ], Logger::CHANNEL_EXTERNAL);
                return null;
            }

            // Load service account credentials
            $serviceAccount = json_decode(file_get_contents($this->config['firebase']['service_account_path']), true);
            if (!$serviceAccount) {
                $this->logger->error('Invalid Firebase service account file', [], Logger::CHANNEL_EXTERNAL);
                return null;
            }

            // Create JWT assertion
            $now = time();
            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT'
            ];

            $payload = [
                'iss' => $serviceAccount['client_email'],
                'scope' => $this->config['firebase']['scope'],
                'aud' => $this->config['firebase']['oauth_url'],
                'exp' => $now + 3600, // 1 hour
                'iat' => $now
            ];

            // Create JWT
            $jwt = $this->createJWT($header, $payload, $serviceAccount['private_key']);
            if (!$jwt) {
                return null;
            }

            // Request access token
            $postData = [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->config['firebase']['oauth_url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $tokenData = json_decode($response, true);
                return $tokenData['access_token'] ?? null;
            }

            $this->logger->error('Failed to get Firebase access token', [
                'http_code' => $httpCode,
                'response' => $response
            ], Logger::CHANNEL_EXTERNAL);

            return null;

        } catch (Exception $e) {
            $this->logger->error('Firebase OAuth error', [
                'error' => $e->getMessage()
            ], Logger::CHANNEL_EXTERNAL);
            return null;
        }
    }

    /**
     * Create JWT for Firebase OAuth2
     */
    private function createJWT(array $header, array $payload, string $privateKey): ?string
    {
        try {
            $headerEncoded = $this->base64UrlEncode(json_encode($header));
            $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

            $data = $headerEncoded . '.' . $payloadEncoded;

            // Sign with private key
            $signature = '';
            if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            $signatureEncoded = $this->base64UrlEncode($signature);

            return $data . '.' . $signatureEncoded;

        } catch (Exception $e) {
            $this->logger->error('JWT creation error', [
                'error' => $e->getMessage()
            ], Logger::CHANNEL_EXTERNAL);
            return null;
        }
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Map priority to Android priority
     */
    private function mapPriorityToAndroid(string $priority): string
    {
        return match ($priority) {
            self::PRIORITY_LOW => 'normal',
            self::PRIORITY_NORMAL => 'normal',
            self::PRIORITY_HIGH => 'high',
            self::PRIORITY_CRITICAL => 'high',
            default => 'normal'
        };
    }

    /**
     * Map priority to Firebase priority
     */
    private function mapPriorityToFirebase(string $priority): string
    {
        return match ($priority) {
            self::PRIORITY_LOW => 'normal',
            self::PRIORITY_NORMAL => 'normal',
            self::PRIORITY_HIGH => 'high',
            self::PRIORITY_CRITICAL => 'high',
            default => 'normal'
        };
    }

    /**
     * Process batch of notifications
     */
    private function processBatch(array $batch): array
    {
        $results = [];

        foreach ($batch as $notification) {
            if (!isset($notification['user_id']) || !isset($notification['type'])) {
                $results[] = [
                    'success' => false,
                    'message' => 'Invalid notification format'
                ];
                continue;
            }

            $result = $this->sendAuthAlert(
                $notification['user_id'],
                $notification['type'],
                $notification['context'] ?? []
            );

            $results[] = $result;
        }

        return $results;
    }

    // Database interaction methods (placeholders - would be implemented with actual database)

    /**
     * Check if notification is enabled for user
     */
    private function isNotificationEnabled(int $userId, string $type): bool
    {
        // This would check user preferences in database
        return true; // Placeholder - assume enabled
    }

    /**
     * Get user's registered devices
     */
    private function getUserDevices(int $userId): array
    {
        // This would query the database for user's devices
        return []; // Placeholder
    }

    /**
     * Get device by token
     */
    private function getDeviceByToken(string $token): ?array
    {
        // This would query the database
        return null; // Placeholder
    }

    /**
     * Store new device
     */
    private function storeDevice(int $userId, array $deviceData): int
    {
        // This would insert into database
        return rand(1000, 9999); // Placeholder device ID
    }

    /**
     * Update existing device
     */
    private function updateDevice(int $deviceId, array $deviceData): int
    {
        // This would update database record
        return $deviceId;
    }

    /**
     * Remove device
     */
    private function removeDevice(int $deviceId): bool
    {
        // This would delete from database
        return true; // Placeholder
    }

    /**
     * Get user notification preferences
     */
    private function getUserNotificationPreferences(int $userId): array
    {
        // This would query user preferences from database
        return [
            self::TYPE_LOGIN_ALERT => true,
            self::TYPE_SECURITY_ALERT => true,
            self::TYPE_MFA_REQUEST => true,
            self::TYPE_PASSWORD_RESET => true,
            self::TYPE_ACCOUNT_LOCKED => true,
            self::TYPE_NEW_DEVICE => true,
            self::TYPE_SUSPICIOUS_ACTIVITY => true,
            self::TYPE_BIOMETRIC_ENROLLED => true
        ];
    }

    /**
     * Store notification preferences
     */
    private function storeNotificationPreferences(int $userId, array $preferences): bool
    {
        // This would update database
        return true; // Placeholder
    }
}