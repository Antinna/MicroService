<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\VendorProfileManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VendorProfileManager
 */
class VendorProfileManagerTest extends TestCase
{
    private VendorProfileManager $manager;

    protected function setUp(): void
    {
        $this->manager = new VendorProfileManager();
    }

    public function testValidateBusinessName()
    {
        // Test empty business name
        $data = ['business_name' => ''];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('business_name', $result['errors']);
        $this->assertStringContainsString('cannot be empty', $result['errors']['business_name']);

        // Test short business name
        $data = ['business_name' => 'AB'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('business_name', $result['errors']);
        $this->assertStringContainsString('at least 3 characters', $result['errors']['business_name']);

        // Test long business name
        $data = ['business_name' => str_repeat('A', 256)];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('business_name', $result['errors']);
        $this->assertStringContainsString('not exceed 255 characters', $result['errors']['business_name']);

        // Test valid business name
        $data = ['business_name' => 'Valid Business Name'];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('business_name', $result['errors']);
    }

    public function testValidateBusinessType()
    {
        // Test invalid business type
        $data = ['business_type' => 'invalid_type'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('business_type', $result['errors']);
        $this->assertStringContainsString('must be one of', $result['errors']['business_type']);

        // Test valid business types
        $validTypes = ['dairy', 'vegetables', 'mixed'];
        foreach ($validTypes as $type) {
            $data = ['business_type' => $type];
            $result = $this->manager->validate($data);
            $this->assertArrayNotHasKey('business_type', $result['errors']);
        }
    }

    public function testValidateEmail()
    {
        // Test empty email
        $data = ['email' => ''];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertStringContainsString('cannot be empty', $result['errors']['email']);

        // Test invalid email
        $data = ['email' => 'invalid-email'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertStringContainsString('Invalid email format', $result['errors']['email']);

        // Test valid email
        $data = ['email' => 'test@example.com'];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('email', $result['errors']);
    }

    public function testValidatePhone()
    {
        // Test empty phone
        $data = ['phone' => ''];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertStringContainsString('cannot be empty', $result['errors']['phone']);

        // Test invalid phone
        $data = ['phone' => '123'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertStringContainsString('Invalid phone number format', $result['errors']['phone']);

        // Test valid phone
        $data = ['phone' => '+91 9876543210'];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('phone', $result['errors']);
    }

    public function testValidateAddress()
    {
        // Test empty address
        $data = ['address' => ''];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('address', $result['errors']);
        $this->assertStringContainsString('cannot be empty', $result['errors']['address']);

        // Test short address
        $data = ['address' => '123 St'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('address', $result['errors']);
        $this->assertStringContainsString('at least 10 characters', $result['errors']['address']);

        // Test valid address
        $data = ['address' => '123 Main Street, City, State'];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('address', $result['errors']);
    }

    public function testValidateLocation()
    {
        // Test incomplete location (only latitude)
        $data = ['latitude' => 12.9716];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('location', $result['errors']);

        // Test incomplete location (only longitude)
        $data = ['longitude' => 77.5946];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('location', $result['errors']);

        // Test invalid latitude
        $data = ['latitude' => 91, 'longitude' => 77.5946];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('latitude', $result['errors']);

        // Test invalid longitude
        $data = ['latitude' => 12.9716, 'longitude' => 181];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('longitude', $result['errors']);

        // Test valid location
        $data = ['latitude' => 12.9716, 'longitude' => 77.5946];
        $result = $this->manager->validate($data);
        $this->assertArrayNotHasKey('location', $result['errors']);
        $this->assertArrayNotHasKey('latitude', $result['errors']);
        $this->assertArrayNotHasKey('longitude', $result['errors']);
    }

    public function testValidateStatus()
    {
        // Test invalid status
        $data = ['status' => 'invalid_status'];
        $result = $this->manager->validate($data);
        $this->assertArrayHasKey('status', $result['errors']);
        $this->assertStringContainsString('Invalid status', $result['errors']['status']);

        // Test valid statuses
        $validStatuses = ['pending', 'active', 'suspended'];
        foreach ($validStatuses as $status) {
            $data = ['status' => $status];
            $result = $this->manager->validate($data);
            $this->assertArrayNotHasKey('status', $result['errors']);
        }
    }

    public function testValidateColdChainCapable()
    {
        // Test boolean conversion
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
            $data = ['cold_chain_capable' => $input];
            $result = $this->manager->validate($data);
            $this->assertEquals($expected, $result['data']['cold_chain_capable']);
        }
    }

    public function testValidCompleteUpdateData()
    {
        $data = [
            'business_name' => 'Updated Fresh Dairy Farm',
            'business_type' => 'mixed',
            'owner_name' => 'Jane Doe',
            'phone' => '+91 9876543211',
            'email' => 'jane@freshdairy.com',
            'address' => '456 Updated Farm Road, New Village, District, State',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'cold_chain_capable' => true,
            'status' => 'active'
        ];

        $result = $this->manager->validate($data);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals($data['business_name'], $result['data']['business_name']);
        $this->assertEquals($data['business_type'], $result['data']['business_type']);
        $this->assertTrue($result['data']['cold_chain_capable']);
    }

    public function testGetProfileNotFound()
    {
        // This would require mocking the repository in a real test
        $result = $this->manager->getProfile(99999);
        $this->assertArrayHasKey('found', $result);
        $this->assertFalse($result['found']);
        $this->assertArrayHasKey('error', $result);
    }
}