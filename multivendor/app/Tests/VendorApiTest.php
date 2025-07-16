<?php

namespace Antinna\MultiVendor\Tests;

use PHPUnit\Framework\TestCase;

class VendorApiTest extends TestCase
{
    private string $baseUrl = 'http://localhost/api/vendors';
    private array $testVendorData;
    private ?int $testVendorId = null;

    protected function setUp(): void
    {
        $this->testVendorData = [
            'business_name' => 'Test Dairy Farm',
            'business_type' => 'dairy',
            'contact_person' => 'John Doe',
            'email' => 'john@testdairy.com',
            'phone' => '9876543210',
            'address' => '123 Farm Road',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001'
        ];
    }

    protected function tearDown(): void
    {
        // Clean up test data if vendor was created
        if ($this->testVendorId) {
            $this->deleteTestVendor($this->testVendorId);
        }
    }

    public function testVendorRegistrationSuccess()
    {
        $response = $this->makeRequest('POST', '/register', $this->testVendorData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('vendor_id', $response['data']);
        $this->assertArrayHasKey('registration_status', $response['data']);
        
        // Store vendor ID for cleanup
        $this->testVendorId = $response['data']['vendor_id'];
    }

    public function testVendorRegistrationWithMissingFields()
    {
        $incompleteData = [
            'business_name' => 'Test Farm',
            // Missing required fields
        ];

        $response = $this->makeRequest('POST', '/register', $incompleteData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('details', $response['data']);
    }

    public function testVendorRegistrationWithInvalidEmail()
    {
        $invalidData = $this->testVendorData;
        $invalidData['email'] = 'invalid-email';

        $response = $this->makeRequest('POST', '/register', $invalidData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
    }

    public function testGetVendorProfile()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        // Get vendor profile
        $response = $this->makeRequest('GET', "/{$this->testVendorId}");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('vendor', $response['data']);
        $this->assertEquals($this->testVendorData['business_name'], $response['data']['vendor']['business_name']);
    }

    public function testGetVendorProfileNotFound()
    {
        $response = $this->makeRequest('GET', '/99999');
        
        $this->assertEquals(404, $response['status_code']);
        $this->assertFalse($response['data']['success']);
    }

    public function testUpdateVendorProfile()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        // Update vendor profile
        $updateData = [
            'business_name' => 'Updated Dairy Farm',
            'contact_person' => 'Jane Doe'
        ];

        $response = $this->makeRequest('PUT', "/{$this->testVendorId}", $updateData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('updated_fields', $response['data']);
    }

    public function testGetVendorsWithPagination()
    {
        $response = $this->makeRequest('GET', '/?page=1&limit=10');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('vendors', $response['data']);
        $this->assertArrayHasKey('pagination', $response['data']);
        $this->assertArrayHasKey('total', $response['data']['pagination']);
        $this->assertArrayHasKey('page', $response['data']['pagination']);
        $this->assertArrayHasKey('limit', $response['data']['pagination']);
    }

    public function testGetVendorsWithFilters()
    {
        $response = $this->makeRequest('GET', '/?status=active&business_type=dairy');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('vendors', $response['data']);
        $this->assertArrayHasKey('filters_applied', $response['data']);
    }

    public function testGetVendorsWithSearch()
    {
        $response = $this->makeRequest('GET', '/?search=dairy');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('vendors', $response['data']);
    }

    public function testValidateKYCDocuments()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $kycData = [
            'documents' => [
                [
                    'document_type' => 'pan_card',
                    'document_number' => 'ABCDE1234F',
                    'document_path' => '/path/to/pan.pdf'
                ],
                [
                    'document_type' => 'aadhar_card',
                    'document_number' => '123456789012',
                    'document_path' => '/path/to/aadhar.pdf'
                ]
            ]
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/kyc/validate", $kycData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('validation_results', $response['data']);
    }

    public function testValidateKYCWithMissingDocuments()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/kyc/validate", []);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertEquals('Documents are required for KYC validation', $response['data']['error']);
    }

    public function testGetKYCStatus()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/kyc/status");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('kyc_status', $response['data']);
        $this->assertArrayHasKey('documents', $response['data']);
    }

    public function testValidateFSSAILicense()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $fssaiData = [
            'license_number' => '1234567890',
            'issue_date' => '2024-01-01',
            'document_path' => '/path/to/fssai.pdf'
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/fssai/validate", $fssaiData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('validation_result', $response['data']);
        $this->assertArrayHasKey('compliance_result', $response['data']);
    }

    public function testValidateFSSAIWithInvalidLicense()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $fssaiData = [
            'license_number' => '123', // Invalid format
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/fssai/validate", $fssaiData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['valid']);
    }

    public function testValidateFSSAIWithMissingLicense()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/fssai/validate", []);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertEquals('FSSAI license number is required', $response['data']['error']);
    }

    public function testGetComplianceStatus()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/compliance");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('compliance_status', $response['data']);
        $this->assertArrayHasKey('has_valid_fssai', $response['data']);
    }

    public function testAssignRole()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $roleData = [
            'user_id' => 123,
            'role' => 'manager',
            'permissions' => ['manage_products', 'view_orders']
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/roles", $roleData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('role_assignment_id', $response['data']);
    }

    public function testAssignRoleWithMissingData()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $roleData = [
            'user_id' => 123,
            // Missing role
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/roles", $roleData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('details', $response['data']);
    }

    public function testGetVendorRoles()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/roles");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('roles', $response['data']);
        $this->assertArrayHasKey('total_users', $response['data']);
    }

    public function testUpdateRole()
    {
        // First register a vendor and assign a role
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $roleData = [
            'user_id' => 123,
            'role' => 'staff'
        ];
        $this->makeRequest('POST', "/{$this->testVendorId}/roles", $roleData);

        // Update the role
        $updateData = [
            'role' => 'manager',
            'permissions' => ['manage_products']
        ];

        $response = $this->makeRequest('PUT', "/{$this->testVendorId}/roles/123", $updateData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
    }

    public function testRemoveRole()
    {
        // First register a vendor and assign a role
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $roleData = [
            'user_id' => 123,
            'role' => 'staff'
        ];
        $this->makeRequest('POST', "/{$this->testVendorId}/roles", $roleData);

        // Remove the role
        $response = $this->makeRequest('DELETE', "/{$this->testVendorId}/roles/123");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
    }

    public function testCheckUserPermissions()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/users/123/permissions");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('permissions', $response['data']);
    }

    public function testCheckSpecificPermission()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/users/123/permissions?permission=manage_products");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('has_permission', $response['data']);
    }

    public function testUpdateVendorStatus()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $statusData = [
            'status' => 'active',
            'reason' => 'Approved after verification'
        ];

        $response = $this->makeRequest('PATCH', "/{$this->testVendorId}/status", $statusData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('previous_status', $response['data']);
        $this->assertArrayHasKey('new_status', $response['data']);
    }

    public function testUpdateVendorStatusWithInvalidStatus()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $statusData = [
            'status' => 'invalid_status'
        ];

        $response = $this->makeRequest('PATCH', "/{$this->testVendorId}/status", $statusData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertStringContainsString('Invalid status', $response['data']['error']);
    }

    public function testGetVendorStatistics()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/stats");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('statistics', $response['data']);
        $this->assertArrayHasKey('products', $response['data']['statistics']);
        $this->assertArrayHasKey('orders', $response['data']['statistics']);
    }

    public function testUploadDocument()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $documentData = [
            'document_type' => 'business_registration',
            'document_path' => '/path/to/business_reg.pdf',
            'expiry_date' => '2025-12-31',
            'notes' => 'Business registration certificate'
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/documents", $documentData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('document_id', $response['data']);
    }

    public function testUploadDocumentWithMissingData()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $documentData = [
            'document_type' => 'business_registration',
            // Missing document_path
        ];

        $response = $this->makeRequest('POST', "/{$this->testVendorId}/documents", $documentData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['data']['success']);
        $this->assertArrayHasKey('details', $response['data']);
    }

    public function testGetVendorDocuments()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/documents");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('documents', $response['data']);
        $this->assertArrayHasKey('total_documents', $response['data']);
    }

    public function testGetVendorDocumentsByType()
    {
        // First register a vendor
        $registerResponse = $this->makeRequest('POST', '/register', $this->testVendorData);
        $this->testVendorId = $registerResponse['data']['vendor_id'];

        $response = $this->makeRequest('GET', "/{$this->testVendorId}/documents?document_type=business_registration");
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('documents', $response['data']);
        $this->assertArrayHasKey('document_type_filter', $response['data']);
    }

    /**
     * Make HTTP request to API endpoint
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status_code' => $statusCode,
            'data' => json_decode($response, true) ?? []
        ];
    }

    /**
     * Delete test vendor for cleanup
     */
    private function deleteTestVendor(int $vendorId): void
    {
        // This would typically call a delete endpoint
        // For now, we'll just mark it as deleted in the database
        // Implementation would depend on your deletion strategy
    }
}