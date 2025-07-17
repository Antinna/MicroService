<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\AuditLogger;
use Exception;
use PDO;

/**
 * Passkey Manager for device registration and management
 */
class PasskeyManager
{
    private PDO $db;
    private AuditLogger $auditLogger;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->auditLogger = new AuditLogger();
    }

    /**
     * Register a new passkey device
     */
    public function registerDevice(int $userId, string $credentialId, string $publicKey, string $deviceName, array $metadata = []): array
    {
        try {
            // Check if device name already exists for this user
            if ($this->deviceNameExists($userId, $deviceName)) {
                return [
                    'success' => false,
                    'error' => 'Device name already exists',
                    'code' => 'DEVICE_NAME_EXISTS'
                ];
            }

            // Check if credential ID already exists
            if ($this->credentialIdExists($credentialId)) {
                return [
                    'success' => false,
                    'error' => 'Credential ID already exists',
                    'code' => 'CREDENTIAL_EXISTS'
                ];
            }

            // Validate device name
            if (!$this->isValidDeviceName($deviceName)) {
                return [
                    'success' => false,
                    'error' => 'Invalid device name',
                    'code' => 'INVALID_DEVICE_NAME'
                ];
            }

            // Check device limit per user
            $deviceCount = $this->getUserDeviceCount($userId);
            $maxDevices = $this->getMaxDevicesPerUser();
            
            if ($deviceCount >= $maxDevices) {
                return [
                    'success' => false,
                    'error' => "Maximum number of devices reached ($maxDevices)",
                    'code' => 'DEVICE_LIMIT_EXCEEDED'
                ];
            }

            // Store device metadata
            $metadataJson = json_encode(array_merge($metadata, [
                'registered_at' => date('Y-m-d H:i:s'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
            ]));

            // Insert passkey device
            $sql = "
                INSERT INTO passkeys (
                    user_id, credential_id, public_key, device_name, 
                    sign_count, metadata, is_active, created_at
                ) VALUES (?, ?, ?, ?, 0, ?, 1, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $userId, 
                $credentialId, 
                $publicKey, 
                $deviceName, 
                $metadataJson
            ]);

            if ($success) {
                $deviceId = (int)$this->db->lastInsertId();
                
                $this->auditLogger->log(
                    'passkey_device_registered', 
                    "Passkey device '$deviceName' registered", 
                    $userId
                );

                return [
                    'success' => true,
                    'device_id' => $deviceId,
                    'device_name' => $deviceName,
                    'message' => 'Device registered successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to register device',
                    'code' => 'REGISTRATION_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_device_registration_error', 
                'Device registration error: ' . $e->getMessage(), 
                $userId, 
                'unknown', 
                'error'
            );

            return [
                'success' => false,
                'error' => 'Device registration failed',
                'code' => 'REGISTRATION_ERROR'
            ];
        }
    }

    /**
     * Get all devices for a user
     */
    public function getUserDevices(int $userId): array
    {
        try {
            $sql = "
                SELECT id, device_name, created_at, last_used, sign_count, metadata
                FROM passkeys 
                WHERE user_id = ? AND is_active = 1 
                ORDER BY created_at DESC
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse metadata and add additional info
            foreach ($devices as &$device) {
                $metadata = json_decode($device['metadata'], true) ?? [];
                $device['metadata'] = $metadata;
                $device['is_recently_used'] = $this->isRecentlyUsed($device['last_used']);
                $device['usage_frequency'] = $this->calculateUsageFrequency($device);
            }

            return [
                'success' => true,
                'devices' => $devices,
                'total_count' => count($devices)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to retrieve devices',
                'code' => 'RETRIEVAL_ERROR'
            ];
        }
    }

    /**
     * Update device name
     */
    public function updateDeviceName(int $userId, int $deviceId, string $newDeviceName): array
    {
        try {
            // Validate new device name
            if (!$this->isValidDeviceName($newDeviceName)) {
                return [
                    'success' => false,
                    'error' => 'Invalid device name',
                    'code' => 'INVALID_DEVICE_NAME'
                ];
            }

            // Check if device exists and belongs to user
            $device = $this->getDeviceById($deviceId, $userId);
            if (!$device) {
                return [
                    'success' => false,
                    'error' => 'Device not found',
                    'code' => 'DEVICE_NOT_FOUND'
                ];
            }

            // Check if new name already exists for this user (excluding current device)
            if ($this->deviceNameExists($userId, $newDeviceName, $deviceId)) {
                return [
                    'success' => false,
                    'error' => 'Device name already exists',
                    'code' => 'DEVICE_NAME_EXISTS'
                ];
            }

            // Update device name
            $sql = "UPDATE passkeys SET device_name = ? WHERE id = ? AND user_id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$newDeviceName, $deviceId, $userId]);

            if ($success && $stmt->rowCount() > 0) {
                $this->auditLogger->log(
                    'passkey_device_renamed', 
                    "Device renamed from '{$device['device_name']}' to '$newDeviceName'", 
                    $userId
                );

                return [
                    'success' => true,
                    'message' => 'Device name updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update device name',
                    'code' => 'UPDATE_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_device_rename_error', 
                'Device rename error: ' . $e->getMessage(), 
                $userId, 
                'unknown', 
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to update device name',
                'code' => 'UPDATE_ERROR'
            ];
        }
    }

    /**
     * Remove a device
     */
    public function removeDevice(int $userId, int $deviceId): array
    {
        try {
            // Check if device exists and belongs to user
            $device = $this->getDeviceById($deviceId, $userId);
            if (!$device) {
                return [
                    'success' => false,
                    'error' => 'Device not found',
                    'code' => 'DEVICE_NOT_FOUND'
                ];
            }

            // Check if this is the last device (security policy)
            $deviceCount = $this->getUserDeviceCount($userId);
            if ($deviceCount <= 1 && $this->requiresMinimumDevices($userId)) {
                return [
                    'success' => false,
                    'error' => 'Cannot remove the last passkey device',
                    'code' => 'LAST_DEVICE_PROTECTION'
                ];
            }

            // Soft delete the device
            $sql = "UPDATE passkeys SET is_active = 0, deleted_at = NOW() WHERE id = ? AND user_id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$deviceId, $userId]);

            if ($success && $stmt->rowCount() > 0) {
                $this->auditLogger->log(
                    'passkey_device_removed', 
                    "Device '{$device['device_name']}' removed", 
                    $userId
                );

                return [
                    'success' => true,
                    'message' => 'Device removed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to remove device',
                    'code' => 'REMOVAL_FAILED'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log(
                'passkey_device_removal_error', 
                'Device removal error: ' . $e->getMessage(), 
                $userId, 
                'unknown', 
                'error'
            );

            return [
                'success' => false,
                'error' => 'Failed to remove device',
                'code' => 'REMOVAL_ERROR'
            ];
        }
    }

    /**
     * Get device security status
     */
    public function getDeviceSecurityStatus(int $userId, int $deviceId): array
    {
        try {
            $device = $this->getDeviceById($deviceId, $userId);
            if (!$device) {
                return [
                    'success' => false,
                    'error' => 'Device not found',
                    'code' => 'DEVICE_NOT_FOUND'
                ];
            }

            $metadata = json_decode($device['metadata'], true) ?? [];
            $securityStatus = [
                'device_id' => $deviceId,
                'device_name' => $device['device_name'],
                'created_at' => $device['created_at'],
                'last_used' => $device['last_used'],
                'sign_count' => $device['sign_count'],
                'is_recently_used' => $this->isRecentlyUsed($device['last_used']),
                'usage_frequency' => $this->calculateUsageFrequency($device),
                'security_score' => $this->calculateSecurityScore($device),
                'risk_factors' => $this->identifyRiskFactors($device, $metadata),
                'recommendations' => $this->getSecurityRecommendations($device, $metadata)
            ];

            return [
                'success' => true,
                'security_status' => $securityStatus
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get security status',
                'code' => 'SECURITY_STATUS_ERROR'
            ];
        }
    }

    /**
     * Bulk device management operations
     */
    public function bulkRemoveDevices(int $userId, array $deviceIds): array
    {
        try {
            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($deviceIds as $deviceId) {
                $result = $this->removeDevice($userId, $deviceId);
                $results[$deviceId] = $result;
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            return [
                'success' => $errorCount === 0,
                'results' => $results,
                'summary' => [
                    'total' => count($deviceIds),
                    'success' => $successCount,
                    'errors' => $errorCount
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Bulk operation failed',
                'code' => 'BULK_OPERATION_ERROR'
            ];
        }
    }

    /**
     * Check if device name exists for user
     */
    private function deviceNameExists(int $userId, string $deviceName, ?int $excludeDeviceId = null): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM passkeys WHERE user_id = ? AND device_name = ? AND is_active = 1";
            $params = [$userId, $deviceName];
            
            if ($excludeDeviceId) {
                $sql .= " AND id != ?";
                $params[] = $excludeDeviceId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if credential ID exists
     */
    private function credentialIdExists(string $credentialId): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM passkeys WHERE credential_id = ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$credentialId]);
            
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate device name
     */
    private function isValidDeviceName(string $deviceName): bool
    {
        // Device name should be 1-50 characters, alphanumeric with spaces and basic punctuation
        return preg_match('/^[a-zA-Z0-9\s\-_\.]{1,50}$/', $deviceName);
    }

    /**
     * Get user device count
     */
    private function getUserDeviceCount(int $userId): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM passkeys WHERE user_id = ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get maximum devices per user
     */
    private function getMaxDevicesPerUser(): int
    {
        // This could be configurable based on user plan or system settings
        return 10;
    }

    /**
     * Get device by ID and user
     */
    private function getDeviceById(int $deviceId, int $userId): ?array
    {
        try {
            $sql = "
                SELECT * FROM passkeys 
                WHERE id = ? AND user_id = ? AND is_active = 1 
                LIMIT 1
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$deviceId, $userId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if device was recently used
     */
    private function isRecentlyUsed(?string $lastUsed): bool
    {
        if (!$lastUsed) {
            return false;
        }
        
        $lastUsedTime = strtotime($lastUsed);
        $sevenDaysAgo = time() - (7 * 24 * 60 * 60);
        
        return $lastUsedTime > $sevenDaysAgo;
    }

    /**
     * Calculate usage frequency
     */
    private function calculateUsageFrequency(array $device): string
    {
        $signCount = (int)$device['sign_count'];
        $createdAt = strtotime($device['created_at']);
        $daysSinceCreation = max(1, (time() - $createdAt) / (24 * 60 * 60));
        
        $usagePerDay = $signCount / $daysSinceCreation;
        
        if ($usagePerDay >= 1) {
            return 'high';
        } elseif ($usagePerDay >= 0.1) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Calculate security score
     */
    private function calculateSecurityScore(array $device): int
    {
        $score = 100;
        
        // Reduce score for old devices
        $createdAt = strtotime($device['created_at']);
        $ageInDays = (time() - $createdAt) / (24 * 60 * 60);
        if ($ageInDays > 365) {
            $score -= 10;
        }
        
        // Reduce score for unused devices
        if (!$this->isRecentlyUsed($device['last_used'])) {
            $score -= 20;
        }
        
        // Reduce score for low usage
        if ($this->calculateUsageFrequency($device) === 'low') {
            $score -= 10;
        }
        
        return max(0, $score);
    }

    /**
     * Identify risk factors
     */
    private function identifyRiskFactors(array $device, array $metadata): array
    {
        $riskFactors = [];
        
        // Check for old devices
        $createdAt = strtotime($device['created_at']);
        $ageInDays = (time() - $createdAt) / (24 * 60 * 60);
        if ($ageInDays > 365) {
            $riskFactors[] = 'Device is over 1 year old';
        }
        
        // Check for unused devices
        if (!$this->isRecentlyUsed($device['last_used'])) {
            $riskFactors[] = 'Device has not been used recently';
        }
        
        // Check for suspicious patterns
        if ($device['sign_count'] === 0) {
            $riskFactors[] = 'Device has never been used for authentication';
        }
        
        return $riskFactors;
    }

    /**
     * Get security recommendations
     */
    private function getSecurityRecommendations(array $device, array $metadata): array
    {
        $recommendations = [];
        
        if (!$this->isRecentlyUsed($device['last_used'])) {
            $recommendations[] = 'Consider removing this device if you no longer use it';
        }
        
        $createdAt = strtotime($device['created_at']);
        $ageInDays = (time() - $createdAt) / (24 * 60 * 60);
        if ($ageInDays > 365) {
            $recommendations[] = 'Consider re-registering this device for enhanced security';
        }
        
        if ($device['sign_count'] === 0) {
            $recommendations[] = 'Test this device to ensure it works properly';
        }
        
        return $recommendations;
    }

    /**
     * Check if user requires minimum devices
     */
    private function requiresMinimumDevices(int $userId): bool
    {
        // This could be based on user role or security policy
        // For now, we'll require at least one device for all users
        return true;
    }
}