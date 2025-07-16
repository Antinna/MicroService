<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\FSSAIValidator;
use PHPUnit\Framework\TestCase;

class FSSAIValidatorTest extends TestCase
{
    private FSSAIValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FSSAIValidator();
    }

    public function testValidateLicenseWithValidFormat()
    {
        // Test valid 10-digit license
        $result = $this->validator->validateLicense('1234567890');
        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('license_data', $result);
    }

    public function testValidateLicenseWithInvalidFormat()
    {
        // Test invalid format
        $result = $this->validator->validateLicense('123');
        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid FSSAI license format', $result['error']);
    }

    public function testValidateLicenseWith14DigitFormat()
    {
        // Test valid 14-digit license
        $result = $this->validator->validateLicense('12345678901234');
        $this->assertTrue($result['valid']);
    }

    public function testRegisterComplianceRecordSuccess()
    {
        $complianceData = [
            'compliance_type' => 'fssai',
            'certificate_number' => '1234567890',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2024-01-01',
            'expiry_date' => '2025-01-01',
            'document_path' => '/path/to/document.pdf',
            'verification_notes' => 'Test compliance record'
        ];

        $result = $this->validator->registerComplianceRecord(1, $complianceData);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('record_id', $result);
    }

    public function testRegisterComplianceRecordWithMissingData()
    {
        $complianceData = [
            'compliance_type' => 'fssai',
            // Missing required fields
        ];

        $result = $this->validator->registerComplianceRecord(1, $complianceData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testRegisterComplianceRecordWithInvalidDates()
    {
        $complianceData = [
            'compliance_type' => 'fssai',
            'certificate_number' => '1234567890',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2025-01-01', // Future date
            'expiry_date' => '2024-01-01'  // Before issue date
        ];

        $result = $this->validator->registerComplianceRecord(1, $complianceData);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('issue_date', $result['errors']);
        $this->assertArrayHasKey('expiry_date', $result['errors']);
    }

    public function testCheckVendorComplianceWithValidFSSAI()
    {
        // First register a compliance record
        $complianceData = [
            'compliance_type' => 'fssai',
            'certificate_number' => '1234567890',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2024-01-01',
            'expiry_date' => '2025-01-01'
        ];

        $this->validator->registerComplianceRecord(1, $complianceData);

        // Check compliance
        $result = $this->validator->checkVendorCompliance(1);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['has_valid_fssai']);
        $this->assertEquals('compliant', $result['compliance_status']);
    }

    public function testCheckVendorComplianceWithoutFSSAI()
    {
        $result = $this->validator->checkVendorCompliance(999); // Non-existent vendor
        $this->assertTrue($result['success']);
        $this->assertFalse($result['has_valid_fssai']);
        $this->assertEquals('non_compliant', $result['compliance_status']);
    }

    public function testGetExpiringComplianceRecords()
    {
        // Register a compliance record expiring soon
        $complianceData = [
            'compliance_type' => 'fssai',
            'certificate_number' => '1234567890',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2024-01-01',
            'expiry_date' => date('Y-m-d', strtotime('+15 days'))
        ];

        $this->validator->registerComplianceRecord(1, $complianceData);

        $result = $this->validator->getExpiringComplianceRecords(30);
        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(1, $result['expiring_count']);
    }

    public function testGenerateComplianceReport()
    {
        $result = $this->validator->generateComplianceReport();
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('vendor_report', $result);
        $this->assertArrayHasKey('total_vendors', $result['statistics']);
        $this->assertArrayHasKey('compliance_rate', $result['statistics']);
    }

    public function testGenerateComplianceReportForSpecificVendor()
    {
        $result = $this->validator->generateComplianceReport(1);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('vendor_report', $result);
    }

    public function testValidateComplianceDataWithValidData()
    {
        $data = [
            'compliance_type' => 'fssai',
            'certificate_number' => '1234567890',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2024-01-01',
            'expiry_date' => '2025-01-01'
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('validateComplianceData');
        $method->setAccessible(true);

        $result = $method->invoke($this->validator, $data);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateComplianceDataWithInvalidType()
    {
        $data = [
            'compliance_type' => 'invalid_type',
            'certificate_number' => '1234567890',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2024-01-01',
            'expiry_date' => '2025-01-01'
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('validateComplianceData');
        $method->setAccessible(true);

        $result = $method->invoke($this->validator, $data);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('compliance_type', $result['errors']);
    }

    public function testIsValidLicenseFormatWith10Digits()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('isValidLicenseFormat');
        $method->setAccessible(true);

        // Valid 10-digit format with valid state code
        $result = $method->invoke($this->validator, '1234567890');
        $this->assertTrue($result);

        // Invalid state code
        $result = $method->invoke($this->validator, '0034567890');
        $this->assertFalse($result);
    }

    public function testIsValidLicenseFormatWith14Digits()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('isValidLicenseFormat');
        $method->setAccessible(true);

        // Valid 14-digit format with valid state code
        $result = $method->invoke($this->validator, '12345678901234');
        $this->assertTrue($result);

        // Invalid state code
        $result = $method->invoke($this->validator, '00345678901234');
        $this->assertFalse($result);
    }

    public function testIsValidLicenseFormatWithInvalidLength()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('isValidLicenseFormat');
        $method->setAccessible(true);

        // Too short
        $result = $method->invoke($this->validator, '123456789');
        $this->assertFalse($result);

        // Too long
        $result = $method->invoke($this->validator, '123456789012345');
        $this->assertFalse($result);

        // Contains letters
        $result = $method->invoke($this->validator, '123456789A');
        $this->assertFalse($result);
    }

    public function testDetermineComplianceStatusWithValidDate()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('determineComplianceStatus');
        $method->setAccessible(true);

        // Future date should be valid
        $futureDate = date('Y-m-d', strtotime('+1 year'));
        $result = $method->invoke($this->validator, $futureDate);
        $this->assertEquals('valid', $result);
    }

    public function testDetermineComplianceStatusWithExpiredDate()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('determineComplianceStatus');
        $method->setAccessible(true);

        // Past date should be expired
        $pastDate = date('Y-m-d', strtotime('-1 year'));
        $result = $method->invoke($this->validator, $pastDate);
        $this->assertEquals('expired', $result);
    }

    public function testIsValidDateFormat()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('isValidDate');
        $method->setAccessible(true);

        // Valid date
        $result = $method->invoke($this->validator, '2024-01-01');
        $this->assertTrue($result);

        // Invalid date format
        $result = $method->invoke($this->validator, '01-01-2024');
        $this->assertFalse($result);

        // Invalid date
        $result = $method->invoke($this->validator, '2024-13-01');
        $this->assertFalse($result);

        // Non-existent date
        $result = $method->invoke($this->validator, '2024-02-30');
        $this->assertFalse($result);
    }
}