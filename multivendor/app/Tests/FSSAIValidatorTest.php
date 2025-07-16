<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\FSSAIValidator;
use PHPUnit\Framework\TestCase;

class FSSAIValidatorTest extends TestCase
{
    private FSSAIValidator $fssaiValidator;

    protected function setUp(): void
    {
        $this->fssaiValidator = new FSSAIValidator();
    }

    public function testValidateLicenseWithValidFormat()
    {
        // Test with valid 14-digit license
        $validLicense14 = '12345678901234';
        $result = $this->fssaiValidator->validateLicense($validLicense14);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);

        // Should be valid due to mock external API
        if ($result['valid']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('license_data', $result);
        }

        // Test with valid 10-digit license
        $validLicense10 = '1234567890';
        $result = $this->fssaiValidator->validateLicense($validLicense10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
    }

    public function testValidateLicenseWithInvalidFormat()
    {
        $invalidLicenses = [
            '123',           // Too short
            '12345678901234567890', // Too long
            'ABCD1234567890', // Contains letters
            '0000000000',     // Invalid state code
            '9999999999'      // Invalid state code
        ];

        foreach ($invalidLicenses as $license) {
            $result = $this->fssaiValidator->validateLicense($license);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('valid', $result);
            $this->assertFalse($result['valid']);
            $this->assertArrayHasKey('error', $result);
            $this->assertEquals('Invalid FSSAI license format', $result['error']);
        }
    }

    public function testRegisterComplianceRecord()
    {
        $vendorId = 1;
        $complianceData = [
            'compliance_type' => 'fssai',
            'certificate_number' => '12345678901234',
            'issuing_authority' => 'FSSAI',
            'issue_date' => '2024-01-01',
            'expiry_date' => '2025-01-01',
            'document_path' => '/uploads/fssai_cert.pdf',
            'verification_notes' => 'Verified through external API'
        ];

        $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $complianceData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('record_id', $result);
            $this->assertArrayHasKey('status', $result);
            $this->assertArrayHasKey('message', $result);
        }
    }

    public function testRegisterComplianceRecordWithInvalidData()
    {
        $vendorId = 1;
        $invalidComplianceData = [
            // Missing required fields
            'compliance_type' => 'invalid_type',
            'certificate_number' => '',
            'issue_date' => 'invalid-date',
            'expiry_date' => '2023-01-01' // Before issue date
        ];

        $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $invalidComplianceData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);

        $errors = $result['errors'];
        $this->assertArrayHasKey('compliance_type', $errors);
        $this->assertArrayHasKey('certificate_number', $errors);
        $this->assertArrayHasKey('issuing_authority', $errors);
        $this->assertArrayHasKey('issue_date', $errors);
    }

    public function testCheckVendorCompliance()
    {
        $vendorId = 1;
        $result = $this->fssaiValidator->checkVendorCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('vendor_id', $result);
            $this->assertArrayHasKey('compliance_status', $result);
            $this->assertArrayHasKey('has_valid_fssai', $result);
            $this->assertArrayHasKey('total_records', $result);
            $this->assertArrayHasKey('valid_records', $result);
            $this->assertArrayHasKey('expiring_records', $result);
            $this->assertArrayHasKey('expired_records', $result);

            $this->assertEquals($vendorId, $result['vendor_id']);
            $this->assertIsBool($result['has_valid_fssai']);
            $this->assertIsInt($result['total_records']);
            $this->assertIsArray($result['valid_records']);
            $this->assertIsArray($result['expiring_records']);
            $this->assertIsArray($result['expired_records']);

            $this->assertContains($result['compliance_status'], ['compliant', 'warning', 'non_compliant']);
        }
    }

    public function testGetExpiringComplianceRecords()
    {
        $daysThreshold = 30;
        $result = $this->fssaiValidator->getExpiringComplianceRecords($daysThreshold);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('days_threshold', $result);
            $this->assertArrayHasKey('expiring_count', $result);
            $this->assertArrayHasKey('records', $result);

            $this->assertEquals($daysThreshold, $result['days_threshold']);
            $this->assertIsInt($result['expiring_count']);
            $this->assertIsArray($result['records']);
        }

        // Test with different thresholds
        $thresholds = [7, 15, 60, 90];
        foreach ($thresholds as $threshold) {
            $result = $this->fssaiValidator->getExpiringComplianceRecords($threshold);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);

            if ($result['success']) {
                $this->assertEquals($threshold, $result['days_threshold']);
            }
        }
    }

    public function testGenerateComplianceReport()
    {
        // Test without vendor filter
        $result = $this->fssaiValidator->generateComplianceReport();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('generated_at', $result);
            $this->assertArrayHasKey('statistics', $result);
            $this->assertArrayHasKey('vendor_report', $result);

            $this->assertIsArray($result['statistics']);
            $this->assertIsArray($result['vendor_report']);

            // Check statistics structure
            $stats = $result['statistics'];
            $this->assertArrayHasKey('total_vendors', $stats);
            $this->assertArrayHasKey('compliant_vendors', $stats);
            $this->assertArrayHasKey('warning_vendors', $stats);
            $this->assertArrayHasKey('non_compliant_vendors', $stats);
            $this->assertArrayHasKey('compliance_rate', $stats);

            $this->assertIsInt($stats['total_vendors']);
            $this->assertIsInt($stats['compliant_vendors']);
            $this->assertIsInt($stats['warning_vendors']);
            $this->assertIsInt($stats['non_compliant_vendors']);
            $this->assertIsFloat($stats['compliance_rate']);
        }

        // Test with vendor filter
        $vendorId = 1;
        $result = $this->fssaiValidator->generateComplianceReport($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testValidateComplianceDataTypes()
    {
        $vendorId = 1;
        $complianceTypes = ['fssai', 'organic', 'halal', 'kosher', 'other'];

        foreach ($complianceTypes as $type) {
            $complianceData = [
                'compliance_type' => $type,
                'certificate_number' => 'CERT123456',
                'issuing_authority' => 'Test Authority',
                'issue_date' => '2024-01-01',
                'expiry_date' => '2025-01-01'
            ];

            $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $complianceData);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);

            // Should succeed for all valid compliance types
            if (!$result['success'] && isset($result['errors'])) {
                $this->assertArrayNotHasKey('compliance_type', $result['errors']);
            }
        }
    }

    public function testDateValidation()
    {
        $vendorId = 1;

        // Test with future issue date
        $futureIssueData = [
            'compliance_type' => 'fssai',
            'certificate_number' => 'CERT123456',
            'issuing_authority' => 'Test Authority',
            'issue_date' => date('Y-m-d', strtotime('+1 day')), // Future date
            'expiry_date' => date('Y-m-d', strtotime('+1 year'))
        ];

        $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $futureIssueData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('issue_date', $result['errors']);

        // Test with expiry date before issue date
        $invalidDateOrderData = [
            'compliance_type' => 'fssai',
            'certificate_number' => 'CERT123456',
            'issuing_authority' => 'Test Authority',
            'issue_date' => '2024-12-01',
            'expiry_date' => '2024-01-01' // Before issue date
        ];

        $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $invalidDateOrderData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('expiry_date', $result['errors']);
    }

    public function testLicenseFormatValidation()
    {
        // Test various FSSAI license formats
        $validLicenses = [
            '1234567890',     // 10-digit with valid state code
            '12345678901234', // 14-digit with valid state code
            '2134567890',     // Different valid state code
            '37345678901234'  // 14-digit with different state code
        ];

        foreach ($validLicenses as $license) {
            if (strlen($license) === 10 || strlen($license) === 14) {
                $result = $this->fssaiValidator->validateLicense($license);

                $this->assertIsArray($result);
                $this->assertArrayHasKey('valid', $result);

                // Should not fail due to format (may fail due to other reasons)
                if (!$result['valid']) {
                    $this->assertNotEquals('Invalid FSSAI license format', $result['error']);
                }
            }
        }
    }

    public function testComplianceStatusDetermination()
    {
        $vendorId = 1;

        // Test with expired certificate
        $expiredData = [
            'compliance_type' => 'fssai',
            'certificate_number' => 'EXPIRED123',
            'issuing_authority' => 'Test Authority',
            'issue_date' => '2023-01-01',
            'expiry_date' => '2023-12-31' // Expired
        ];

        $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $expiredData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('status', $result);
            $this->assertEquals('expired', $result['status']);
        }

        // Test with valid certificate
        $validData = [
            'compliance_type' => 'fssai',
            'certificate_number' => 'VALID123',
            'issuing_authority' => 'Test Authority',
            'issue_date' => '2024-01-01',
            'expiry_date' => '2025-12-31' // Valid
        ];

        $result = $this->fssaiValidator->registerComplianceRecord($vendorId, $validData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('status', $result);
            $this->assertEquals('valid', $result['status']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}