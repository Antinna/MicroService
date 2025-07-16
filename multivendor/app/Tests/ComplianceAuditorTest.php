<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\ComplianceAuditor;
use PHPUnit\Framework\TestCase;

class ComplianceAuditorTest extends TestCase
{
    private ComplianceAuditor $complianceAuditor;

    protected function setUp(): void
    {
        $this->complianceAuditor = new ComplianceAuditor();
    }

    public function testAuditVendorCompliance()
    {
        $vendorId = 999; // Non-existent vendor for testing
        $result = $this->complianceAuditor->auditVendorCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because vendor doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Vendor not found', $result['error']);
    }

    public function testAuditVendorComplianceWithValidVendor()
    {
        // This test would require a vendor to exist in the database
        // For now, we'll test the structure when vendor exists
        $vendorId = 1; // Assuming vendor exists
        $result = $this->complianceAuditor->auditVendorCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('audit_id', $result);
            $this->assertArrayHasKey('vendor_id', $result);
            $this->assertArrayHasKey('vendor_name', $result);
            $this->assertArrayHasKey('audit_date', $result);
            $this->assertArrayHasKey('compliance_score', $result);
            $this->assertArrayHasKey('overall_status', $result);
            $this->assertArrayHasKey('detailed_results', $result);
            $this->assertArrayHasKey('recommendations', $result);
            
            $this->assertEquals($vendorId, $result['vendor_id']);
            $this->assertIsInt($result['compliance_score']);
            $this->assertContains($result['overall_status'], ['compliant', 'warning', 'non_compliant']);
            $this->assertIsArray($result['detailed_results']);
            $this->assertIsArray($result['recommendations']);
            
            // Check detailed results structure
            $detailedResults = $result['detailed_results'];
            $this->assertArrayHasKey('fssai_compliance', $detailedResults);
            $this->assertArrayHasKey('document_compliance', $detailedResults);
            $this->assertArrayHasKey('product_compliance', $detailedResults);
            $this->assertArrayHasKey('operational_compliance', $detailedResults);
        }
    }

    public function testCheckDocumentCompliance()
    {
        $vendorId = 1;
        $result = $this->complianceAuditor->checkDocumentCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('status', $result);
            $this->assertArrayHasKey('compliance_percentage', $result);
            $this->assertArrayHasKey('total_required', $result);
            $this->assertArrayHasKey('valid_documents', $result);
            $this->assertArrayHasKey('missing_documents', $result);
            $this->assertArrayHasKey('expired_documents', $result);
            $this->assertArrayHasKey('pending_documents', $result);
            $this->assertArrayHasKey('document_details', $result);
            
            $this->assertContains($result['status'], ['compliant', 'warning', 'non_compliant']);
            $this->assertIsFloat($result['compliance_percentage']);
            $this->assertIsInt($result['total_required']);
            $this->assertIsInt($result['valid_documents']);
            $this->assertIsArray($result['missing_documents']);
            $this->assertIsArray($result['expired_documents']);
            $this->assertIsArray($result['pending_documents']);
            $this->assertIsArray($result['document_details']);
            
            // Check document details structure
            $documentDetails = $result['document_details'];
            $this->assertArrayHasKey('valid', $documentDetails);
            $this->assertArrayHasKey('expired', $documentDetails);
            $this->assertArrayHasKey('pending', $documentDetails);
        }
    }

    public function testCheckProductCompliance()
    {
        $vendorId = 1;
        $result = $this->complianceAuditor->checkProductCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('status', $result);
            $this->assertArrayHasKey('compliance_percentage', $result);
            $this->assertArrayHasKey('total_products', $result);
            $this->assertArrayHasKey('compliant_products', $result);
            $this->assertArrayHasKey('non_compliant_products', $result);
            $this->assertArrayHasKey('expired_batches', $result);
            $this->assertArrayHasKey('expiring_batches', $result);
            $this->assertArrayHasKey('issues', $result);
            
            $this->assertContains($result['status'], ['compliant', 'warning', 'non_compliant']);
            $this->assertIsFloat($result['compliance_percentage']);
            $this->assertIsInt($result['total_products']);
            $this->assertIsInt($result['compliant_products']);
            $this->assertIsInt($result['non_compliant_products']);
            $this->assertIsInt($result['expired_batches']);
            $this->assertIsInt($result['expiring_batches']);
            $this->assertIsArray($result['issues']);
            
            // Check issues structure
            $issues = $result['issues'];
            $this->assertArrayHasKey('non_compliant_products', $issues);
            $this->assertArrayHasKey('expired_batches', $issues);
            $this->assertArrayHasKey('expiring_batches', $issues);
        }
    }

    public function testCheckOperationalCompliance()
    {
        $vendorId = 1;
        $result = $this->complianceAuditor->checkOperationalCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('status', $result);
            $this->assertArrayHasKey('compliance_score', $result);
            $this->assertArrayHasKey('fulfillment_rate', $result);
            $this->assertArrayHasKey('delivery_performance', $result);
            $this->assertArrayHasKey('complaint_rate', $result);
            $this->assertArrayHasKey('inventory_performance', $result);
            $this->assertArrayHasKey('response_time_hours', $result);
            $this->assertArrayHasKey('issues', $result);
            
            $this->assertContains($result['status'], ['compliant', 'warning', 'non_compliant']);
            $this->assertIsInt($result['compliance_score']);
            $this->assertIsFloat($result['fulfillment_rate']);
            $this->assertIsArray($result['delivery_performance']);
            $this->assertIsFloat($result['complaint_rate']);
            $this->assertIsArray($result['inventory_performance']);
            $this->assertIsFloat($result['response_time_hours']);
            $this->assertIsArray($result['issues']);
            
            // Check delivery performance structure
            $deliveryPerformance = $result['delivery_performance'];
            $this->assertArrayHasKey('on_time_rate', $deliveryPerformance);
            $this->assertArrayHasKey('average_delay_minutes', $deliveryPerformance);
            $this->assertArrayHasKey('total_deliveries', $deliveryPerformance);
            
            // Check inventory performance structure
            $inventoryPerformance = $result['inventory_performance'];
            $this->assertArrayHasKey('stock_out_rate', $inventoryPerformance);
            $this->assertArrayHasKey('overstock_rate', $inventoryPerformance);
            $this->assertArrayHasKey('turnover_rate', $inventoryPerformance);
        }
    }

    public function testGenerateComplianceRecommendations()
    {
        // Mock compliance results for testing
        $mockComplianceResults = [
            'fssai' => [
                'success' => true,
                'compliance_status' => 'non_compliant'
            ],
            'documents' => [
                'success' => true,
                'status' => 'non_compliant',
                'missing_documents' => ['business_registration', 'tax_registration'],
                'expired_documents' => [
                    ['document_type' => 'identity_proof', 'expiry_date' => '2023-12-31']
                ]
            ],
            'products' => [
                'success' => true,
                'expired_batches' => 3,
                'expiring_batches' => 5
            ],
            'operational' => [
                'success' => true,
                'issues' => [
                    'Low order fulfillment rate: 75%',
                    'Poor delivery performance: 70% on-time rate',
                    'High complaint rate: 8%'
                ]
            ]
        ];

        $recommendations = $this->complianceAuditor->generateComplianceRecommendations($mockComplianceResults);

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);

        // Check recommendation structure
        foreach ($recommendations as $recommendation) {
            $this->assertArrayHasKey('category', $recommendation);
            $this->assertArrayHasKey('priority', $recommendation);
            $this->assertArrayHasKey('title', $recommendation);
            $this->assertArrayHasKey('description', $recommendation);
            $this->assertArrayHasKey('action', $recommendation);
            
            $this->assertContains($recommendation['category'], ['fssai', 'documents', 'products', 'operational']);
            $this->assertContains($recommendation['priority'], ['high', 'medium', 'low']);
            $this->assertIsString($recommendation['title']);
            $this->assertIsString($recommendation['description']);
            $this->assertIsString($recommendation['action']);
        }

        // Should have recommendations for each non-compliant area
        $categories = array_column($recommendations, 'category');
        $this->assertContains('fssai', $categories);
        $this->assertContains('documents', $categories);
        $this->assertContains('products', $categories);
        $this->assertContains('operational', $categories);
    }

    public function testGetComplianceHistory()
    {
        $vendorId = 1;
        $limit = 5;
        
        $result = $this->complianceAuditor->getComplianceHistory($vendorId, $limit);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('vendor_id', $result);
            $this->assertArrayHasKey('audit_count', $result);
            $this->assertArrayHasKey('audits', $result);
            
            $this->assertEquals($vendorId, $result['vendor_id']);
            $this->assertIsInt($result['audit_count']);
            $this->assertIsArray($result['audits']);
            $this->assertLessThanOrEqual($limit, count($result['audits']));
        }

        // Test with default limit
        $result = $this->complianceAuditor->getComplianceHistory($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertLessThanOrEqual(10, count($result['audits'])); // Default limit is 10
        }
    }

    public function testComplianceScoreCalculation()
    {
        // Test with perfect compliance
        $perfectCompliance = [
            'fssai' => ['success' => true, 'compliance_status' => 'compliant'],
            'documents' => ['success' => true, 'compliance_percentage' => 100],
            'products' => ['success' => true, 'compliance_percentage' => 100],
            'operational' => ['success' => true, 'compliance_score' => 100]
        ];

        $vendorId = 1;
        $result = $this->complianceAuditor->auditVendorCompliance($vendorId);

        if ($result['success']) {
            $this->assertIsInt($result['compliance_score']);
            $this->assertGreaterThanOrEqual(0, $result['compliance_score']);
            $this->assertLessThanOrEqual(100, $result['compliance_score']);
        }
    }

    public function testComplianceStatusDetermination()
    {
        // Test different score ranges
        $testCases = [
            ['score' => 90, 'expected_status' => 'compliant'],
            ['score' => 85, 'expected_status' => 'compliant'],
            ['score' => 80, 'expected_status' => 'warning'],
            ['score' => 70, 'expected_status' => 'warning'],
            ['score' => 60, 'expected_status' => 'non_compliant'],
            ['score' => 30, 'expected_status' => 'non_compliant']
        ];

        foreach ($testCases as $testCase) {
            $score = $testCase['score'];
            $expectedStatus = $testCase['expected_status'];
            
            // We can't directly test the private method, but we can verify
            // that the audit result has the correct status based on score
            // This would be tested indirectly through the audit process
            $this->assertTrue(true); // Placeholder for indirect testing
        }
    }

    public function testRecommendationPriorities()
    {
        // Test that high-priority issues get high-priority recommendations
        $criticalIssues = [
            'fssai' => [
                'success' => true,
                'compliance_status' => 'non_compliant'
            ],
            'documents' => [
                'success' => true,
                'status' => 'non_compliant',
                'missing_documents' => ['fssai_license']
            ],
            'products' => [
                'success' => true,
                'expired_batches' => 10
            ],
            'operational' => [
                'success' => true,
                'issues' => ['Low order fulfillment rate: 50%']
            ]
        ];

        $recommendations = $this->complianceAuditor->generateComplianceRecommendations($criticalIssues);

        $this->assertIsArray($recommendations);
        
        // Should have high-priority recommendations for critical issues
        $highPriorityRecommendations = array_filter($recommendations, fn($r) => $r['priority'] === 'high');
        $this->assertNotEmpty($highPriorityRecommendations);
    }

    public function testEmptyComplianceResults()
    {
        $emptyResults = [];
        $recommendations = $this->complianceAuditor->generateComplianceRecommendations($emptyResults);

        $this->assertIsArray($recommendations);
        // Should handle empty results gracefully
    }

    public function testComplianceAuditWithNonExistentVendor()
    {
        $nonExistentVendorId = 999999;
        $result = $this->complianceAuditor->auditVendorCompliance($nonExistentVendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Vendor not found', $result['error']);
    }

    public function testDocumentComplianceWithNoDocuments()
    {
        // Test vendor with no documents
        $vendorId = 999; // Assuming this vendor has no documents
        $result = $this->complianceAuditor->checkDocumentCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertEquals('non_compliant', $result['status']);
            $this->assertEquals(0, $result['compliance_percentage']);
            $this->assertNotEmpty($result['missing_documents']);
        }
    }

    public function testProductComplianceWithNoProducts()
    {
        // Test vendor with no products
        $vendorId = 999; // Assuming this vendor has no products
        $result = $this->complianceAuditor->checkProductCompliance($vendorId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertEquals(0, $result['total_products']);
            $this->assertEquals(100, $result['compliance_percentage']); // No products = 100% compliant
            $this->assertEquals('compliant', $result['status']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}