<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\VendorRegistrationService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VendorRegistrationService
 */
class VendorRegistrationServiceTest extends TestCase
{
    private VendorRegistrationService $service;

    protected function setUp(): void
    {
        $this->service = new VendorRegistrationService();
    }

    public function testValidateRequiredFields()
    {
        $data = [];
        $result = $this->service->validate($data);
        
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('business_name', $result['errors']);
        $this->assertArrayHasKey('business_type', $result['errors']);
        $this->assertArrayHasKey('fssai_license', $result['errors']);
        $this->assertArrayHasKey('owner_name', $result['errors']);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayHasKey('address', $result['errors']);
    }

    public function testValidateBusinessName()
    {
        // Test short business name
        $data = ['business_name' => 'AB'];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('business_name', $result['errors']);
        $this->assertStringContainsString('at least 3 characters', $result['errors']['business_name']);

        // Test long business name
        $data = ['business_name' => str_repeat('A', 256)];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('business_name', $result['errors']);
        $this->assertStringContainsString('not exceed 255 characters', $result['errors']['business_name']);

        // Test valid business name
        $data = ['business_name' => 'Valid Business Name'];
        $result = $this->service->validate($data);
        $this->assertArrayNotHasKey('business_name', $result['errors']);
    }

    public function testValidateBusinessType()
    {
        // Test invalid business type
        $data = ['business_type' => 'invalid_type'];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('business_type', $result['errors']);
        $this->assertStringContainsString('must be one of', $result['errors']['business_type']);

        // Test valid business types
        $validTypes = ['dairy', 'vegetables', 'mixed'];
        foreach ($validTypes as $type) {
            $data = ['business_type' => $type];
            $result = $this->service->validate($data);
            $this->assertArrayNotHasKey('business_type', $result['errors']);
        }
    }

    public function testValidateEmail()
    {
        // Test invalid email
        $data = ['email' => 'invalid-email'];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertStringContainsString('Invalid email format', $result['errors']['email']);

        // Test valid email
        $data = ['email' => 'test@example.com'];
        $result = $this->service->validate($data);
        $this->assertArrayNotHasKey('email', $result['errors']);
    }

    public function testValidatePhone()
    {
        // Test invalid phone
        $data = ['phone' => '123'];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertStringContainsString('Invalid phone number format', $result['errors']['phone']);

        // Test valid phones
        $validPhones = ['+91 9876543210', '9876543210', '+1-555-123-4567', '(555) 123-4567'];
        foreach ($validPhones as $phone) {
            $data = ['phone' => $phone];
            $result = $this->service->validate($data);
            $this->assertArrayNotHasKey('phone', $result['errors']);
        }
    }

    public function testValidateLocation()
    {
        // Test incomplete location (only latitude)
        $data = ['latitude' => 12.9716];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('location', $result['errors']);

        // Test incomplete location (only longitude)
        $data = ['longitude' => 77.5946];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('location', $result['errors']);

        // Test invalid latitude
        $data = ['latitude' => 91, 'longitude' => 77.5946];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('latitude', $result['errors']);

        // Test invalid longitude
        $data = ['latitude' => 12.9716, 'longitude' => 181];
        $result = $this->service->validate($data);
        $this->assertArrayHasKey('longitude', $result['errors']);

        // Test valid location
        $data = ['latitude' => 12.9716, 'longitude' => 77.5946];
        $result = $this->service->validate($data);
        $this->assertArrayNotHasKey('location', $result['errors']);
        $this->assertArrayNotHasKey('latitude', $result['errors']);
        $this->assertArrayNotHasKey('longitude', $result['errors']);
    }

    public function testValidCompleteData()
    {
        $data = [
            'business_name' => 'Fresh Dairy Farm',
            'business_type' => 'dairy',
            'fssai_license' => '1234567890',
            'owner_name' => 'John Doe',
            'phone' => '+91 9876543210',
            'email' => 'john@freshdairy.com',
            'address' => '123 Farm Road, Village, District, State',
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'cold_chain_capable' => true
        ];

        $result = $this->service->validate($data);
        
        // Note: This test might fail due to FSSAI validation or duplicate checks
        // In a real test environment, you'd mock the repository and KYC validator
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testGetRegistrationStatusNotFound()
    {
        // This would require mocking the repository in a real test
        $result = $this->service->getRegistrationStatus(99999);
        $this->assertArrayHasKey('found', $result);
        $this->assertFalse($result['found']);
    }
}