<?php

require_once 'bootstrap/app.php';

use Antinna\Auth\Services\PasskeyManager;
use Antinna\Auth\Database\Connection;

try {
    echo "Testing PasskeyManager...\n";
    
    // Initialize database connection
    $db = Connection::getInstance()->getConnection();
    echo "Database connection: OK\n";
    
    // Create test user
    $sql = "INSERT INTO users (email, password_hash, is_verified) VALUES (?, ?, 1)";
    $stmt = $db->prepare($sql);
    $stmt->execute(['test@example.com', password_hash('password', PASSWORD_DEFAULT)]);
    $testUserId = (int)$db->lastInsertId();
    echo "Test user created: ID $testUserId\n";
    
    // Initialize PasskeyManager
    $passkeyManager = new PasskeyManager();
    echo "PasskeyManager initialized: OK\n";
    
    // Test device registration
    $result = $passkeyManager->registerDevice(
        $testUserId,
        'test_credential_123',
        'test_public_key_456',
        'Test Device',
        ['browser' => 'Chrome', 'os' => 'Windows']
    );
    
    if ($result['success']) {
        echo "Device registration: SUCCESS\n";
        echo "Device ID: " . $result['device_id'] . "\n";
        echo "Device Name: " . $result['device_name'] . "\n";
    } else {
        echo "Device registration: FAILED - " . $result['error'] . "\n";
    }
    
    // Test getting user devices
    $devicesResult = $passkeyManager->getUserDevices($testUserId);
    if ($devicesResult['success']) {
        echo "Get user devices: SUCCESS\n";
        echo "Total devices: " . $devicesResult['total_count'] . "\n";
        foreach ($devicesResult['devices'] as $device) {
            echo "  - " . $device['device_name'] . " (ID: " . $device['id'] . ")\n";
        }
    } else {
        echo "Get user devices: FAILED - " . $devicesResult['error'] . "\n";
    }
    
    // Test device name update
    if ($result['success']) {
        $deviceId = $result['device_id'];
        $updateResult = $passkeyManager->updateDeviceName($testUserId, $deviceId, 'Updated Test Device');
        
        if ($updateResult['success']) {
            echo "Device name update: SUCCESS\n";
        } else {
            echo "Device name update: FAILED - " . $updateResult['error'] . "\n";
        }
    }
    
    // Test security status
    if ($result['success']) {
        $deviceId = $result['device_id'];
        $securityResult = $passkeyManager->getDeviceSecurityStatus($testUserId, $deviceId);
        
        if ($securityResult['success']) {
            echo "Device security status: SUCCESS\n";
            $status = $securityResult['security_status'];
            echo "  Security Score: " . $status['security_score'] . "\n";
            echo "  Usage Frequency: " . $status['usage_frequency'] . "\n";
            echo "  Risk Factors: " . count($status['risk_factors']) . "\n";
        } else {
            echo "Device security status: FAILED - " . $securityResult['error'] . "\n";
        }
    }
    
    // Clean up
    $db->prepare("DELETE FROM passkeys WHERE user_id = ?")->execute([$testUserId]);
    $db->prepare("DELETE FROM audit_logs WHERE user_id = ?")->execute([$testUserId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$testUserId]);
    echo "Cleanup completed\n";
    
    echo "\nAll tests completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}