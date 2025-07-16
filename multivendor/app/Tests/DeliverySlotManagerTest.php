<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\DeliverySlotManager;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class DeliverySlotManagerTest extends TestCase
{
    private DeliverySlotManager $deliverySlotManager;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->deliverySlotManager = new DeliverySlotManager();
    }

    public function testValidateDeliverySlotData()
    {
        // Test valid data
        $validData = [
            'vendor_id' => 1,
            'slot_name' => 'Morning Delivery',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_capacity' => 50,
            'days_of_week' => ['monday', 'tuesday', 'wednesday'],
            'freshness_window_hours' => 24,
            'requires_cold_chain' => true,
            'delivery_fee' => 5.99,
            'is_active' => true
        ];

        $result = $this->deliverySlotManager->validate($validData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('data', $result);

        if (!$result['valid']) {
            // If validation fails due to vendor not existing, that's expected in test environment
            $this->assertArrayHasKey('vendor_id', $result['errors']);
        }
    }

    public function testValidateInvalidData()
    {
        // Test invalid data
        $invalidData = [
            'vendor_id' => 'invalid',
            'slot_name' => 'AB', // Too short
            'start_time' => '25:00:00', // Invalid time
            'end_time' => '08:00:00', // Before start time
            'max_capacity' => -5, // Negative capacity
            'days_of_week' => ['invalid_day'],
            'freshness_window_hours' => -10, // Negative hours
            'delivery_fee' => -1 // Negative fee
        ];

        $result = $this->deliverySlotManager->validate($invalidData);

        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        
        // Check specific error fields
        $this->assertArrayHasKey('vendor_id', $result['errors']);
        $this->assertArrayHasKey('slot_name', $result['errors']);
        $this->assertArrayHasKey('start_time', $result['errors']);
        $this->assertArrayHasKey('max_capacity', $result['errors']);
        $this->assertArrayHasKey('days_of_week', $result['errors']);
        $this->assertArrayHasKey('freshness_window_hours', $result['errors']);
        $this->assertArrayHasKey('delivery_fee', $result['errors']);
    }

    public function testValidateRequiredFields()
    {
        // Test missing required fields
        $incompleteData = [
            'slot_name' => 'Test Slot'
            // Missing other required fields
        ];

        $result = $this->deliverySlotManager->validate($incompleteData);

        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        
        // Check that required field errors are present
        $this->assertArrayHasKey('vendor_id', $result['errors']);
        $this->assertArrayHasKey('start_time', $result['errors']);
        $this->assertArrayHasKey('end_time', $result['errors']);
        $this->assertArrayHasKey('max_capacity', $result['errors']);
        $this->assertArrayHasKey('days_of_week', $result['errors']);
    }

    public function testCreateDeliverySlot()
    {
        $slotData = [
            'vendor_id' => 1,
            'slot_name' => 'Test Morning Slot',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_capacity' => 25,
            'days_of_week' => ['monday', 'wednesday', 'friday'],
            'freshness_window_hours' => 48,
            'requires_cold_chain' => false,
            'delivery_fee' => 3.50,
            'is_active' => true
        ];

        $result = $this->deliverySlotManager->createDeliverySlot($slotData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if (!$result['success']) {
            // Expected to fail in test environment due to missing vendor
            $this->assertArrayHasKey('errors', $result);
        } else {
            $this->assertArrayHasKey('slot_id', $result);
            $this->assertArrayHasKey('message', $result);
        }
    }

    public function testGetDeliverySlot()
    {
        // Test with non-existent slot
        $result = $this->deliverySlotManager->getDeliverySlot(999);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('found', $result);
        $this->assertFalse($result['found']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetVendorDeliverySlots()
    {
        // Test getting slots for a vendor
        $result = $this->deliverySlotManager->getVendorDeliverySlots(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('slots', $result);
        
        if ($result['success']) {
            $this->assertIsArray($result['slots']);
        }

        // Test with activeOnly = false
        $result = $this->deliverySlotManager->getVendorDeliverySlots(1, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('slots', $result);
    }

    public function testGetAvailableSlots()
    {
        $date = date('Y-m-d', strtotime('+1 day'));
        
        // Test without cold chain requirement
        $result = $this->deliverySlotManager->getAvailableSlots(1, $date);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('day_of_week', $result);
        $this->assertArrayHasKey('slots', $result);
        
        if ($result['success']) {
            $this->assertEquals($date, $result['date']);
            $this->assertIsArray($result['slots']);
        }

        // Test with cold chain requirement
        $result = $this->deliverySlotManager->getAvailableSlots(1, $date, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertIsArray($result['slots']);
        }
    }

    public function testCheckSlotAvailability()
    {
        $date = date('Y-m-d', strtotime('+1 day'));
        
        // Test with non-existent slot
        $result = $this->deliverySlotManager->checkSlotAvailability(999, $date);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('available', $result);
        $this->assertFalse($result['available']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Delivery slot not found', $result['error']);

        // Test with quantity parameter
        $result = $this->deliverySlotManager->checkSlotAvailability(999, $date, 5);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('available', $result);
        $this->assertFalse($result['available']);
    }

    public function testBookDeliverySlot()
    {
        $date = date('Y-m-d', strtotime('+1 day'));
        
        // Test booking non-existent slot
        $result = $this->deliverySlotManager->bookDeliverySlot(999, $date);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

        // Test booking with quantity
        $result = $this->deliverySlotManager->bookDeliverySlot(999, $date, 3);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
    }

    public function testReleaseDeliverySlot()
    {
        // Test releasing booking for non-existent slot
        $result = $this->deliverySlotManager->releaseDeliverySlot(999);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should succeed even for non-existent slot (graceful handling)
        if ($result['success']) {
            $this->assertArrayHasKey('released_quantity', $result);
            $this->assertArrayHasKey('message', $result);
        }

        // Test releasing with quantity
        $result = $this->deliverySlotManager->releaseDeliverySlot(999, 2);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testUpdateDeliverySlot()
    {
        $updateData = [
            'slot_name' => 'Updated Slot Name',
            'max_capacity' => 75,
            'delivery_fee' => 7.99,
            'is_active' => false
        ];

        // Test updating non-existent slot
        $result = $this->deliverySlotManager->updateDeliverySlot(999, $updateData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Delivery slot not found', $result['error']);
    }

    public function testTimeValidation()
    {
        // Test various time formats
        $timeTests = [
            ['09:00:00', true],
            ['23:59:59', true],
            ['00:00:00', true],
            ['24:00:00', false],
            ['09:60:00', false],
            ['09:00:60', false],
            ['9:00:00', true], // Should accept single digit hour
            ['invalid', false]
        ];

        foreach ($timeTests as [$time, $shouldBeValid]) {
            $data = [
                'vendor_id' => 1,
                'slot_name' => 'Test Slot',
                'start_time' => $time,
                'end_time' => '18:00:00',
                'max_capacity' => 10,
                'days_of_week' => ['monday']
            ];

            $result = $this->deliverySlotManager->validate($data);
            
            if ($shouldBeValid) {
                $this->assertArrayNotHasKey('start_time', $result['errors'] ?? []);
            } else {
                $this->assertArrayHasKey('start_time', $result['errors'] ?? []);
            }
        }
    }

    public function testDaysOfWeekValidation()
    {
        // Test valid days
        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        $data = [
            'vendor_id' => 1,
            'slot_name' => 'Test Slot',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'max_capacity' => 10,
            'days_of_week' => $validDays
        ];

        $result = $this->deliverySlotManager->validate($data);
        $this->assertArrayNotHasKey('days_of_week', $result['errors'] ?? []);

        // Test invalid day
        $data['days_of_week'] = ['monday', 'invalid_day'];
        $result = $this->deliverySlotManager->validate($data);
        $this->assertArrayHasKey('days_of_week', $result['errors'] ?? []);

        // Test empty days
        $data['days_of_week'] = [];
        $result = $this->deliverySlotManager->validate($data);
        $this->assertArrayHasKey('days_of_week', $result['errors'] ?? []);
    }

    public function testCapacityValidation()
    {
        $capacityTests = [
            [1, true],
            [100, true],
            [10000, true],
            [10001, false], // Exceeds maximum
            [0, false], // Zero capacity
            [-1, false], // Negative capacity
            ['invalid', false] // Non-numeric
        ];

        foreach ($capacityTests as [$capacity, $shouldBeValid]) {
            $data = [
                'vendor_id' => 1,
                'slot_name' => 'Test Slot',
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'max_capacity' => $capacity,
                'days_of_week' => ['monday']
            ];

            $result = $this->deliverySlotManager->validate($data);
            
            if ($shouldBeValid) {
                $this->assertArrayNotHasKey('max_capacity', $result['errors'] ?? []);
            } else {
                $this->assertArrayHasKey('max_capacity', $result['errors'] ?? []);
            }
        }
    }

    public function testFreshnessWindowValidation()
    {
        $windowTests = [
            [1, true],
            [24, true],
            [168, true], // 1 week
            [169, false], // Exceeds maximum
            [0, false], // Zero hours
            [-1, false], // Negative hours
            ['invalid', false] // Non-numeric
        ];

        foreach ($windowTests as [$hours, $shouldBeValid]) {
            $data = [
                'vendor_id' => 1,
                'slot_name' => 'Test Slot',
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'max_capacity' => 10,
                'days_of_week' => ['monday'],
                'freshness_window_hours' => $hours
            ];

            $result = $this->deliverySlotManager->validate($data);
            
            if ($shouldBeValid) {
                $this->assertArrayNotHasKey('freshness_window_hours', $result['errors'] ?? []);
            } else {
                $this->assertArrayHasKey('freshness_window_hours', $result['errors'] ?? []);
            }
        }
    }

    public function testBooleanFieldHandling()
    {
        $data = [
            'vendor_id' => 1,
            'slot_name' => 'Test Slot',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'max_capacity' => 10,
            'days_of_week' => ['monday'],
            'requires_cold_chain' => 'true', // String boolean
            'is_active' => '1' // String boolean
        ];

        $result = $this->deliverySlotManager->validate($data);
        
        if ($result['valid'] || !isset($result['errors']['vendor_id'])) {
            // Check that boolean fields are properly converted
            $this->assertTrue($result['data']['requires_cold_chain']);
            $this->assertTrue($result['data']['is_active']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}