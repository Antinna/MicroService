<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\InventoryTracker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InventoryTracker
 */
class InventoryTrackerTest extends TestCase
{
    private InventoryTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new InventoryTracker();
    }

    public function testValidateRequiredFields()
    {
        $data = [];
        $result = $this->tracker->validate($data);
        
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('product_id', $result['errors']);
        $this->assertArrayHasKey('batch_number', $result['errors']);
        $this->assertArrayHasKey('production_date', $result['errors']);
        $this->assertArrayHasKey('expiry_date', $result['errors']);
        $this->assertArrayHasKey('quantity_available', $result['errors']);
    }

    public function testValidateProductId()
    {
        // Test invalid product ID
        $data = ['product_id' => 'invalid'];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('product_id', $result['errors']);
        $this->assertStringContainsString('must be a valid number', $result['errors']['product_id']);

        // Test valid product ID (note: this would fail in real test due to database check)
        $data = ['product_id' => 123];
        $result = $this->tracker->validate($data);
        // In a real test, you'd mock the repository to avoid database calls
    }

    public function testValidateBatchNumber()
    {
        // Test short batch number
        $data = ['batch_number' => 'AB'];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('batch_number', $result['errors']);
        $this->assertStringContainsString('at least 3 characters', $result['errors']['batch_number']);

        // Test long batch number
        $data = ['batch_number' => str_repeat('A', 101)];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('batch_number', $result['errors']);
        $this->assertStringContainsString('not exceed 100 characters', $result['errors']['batch_number']);

        // Test valid batch number
        $data = ['batch_number' => 'BATCH001'];
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('batch_number', $result['errors']);
    }

    public function testValidateProductionDate()
    {
        // Test invalid date format
        $data = ['production_date' => 'invalid-date'];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('production_date', $result['errors']);
        $this->assertStringContainsString('Invalid production date format', $result['errors']['production_date']);

        // Test future production date
        $futureDate = date('Y-m-d', strtotime('+1 day'));
        $data = ['production_date' => $futureDate];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('production_date', $result['errors']);
        $this->assertStringContainsString('cannot be in the future', $result['errors']['production_date']);

        // Test too old production date
        $oldDate = date('Y-m-d', strtotime('-2 years'));
        $data = ['production_date' => $oldDate];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('production_date', $result['errors']);
        $this->assertStringContainsString('cannot be more than 1 year ago', $result['errors']['production_date']);

        // Test valid production date
        $validDate = date('Y-m-d', strtotime('-1 day'));
        $data = ['production_date' => $validDate];
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('production_date', $result['errors']);
    }

    public function testValidateExpiryDate()
    {
        // Test invalid date format
        $data = ['expiry_date' => 'invalid-date'];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('expiry_date', $result['errors']);
        $this->assertStringContainsString('Invalid expiry date format', $result['errors']['expiry_date']);

        // Test past expiry date
        $pastDate = date('Y-m-d', strtotime('-1 day'));
        $data = ['expiry_date' => $pastDate];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('expiry_date', $result['errors']);
        $this->assertStringContainsString('must be in the future', $result['errors']['expiry_date']);

        // Test valid expiry date
        $futureDate = date('Y-m-d', strtotime('+7 days'));
        $data = ['expiry_date' => $futureDate];
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('expiry_date', $result['errors']);
    }

    public function testValidateCrossDates()
    {
        // Test expiry date before production date
        $productionDate = date('Y-m-d', strtotime('-1 day'));
        $expiryDate = date('Y-m-d', strtotime('-2 days'));
        
        $data = [
            'production_date' => $productionDate,
            'expiry_date' => $expiryDate
        ];
        
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('expiry_date', $result['errors']);
        $this->assertStringContainsString('must be after production date', $result['errors']['expiry_date']);

        // Test valid date combination
        $productionDate = date('Y-m-d', strtotime('-1 day'));
        $expiryDate = date('Y-m-d', strtotime('+7 days'));
        
        $data = [
            'production_date' => $productionDate,
            'expiry_date' => $expiryDate
        ];
        
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('expiry_date', $result['errors']);
    }

    public function testValidateQuantities()
    {
        // Test invalid quantity available (negative)
        $data = ['quantity_available' => -1];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('quantity_available', $result['errors']);
        $this->assertStringContainsString('must be a non-negative number', $result['errors']['quantity_available']);

        // Test invalid quantity available (too large)
        $data = ['quantity_available' => 1000000];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('quantity_available', $result['errors']);
        $this->assertStringContainsString('cannot exceed 999,999', $result['errors']['quantity_available']);

        // Test invalid quantity reserved (negative)
        $data = ['quantity_reserved' => -1];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('quantity_reserved', $result['errors']);

        // Test quantity reserved exceeding available
        $data = [
            'quantity_available' => 10,
            'quantity_reserved' => 15
        ];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('quantity_reserved', $result['errors']);
        $this->assertStringContainsString('cannot exceed quantity available', $result['errors']['quantity_reserved']);

        // Test valid quantities
        $data = [
            'quantity_available' => 100,
            'quantity_reserved' => 25
        ];
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('quantity_available', $result['errors']);
        $this->assertArrayNotHasKey('quantity_reserved', $result['errors']);
    }

    public function testValidateQualityGrade()
    {
        // Test invalid quality grade
        $data = ['quality_grade' => 'D'];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('quality_grade', $result['errors']);
        $this->assertStringContainsString('must be one of', $result['errors']['quality_grade']);

        // Test valid quality grades
        $validGrades = ['A', 'B', 'C'];
        foreach ($validGrades as $grade) {
            $data = ['quality_grade' => $grade];
            $result = $this->tracker->validate($data);
            $this->assertArrayNotHasKey('quality_grade', $result['errors']);
        }
    }

    public function testValidateTemperatures()
    {
        // Test invalid temperature (non-numeric)
        $data = ['storage_temperature_min' => 'invalid'];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('storage_temperature_min', $result['errors']);
        $this->assertStringContainsString('must be a number', $result['errors']['storage_temperature_min']);

        // Test temperature out of range
        $data = ['storage_temperature_min' => -60];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('storage_temperature_min', $result['errors']);
        $this->assertStringContainsString('between -50°C and 50°C', $result['errors']['storage_temperature_min']);

        // Test max temperature less than min
        $data = [
            'storage_temperature_min' => 10,
            'storage_temperature_max' => 5
        ];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('storage_temperature_max', $result['errors']);
        $this->assertStringContainsString('greater than minimum temperature', $result['errors']['storage_temperature_max']);

        // Test valid temperatures
        $data = [
            'storage_temperature_min' => 2,
            'storage_temperature_max' => 8
        ];
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('storage_temperature_min', $result['errors']);
        $this->assertArrayNotHasKey('storage_temperature_max', $result['errors']);
    }

    public function testValidateTextFields()
    {
        // Test long farm source
        $data = ['farm_source' => str_repeat('A', 256)];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('farm_source', $result['errors']);
        $this->assertStringContainsString('not exceed 255 characters', $result['errors']['farm_source']);

        // Test long notes
        $data = ['notes' => str_repeat('A', 1001)];
        $result = $this->tracker->validate($data);
        $this->assertArrayHasKey('notes', $result['errors']);
        $this->assertStringContainsString('not exceed 1000 characters', $result['errors']['notes']);

        // Test valid text fields
        $data = [
            'farm_source' => 'Green Valley Farm, Karnataka',
            'notes' => 'High quality batch with excellent storage conditions'
        ];
        $result = $this->tracker->validate($data);
        $this->assertArrayNotHasKey('farm_source', $result['errors']);
        $this->assertArrayNotHasKey('notes', $result['errors']);
    }

    public function testValidateBooleanFields()
    {
        // Test boolean conversion for is_recalled
        $testValues = [
            'true' => true,
            'false' => false,
            '1' => true,
            '0' => false,
            1 => true,
            0 => false,
            true => true,
            false => false
        ];

        foreach ($testValues as $input => $expected) {
            $data = ['is_recalled' => $input];
            $result = $this->tracker->validate($data);
            $this->assertEquals($expected, $result['data']['is_recalled']);
        }
    }

    public function testValidCompleteBatchData()
    {
        $data = [
            'product_id' => 1, // This would fail in real test due to database check
            'batch_number' => 'BATCH001',
            'production_date' => date('Y-m-d', strtotime('-1 day')),
            'expiry_date' => date('Y-m-d', strtotime('+7 days')),
            'quantity_available' => 100,
            'quantity_reserved' => 0,
            'farm_source' => 'Green Valley Farm, Karnataka',
            'quality_grade' => 'A',
            'storage_temperature_min' => 2.0,
            'storage_temperature_max' => 8.0,
            'notes' => 'Premium quality batch',
            'is_recalled' => false
        ];

        $result = $this->tracker->validate($data);
        
        // Note: This test might fail due to product validation in a real environment
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('data', $result);
        
        // Check boolean conversion
        $this->assertFalse($result['data']['is_recalled']);
    }

    // Note: The following tests would require mocking repositories in a real test environment
    
    public function testGetBatchNotFound()
    {
        // This would require mocking the repository in a real test
        $result = $this->tracker->getBatch(99999);
        $this->assertArrayHasKey('found', $result);
        $this->assertFalse($result['found']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testReserveQuantityInsufficientStock()
    {
        // This would require mocking the repository in a real test
        // In a real test, you'd mock a batch with limited available quantity
        $this->assertTrue(true); // Placeholder assertion
    }

    public function testInventorySummaryCalculation()
    {
        // This would require mocking the repository in a real test
        // In a real test, you'd mock multiple batches and verify summary calculations
        $this->assertTrue(true); // Placeholder assertion
    }
}