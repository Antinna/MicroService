<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Services\PasskeyHandler;
use Antinna\Auth\Services\PasskeyManager;
use Antinna\Auth\Services\SessionManager;
use Antinna\Auth\Services\JWTManager;
use Antinna\Auth\Services\AuditLogger;
use Exception;

/**
 * Passkey Controller for WebAuthn API endpoints
 */
class PasskeyController
{
    private PasskeyHandler $passkeyHandler;
    private PasskeyManager $passkeyManager;
    private SessionManager $sessionManager;
    private JWTManager $jwtManager;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->passkeyHandler = new PasskeyHandler();
        $this->passkeyManager = new PasskeyManager();
        $this->sessionManager = new SessionManager();
        $this->jwtManager = new JWTManager();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Generate registration challenge
     * POST /api/passkeys/register/begin
     */
    public function beginRegistration(): void
    {
        try {
            // Get authenticated user
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $result = $this->passkeyHandler->generateRegistrationChallenge($userId);

            if ($result['success']) {
                $this->sendSuccess($result['options'], 'Registration challenge generated');
            } else {
                $this->sendError($result['error'], 400, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_registration_begin_error',
                'Registration begin error: ' . $e->getMessage(),
                null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to generate registration challenge', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Complete registration
     * POST /api/passkeys/register/complete
     */
    public function completeRegistration(): void
    {
        try {
            // Get authenticated user
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $input = $this->getJsonInput();
            if (!$input) {
                $this->sendError('Invalid JSON input', 400, 'INVALID_INPUT');
                return;
            }

            // Validate required fields
            $requiredFields = ['id', 'rawId', 'response', 'type', 'deviceName'];
            foreach ($requiredFields as $field) {
                if (!isset($input[$field])) {
                    $this->sendError("Missing required field: $field", 400, 'MISSING_FIELD');
                    return;
                }
            }

            // Validate device name
            if (empty(trim($input['deviceName']))) {
                $this->sendError('Device name is required', 400, 'DEVICE_NAME_REQUIRED');
                return;
            }

            $response = $input['response'];
            $deviceName = trim($input['deviceName']);

            // Prepare response data for verification
            $verificationData = [
                'id' => $input['id'],
                'rawId' => $input['rawId'],
                'clientDataJSON' => $response['clientDataJSON'],
                'attestationObject' => $response['attestationObject']
            ];

            $result = $this->passkeyHandler->verifyRegistration($userId, $verificationData, $deviceName);

            if ($result['success']) {
                $this->sendSuccess([
                    'passkey_id' => $result['passkey_id'],
                    'device_name' => $result['device_name']
                ], $result['message']);
            } else {
                $this->sendError($result['error'], 400, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_registration_complete_error',
                'Registration complete error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to complete registration', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Generate authentication challenge
     * POST /api/passkeys/authenticate/begin
     */
    public function beginAuthentication(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input || !isset($input['email'])) {
                $this->sendError('Email is required', 400, 'EMAIL_REQUIRED');
                return;
            }

            $email = trim($input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendError('Invalid email format', 400, 'INVALID_EMAIL');
                return;
            }

            $result = $this->passkeyHandler->generateAuthenticationChallenge($email);

            if ($result['success']) {
                // Store user ID in session for later verification
                $_SESSION['passkey_auth_user_id'] = $result['user_id'];
                
                $this->sendSuccess($result['options'], 'Authentication challenge generated');
            } else {
                $this->sendError($result['error'], 400, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_auth_begin_error',
                'Authentication begin error: ' . $e->getMessage(),
                null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to generate authentication challenge', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Complete authentication
     * POST /api/passkeys/authenticate/complete
     */
    public function completeAuthentication(): void
    {
        try {
            $input = $this->getJsonInput();
            if (!$input) {
                $this->sendError('Invalid JSON input', 400, 'INVALID_INPUT');
                return;
            }

            // Get user ID from session
            $userId = $_SESSION['passkey_auth_user_id'] ?? null;
            if (!$userId) {
                $this->sendError('No authentication challenge found', 400, 'NO_CHALLENGE');
                return;
            }

            // Validate required fields
            $requiredFields = ['id', 'rawId', 'response', 'type'];
            foreach ($requiredFields as $field) {
                if (!isset($input[$field])) {
                    $this->sendError("Missing required field: $field", 400, 'MISSING_FIELD');
                    return;
                }
            }

            $response = $input['response'];

            // Prepare response data for verification
            $verificationData = [
                'id' => $input['id'],
                'rawId' => $input['rawId'],
                'clientDataJSON' => $response['clientDataJSON'],
                'authenticatorData' => $response['authenticatorData'],
                'signature' => $response['signature'],
                'userHandle' => $response['userHandle'] ?? null
            ];

            $result = $this->passkeyHandler->verifyAuthentication($userId, $verificationData);

            if ($result['success']) {
                $user = $result['user'];
                
                // Create session
                $sessionResult = $this->sessionManager->createSession($user['id'], [
                    'auth_method' => 'passkey',
                    'passkey_id' => $result['passkey']['id'],
                    'device_name' => $result['passkey']['device_name']
                ]);

                if (!$sessionResult['success']) {
                    $this->sendError('Failed to create session', 500, 'SESSION_ERROR');
                    return;
                }

                // Generate JWT token
                $tokenResult = $this->jwtManager->generateToken($user['id'], [
                    'auth_method' => 'passkey',
                    'session_id' => $sessionResult['session_id']
                ]);

                if (!$tokenResult['success']) {
                    $this->sendError('Failed to generate token', 500, 'TOKEN_ERROR');
                    return;
                }

                // Clear session data
                unset($_SESSION['passkey_auth_user_id']);

                $this->sendSuccess([
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'is_verified' => (bool)$user['is_verified']
                    ],
                    'session' => [
                        'id' => $sessionResult['session_id'],
                        'expires_at' => $sessionResult['expires_at']
                    ],
                    'token' => [
                        'access_token' => $tokenResult['access_token'],
                        'refresh_token' => $tokenResult['refresh_token'],
                        'expires_in' => $tokenResult['expires_in']
                    ],
                    'auth_method' => 'passkey',
                    'device_name' => $result['passkey']['device_name']
                ], 'Authentication successful');

            } else {
                $this->sendError($result['error'], 400, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_auth_complete_error',
                'Authentication complete error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to complete authentication', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get user's passkey devices
     * GET /api/passkeys/devices
     */
    public function getDevices(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $result = $this->passkeyManager->getUserDevices($userId);

            if ($result['success']) {
                // Remove sensitive data from response
                $devices = array_map(function($device) {
                    return [
                        'id' => $device['id'],
                        'device_name' => $device['device_name'],
                        'created_at' => $device['created_at'],
                        'last_used' => $device['last_used'],
                        'sign_count' => $device['sign_count'],
                        'is_recently_used' => $device['is_recently_used'],
                        'usage_frequency' => $device['usage_frequency']
                    ];
                }, $result['devices']);

                $this->sendSuccess([
                    'devices' => $devices,
                    'total_count' => $result['total_count']
                ], 'Devices retrieved successfully');
            } else {
                $this->sendError($result['error'], 500, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_get_devices_error',
                'Get devices error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to retrieve devices', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Update device name
     * PUT /api/passkeys/devices/{deviceId}
     */
    public function updateDevice(int $deviceId): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['device_name'])) {
                $this->sendError('Device name is required', 400, 'DEVICE_NAME_REQUIRED');
                return;
            }

            $deviceName = trim($input['device_name']);
            if (empty($deviceName)) {
                $this->sendError('Device name cannot be empty', 400, 'DEVICE_NAME_EMPTY');
                return;
            }

            $result = $this->passkeyManager->updateDeviceName($userId, $deviceId, $deviceName);

            if ($result['success']) {
                $this->sendSuccess([], $result['message']);
            } else {
                $statusCode = $result['code'] === 'DEVICE_NOT_FOUND' ? 404 : 400;
                $this->sendError($result['error'], $statusCode, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_update_device_error',
                'Update device error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to update device', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Remove device
     * DELETE /api/passkeys/devices/{deviceId}
     */
    public function removeDevice(int $deviceId): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $result = $this->passkeyManager->removeDevice($userId, $deviceId);

            if ($result['success']) {
                $this->sendSuccess([], $result['message']);
            } else {
                $statusCode = $result['code'] === 'DEVICE_NOT_FOUND' ? 404 : 400;
                $this->sendError($result['error'], $statusCode, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_remove_device_error',
                'Remove device error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to remove device', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get device security status
     * GET /api/passkeys/devices/{deviceId}/security
     */
    public function getDeviceSecurityStatus(int $deviceId): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $result = $this->passkeyManager->getDeviceSecurityStatus($userId, $deviceId);

            if ($result['success']) {
                $this->sendSuccess($result['security_status'], 'Security status retrieved successfully');
            } else {
                $statusCode = $result['code'] === 'DEVICE_NOT_FOUND' ? 404 : 500;
                $this->sendError($result['error'], $statusCode, $result['code']);
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_security_status_error',
                'Security status error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to get security status', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Bulk remove devices
     * DELETE /api/passkeys/devices/bulk
     */
    public function bulkRemoveDevices(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            $input = $this->getJsonInput();
            if (!$input || !isset($input['device_ids']) || !is_array($input['device_ids'])) {
                $this->sendError('Device IDs array is required', 400, 'DEVICE_IDS_REQUIRED');
                return;
            }

            $deviceIds = array_filter(array_map('intval', $input['device_ids']));
            if (empty($deviceIds)) {
                $this->sendError('Valid device IDs are required', 400, 'INVALID_DEVICE_IDS');
                return;
            }

            $result = $this->passkeyManager->bulkRemoveDevices($userId, $deviceIds);

            $this->sendSuccess($result, 'Bulk removal completed');

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_bulk_remove_error',
                'Bulk remove error: ' . $e->getMessage(),
                $userId ?? null,
                'unknown',
                'error'
            );
            $this->sendError('Failed to remove devices', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get authenticated user ID from session or JWT
     */
    private function getAuthenticatedUserId(): ?int
    {
        // Try to get from session first
        if (isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        // Try to get from JWT token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenResult = $this->jwtManager->validateToken($token);
            
            if ($tokenResult['success'] && isset($tokenResult['payload']['user_id'])) {
                return (int)$tokenResult['payload']['user_id'];
            }
        }

        return null;
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }

        $decoded = json_decode($input, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Send success response
     */
    private function sendSuccess(array $data, string $message = 'Success'): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Send error response
     */
    private function sendError(string $message, int $statusCode = 400, string $code = 'ERROR'): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ]);
    }
}