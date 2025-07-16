<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\KYCValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for KYCValidator
 */
class KYCValidatorTest extends TestCase
{
    private KYCValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new KYCValidator();
    }

    public function testValidateFSSAILicense()
    {
        // Test valid 10-digit FSSAI license
        $this->assertTrue($this->validator->validateFSSAILicense('1234567890'));
        $this->assertTrue($this->validator->validateFSSAILicense('10123456789'));

        // Test valid 14-digit FSSAI license
        $this->assertTrue($this->validator->validateFSSAILicense('12345678901234'));
        $this->assertTrue($this->validator->validateFSSAILicense('10123456789012'));

        // Test invalid formats
        $this->assertFalse($this->validator->validateFSSAILicense('123'));
        $this->assertFalse($this->validator->validateFSSAILicense('12345'));
        $this->assertFalse($this->validator->validateFSSAILicense('123456789012345'));
        $this->assertFalse($this->validator->validateFSSAILicense('abcd567890'));

        // Test with spaces (should be handled)
        $this->assertTrue($this->validator->validateFSSAILicense('12 34 56 78 90'));
        $this->assertTrue($this->validator->validateFSSAILicense('12 34 56 78 90 12 34'));
    }

    public function testValidateDocumentRequiredFields()
    {
        // Test empty document
        $document = [];
        $result = $this->validator->validateDocument($document);
        
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('type', $result['errors']);
        $this->assertArrayHasKey('content', $result['errors']);
    }

    public function testValidateDocumentType()
    {
        // Test invalid document type
        $document = ['type' => 'invalid_type'];
        $result = $this->validator->validateDocument($document);
        
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('type', $result['errors']);
        $this->assertStringContainsString('Invalid document type', $result['errors']['type']);

        // Test valid document types
        $validTypes = ['kyc', 'fssai', 'business_license', 'tax_certificate'];
        foreach ($validTypes as $type) {
            $document = ['type' => $type, 'number' => '1234567890'];
            $result = $this->validator->validateDocument($document);
            $this->assertArrayNotHasKey('type', $result['errors']);
        }
    }

    public function testValidateDocumentFSSAI()
    {
        // Test valid FSSAI document
        $document = [
            'type' => 'fssai',
            'number' => '1234567890'
        ];
        $result = $this->validator->validateDocument($document);
        $this->assertArrayNotHasKey('number', $result['errors']);

        // Test invalid FSSAI document
        $document = [
            'type' => 'fssai',
            'number' => '123'
        ];
        $result = $this->validator->validateDocument($document);
        $this->assertArrayHasKey('number', $result['errors']);
    }

    public function testValidateDocumentBusinessLicense()
    {
        // Test valid business license
        $document = [
            'type' => 'business_license',
            'number' => '12345678'
        ];
        $result = $this->validator->validateDocument($document);
        $this->assertArrayNotHasKey('number', $result['errors']);

        // Test invalid business license (too short)
        $document = [
            'type' => 'business_license',
            'number' => '123'
        ];
        $result = $this->validator->validateDocument($document);
        $this->assertArrayHasKey('number', $result['errors']);
    }

    public function testGetDocumentRequirements()
    {
        // Test dairy business requirements
        $requirements = $this->validator->getDocumentRequirements('dairy');
        $this->assertArrayHasKey('fssai', $requirements);
        $this->assertArrayHasKey('business_license', $requirements);
        $this->assertArrayHasKey('dairy_license', $requirements);
        $this->assertTrue($requirements['dairy_license']['required']);

        // Test vegetables business requirements
        $requirements = $this->validator->getDocumentRequirements('vegetables');
        $this->assertArrayHasKey('fssai', $requirements);
        $this->assertArrayHasKey('business_license', $requirements);
        $this->assertArrayHasKey('agriculture_license', $requirements);
        $this->assertFalse($requirements['agriculture_license']['required']);

        // Test mixed business requirements
        $requirements = $this->validator->getDocumentRequirements('mixed');
        $this->assertArrayHasKey('fssai', $requirements);
        $this->assertArrayHasKey('business_license', $requirements);
        $this->assertArrayHasKey('dairy_license', $requirements);
        $this->assertArrayHasKey('agriculture_license', $requirements);
        $this->assertTrue($requirements['dairy_license']['required']);
        $this->assertFalse($requirements['agriculture_license']['required']);
    }

    public function testVerifyFSSAILicenseOnline()
    {
        // Test mock verification
        $result = $this->validator->verifyFSSAILicenseOnline('1234567890');
        
        $this->assertArrayHasKey('verified', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('business_name', $result);
        $this->assertArrayHasKey('license_type', $result);
        $this->assertArrayHasKey('valid_until', $result);
        $this->assertArrayHasKey('message', $result);
        
        $this->assertTrue($result['verified']);
        $this->assertEquals('active', $result['status']);
    }
}