<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * Compliance auditor for tracking and managing vendor compliance
 */
class ComplianceAuditor
{
    private PDO $db;
    private FSSAIValidator $fssaiValidator;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->fssaiValidator = new FSSAIValidator();
    }

    /**
     * Perform comprehensive compliance audit for vendor
     */
    public function auditVendorCompliance(int $vendorId): array
    {
        try {
            // Get vendor information
            $vendor = $this->getVendorInfo($vendorId);
            if (!$vendor) {
                return [
                    'success' => false,
                    'error' => 'Vendor not found'
                ];
            }

            // Check FSSAI compliance
            $fssaiCompliance = $this->fssaiValidator->checkVendorCompliance($vendorId);
            
            // Check document compliance
            $documentCompliance = $this->checkDocumentCompliance($vendorId);
            
            // Check product compliance
            $productCompliance = $this->checkProductCompliance($vendorId);
            
            // Check operational compliance
            $operationalCompliance = $this->checkOperationalCompliance($vendorId);
            
            // Calculate overall compliance score
            $complianceScore = $this->calculateComplianceScore([
                'fssai' => $fssaiCompliance,
                'documents' => $documentCompliance,
                'products' => $productCompliance,
                'operational' => $operationalCompliance
            ]);

            // Determine compliance status
            $overallStatus = $this->determineOverallComplianceStatus($complianceScore);

            // Generate recommendations
            $recommendations = $this->generateComplianceRecommendations([
                'fssai' => $fssaiCompliance,
                'documents' => $documentCompliance,
                'products' => $productCompliance,
                'operational' => $operationalCompliance
            ]);

            // Save audit record
            $auditId = $this->saveAuditRecord($vendorId, [
                'compliance_score' => $complianceScore,
                'overall_status' => $overallStatus,
                'fssai_status' => $fssaiCompliance['compliance_status'] ?? 'unknown',
                'document_status' => $documentCompliance['status'],
                'product_status' => $productCompliance['status'],
                'operational_status' => $operationalCompliance['status'],
                'recommendations' => $recommendations
            ]);

            return [
                'success' => true,
                'audit_id' => $auditId,
                'vendor_id' => $vendorId,
                'vendor_name' => $vendor['business_name'],
                'audit_date' => date('Y-m-d H:i:s'),
                'compliance_score' => $complianceScore,
                'overall_status' => $overallStatus,
                'detailed_results' => [
                    'fssai_compliance' => $fssaiCompliance,
                    'document_compliance' => $documentCompliance,
                    'product_compliance' => $productCompliance,
                    'operational_compliance' => $operationalCompliance
                ],
                'recommendations' => $recommendations
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Compliance audit failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check document compliance
     */
    public function checkDocumentCompliance(int $vendorId): array
    {
        try {
            // Get vendor documents
            $sql = "SELECT * FROM vendor_documents WHERE vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $documents = $stmt->fetchAll();

            $requiredDocuments = [
                'business_registration',
                'tax_registration',
                'bank_details',
                'identity_proof',
                'address_proof'
            ];

            $missingDocuments = [];
            $expiredDocuments = [];
            $validDocuments = [];
            $pendingDocuments = [];

            // Check each required document
            foreach ($requiredDocuments as $docType) {
                $found = false;
                foreach ($documents as $doc) {
                    if ($doc['document_type'] === $docType) {
                        $found = true;
                        
                        if ($doc['verification_status'] === 'verified') {
                            // Check if document has expiry and is expired
                            if ($doc['expiry_date'] && new DateTime($doc['expiry_date']) < new DateTime()) {
                                $expiredDocuments[] = $doc;
                            } else {
                                $validDocuments[] = $doc;
                            }
                        } elseif ($doc['verification_status'] === 'pending') {
                            $pendingDocuments[] = $doc;
                        }
                        break;
                    }
                }
                
                if (!$found) {
                    $missingDocuments[] = $docType;
                }
            }

            // Calculate document compliance percentage
            $totalRequired = count($requiredDocuments);
            $validCount = count($validDocuments);
            $compliancePercentage = $totalRequired > 0 ? ($validCount / $totalRequired) * 100 : 0;

            // Determine status
            $status = 'non_compliant';
            if ($compliancePercentage >= 100) {
                $status = 'compliant';
            } elseif ($compliancePercentage >= 80) {
                $status = 'warning';
            }

            return [
                'success' => true,
                'status' => $status,
                'compliance_percentage' => $compliancePercentage,
                'total_required' => $totalRequired,
                'valid_documents' => count($validDocuments),
                'missing_documents' => $missingDocuments,
                'expired_documents' => $expiredDocuments,
                'pending_documents' => $pendingDocuments,
                'document_details' => [
                    'valid' => $validDocuments,
                    'expired' => $expiredDocuments,
                    'pending' => $pendingDocuments
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Document compliance check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check product compliance
     */
    public function checkProductCompliance(int $vendorId): array
    {
        try {
            // Get vendor products
            $sql = "SELECT p.*, ib.batch_number, ib.expiry_date as batch_expiry, ib.quantity
                    FROM products p
                    LEFT JOIN inventory_batches ib ON p.id = ib.product_id
                    WHERE p.vendor_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $products = $stmt->fetchAll();

            $totalProducts = 0;
            $compliantProducts = 0;
            $nonCompliantProducts = [];
            $expiredBatches = [];
            $expiringBatches = [];

            $productGroups = [];
            foreach ($products as $product) {
                $productId = $product['id'];
                if (!isset($productGroups[$productId])) {
                    $productGroups[$productId] = [
                        'product' => $product,
                        'batches' => []
                    ];
                    $totalProducts++;
                }
                
                if ($product['batch_number']) {
                    $productGroups[$productId]['batches'][] = $product;
                }
            }

            foreach ($productGroups as $productId => $group) {
                $product = $group['product'];
                $batches = $group['batches'];
                
                $productCompliant = true;
                $issues = [];

                // Check product information completeness
                if (empty($product['name']) || empty($product['category']) || empty($product['unit'])) {
                    $productCompliant = false;
                    $issues[] = 'Incomplete product information';
                }

                // Check if perishable products have proper batch tracking
                if ($product['is_perishable'] && empty($batches)) {
                    $productCompliant = false;
                    $issues[] = 'Perishable product missing batch tracking';
                }

                // Check batch compliance for perishable products
                foreach ($batches as $batch) {
                    if ($batch['batch_expiry']) {
                        $expiryDate = new DateTime($batch['batch_expiry']);
                        $today = new DateTime();
                        $daysDiff = $expiryDate->diff($today)->days;

                        if ($expiryDate < $today) {
                            $expiredBatches[] = $batch;
                            $productCompliant = false;
                            $issues[] = 'Contains expired batches';
                        } elseif ($daysDiff <= 3) {
                            $expiringBatches[] = $batch;
                        }
                    }
                }

                if ($productCompliant) {
                    $compliantProducts++;
                } else {
                    $nonCompliantProducts[] = [
                        'product' => $product,
                        'issues' => $issues
                    ];
                }
            }

            // Calculate compliance percentage
            $compliancePercentage = $totalProducts > 0 ? ($compliantProducts / $totalProducts) * 100 : 100;

            // Determine status
            $status = 'compliant';
            if ($compliancePercentage < 80) {
                $status = 'non_compliant';
            } elseif ($compliancePercentage < 95 || !empty($expiringBatches)) {
                $status = 'warning';
            }

            return [
                'success' => true,
                'status' => $status,
                'compliance_percentage' => $compliancePercentage,
                'total_products' => $totalProducts,
                'compliant_products' => $compliantProducts,
                'non_compliant_products' => count($nonCompliantProducts),
                'expired_batches' => count($expiredBatches),
                'expiring_batches' => count($expiringBatches),
                'issues' => [
                    'non_compliant_products' => $nonCompliantProducts,
                    'expired_batches' => $expiredBatches,
                    'expiring_batches' => $expiringBatches
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product compliance check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check operational compliance
     */
    public function checkOperationalCompliance(int $vendorId): array
    {
        try {
            $issues = [];
            $score = 100;

            // Check recent order fulfillment rate
            $fulfillmentRate = $this->checkOrderFulfillmentRate($vendorId);
            if ($fulfillmentRate < 90) {
                $issues[] = "Low order fulfillment rate: {$fulfillmentRate}%";
                $score -= 20;
            }

            // Check delivery performance
            $deliveryPerformance = $this->checkDeliveryPerformance($vendorId);
            if ($deliveryPerformance['on_time_rate'] < 85) {
                $issues[] = "Poor delivery performance: {$deliveryPerformance['on_time_rate']}% on-time rate";
                $score -= 15;
            }

            // Check customer complaints
            $complaintRate = $this->checkComplaintRate($vendorId);
            if ($complaintRate > 5) {
                $issues[] = "High complaint rate: {$complaintRate}%";
                $score -= 15;
            }

            // Check inventory management
            $inventoryIssues = $this->checkInventoryManagement($vendorId);
            if ($inventoryIssues['stock_out_rate'] > 10) {
                $issues[] = "Frequent stock-outs: {$inventoryIssues['stock_out_rate']}%";
                $score -= 10;
            }

            // Check response time to customer queries
            $responseTime = $this->checkResponseTime($vendorId);
            if ($responseTime > 24) {
                $issues[] = "Slow response time: {$responseTime} hours average";
                $score -= 10;
            }

            // Determine status
            $status = 'compliant';
            if ($score < 70) {
                $status = 'non_compliant';
            } elseif ($score < 85) {
                $status = 'warning';
            }

            return [
                'success' => true,
                'status' => $status,
                'compliance_score' => max(0, $score),
                'fulfillment_rate' => $fulfillmentRate,
                'delivery_performance' => $deliveryPerformance,
                'complaint_rate' => $complaintRate,
                'inventory_performance' => $inventoryIssues,
                'response_time_hours' => $responseTime,
                'issues' => $issues
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Operational compliance check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate compliance recommendations
     */
    public function generateComplianceRecommendations(array $complianceResults): array
    {
        $recommendations = [];

        // FSSAI recommendations
        if (isset($complianceResults['fssai']['success']) && $complianceResults['fssai']['success']) {
            $fssaiStatus = $complianceResults['fssai']['compliance_status'] ?? 'unknown';
            
            if ($fssaiStatus === 'non_compliant') {
                $recommendations[] = [
                    'category' => 'fssai',
                    'priority' => 'high',
                    'title' => 'FSSAI License Required',
                    'description' => 'Obtain valid FSSAI license to comply with food safety regulations',
                    'action' => 'Apply for FSSAI license through official portal'
                ];
            } elseif ($fssaiStatus === 'warning') {
                $recommendations[] = [
                    'category' => 'fssai',
                    'priority' => 'medium',
                    'title' => 'FSSAI License Expiring',
                    'description' => 'Renew FSSAI license before expiry to maintain compliance',
                    'action' => 'Initiate license renewal process'
                ];
            }
        }

        // Document recommendations
        if (isset($complianceResults['documents']['success']) && $complianceResults['documents']['success']) {
            $docStatus = $complianceResults['documents']['status'];
            
            if ($docStatus === 'non_compliant') {
                $missingDocs = $complianceResults['documents']['missing_documents'] ?? [];
                if (!empty($missingDocs)) {
                    $recommendations[] = [
                        'category' => 'documents',
                        'priority' => 'high',
                        'title' => 'Missing Required Documents',
                        'description' => 'Upload missing documents: ' . implode(', ', $missingDocs),
                        'action' => 'Upload and verify required documents'
                    ];
                }
            }

            $expiredDocs = $complianceResults['documents']['expired_documents'] ?? [];
            if (!empty($expiredDocs)) {
                $recommendations[] = [
                    'category' => 'documents',
                    'priority' => 'medium',
                    'title' => 'Expired Documents',
                    'description' => 'Update expired documents to maintain compliance',
                    'action' => 'Upload renewed versions of expired documents'
                ];
            }
        }

        // Product recommendations
        if (isset($complianceResults['products']['success']) && $complianceResults['products']['success']) {
            $expiredBatches = $complianceResults['products']['expired_batches'] ?? 0;
            if ($expiredBatches > 0) {
                $recommendations[] = [
                    'category' => 'products',
                    'priority' => 'high',
                    'title' => 'Remove Expired Products',
                    'description' => "Remove {$expiredBatches} expired product batches from inventory",
                    'action' => 'Update inventory to remove expired items'
                ];
            }

            $expiringBatches = $complianceResults['products']['expiring_batches'] ?? 0;
            if ($expiringBatches > 0) {
                $recommendations[] = [
                    'category' => 'products',
                    'priority' => 'medium',
                    'title' => 'Products Expiring Soon',
                    'description' => "{$expiringBatches} product batches expiring within 3 days",
                    'action' => 'Prioritize sale of expiring products or remove from inventory'
                ];
            }
        }

        // Operational recommendations
        if (isset($complianceResults['operational']['success']) && $complianceResults['operational']['success']) {
            $issues = $complianceResults['operational']['issues'] ?? [];
            foreach ($issues as $issue) {
                if (strpos($issue, 'fulfillment rate') !== false) {
                    $recommendations[] = [
                        'category' => 'operational',
                        'priority' => 'high',
                        'title' => 'Improve Order Fulfillment',
                        'description' => $issue,
                        'action' => 'Review inventory management and order processing workflow'
                    ];
                } elseif (strpos($issue, 'delivery performance') !== false) {
                    $recommendations[] = [
                        'category' => 'operational',
                        'priority' => 'medium',
                        'title' => 'Improve Delivery Performance',
                        'description' => $issue,
                        'action' => 'Optimize delivery scheduling and logistics'
                    ];
                } elseif (strpos($issue, 'complaint rate') !== false) {
                    $recommendations[] = [
                        'category' => 'operational',
                        'priority' => 'medium',
                        'title' => 'Address Customer Complaints',
                        'description' => $issue,
                        'action' => 'Improve product quality and customer service'
                    ];
                }
            }
        }

        return $recommendations;
    }

    /**
     * Get compliance history for vendor
     */
    public function getComplianceHistory(int $vendorId, int $limit = 10): array
    {
        try {
            $sql = "SELECT * FROM compliance_audits 
                    WHERE vendor_id = ? 
                    ORDER BY audit_date DESC 
                    LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $limit]);
            $audits = $stmt->fetchAll();

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'audit_count' => count($audits),
                'audits' => $audits
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get compliance history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate overall compliance score
     */
    private function calculateComplianceScore(array $complianceResults): int
    {
        $weights = [
            'fssai' => 40,      // FSSAI compliance is most important
            'documents' => 25,   // Document compliance
            'products' => 20,    // Product compliance
            'operational' => 15  // Operational compliance
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($weights as $category => $weight) {
            if (isset($complianceResults[$category]['success']) && $complianceResults[$category]['success']) {
                $categoryScore = 0;
                
                switch ($category) {
                    case 'fssai':
                        $status = $complianceResults[$category]['compliance_status'] ?? 'non_compliant';
                        $categoryScore = $status === 'compliant' ? 100 : ($status === 'warning' ? 70 : 0);
                        break;
                        
                    case 'documents':
                        $categoryScore = $complianceResults[$category]['compliance_percentage'] ?? 0;
                        break;
                        
                    case 'products':
                        $categoryScore = $complianceResults[$category]['compliance_percentage'] ?? 0;
                        break;
                        
                    case 'operational':
                        $categoryScore = $complianceResults[$category]['compliance_score'] ?? 0;
                        break;
                }
                
                $totalScore += ($categoryScore * $weight / 100);
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? round($totalScore * 100 / $totalWeight) : 0;
    }

    /**
     * Determine overall compliance status
     */
    private function determineOverallComplianceStatus(int $score): string
    {
        if ($score >= 85) {
            return 'compliant';
        } elseif ($score >= 70) {
            return 'warning';
        } else {
            return 'non_compliant';
        }
    }

    /**
     * Save audit record
     */
    private function saveAuditRecord(int $vendorId, array $auditData): ?int
    {
        try {
            $sql = "INSERT INTO compliance_audits (
                        vendor_id, audit_date, compliance_score, overall_status,
                        fssai_status, document_status, product_status, operational_status,
                        recommendations, created_at
                    ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $vendorId,
                $auditData['compliance_score'],
                $auditData['overall_status'],
                $auditData['fssai_status'],
                $auditData['document_status'],
                $auditData['product_status'],
                $auditData['operational_status'],
                json_encode($auditData['recommendations'])
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get vendor information
     */
    private function getVendorInfo(int $vendorId): ?array
    {
        try {
            $sql = "SELECT * FROM vendors WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check order fulfillment rate
     */
    private function checkOrderFulfillmentRate(int $vendorId): float
    {
        try {
            // This would integrate with order management system
            // For now, return a mock value
            return 92.5;

        } catch (Exception $e) {
            return 0.0;
        }
    }

    /**
     * Check delivery performance
     */
    private function checkDeliveryPerformance(int $vendorId): array
    {
        try {
            // This would integrate with delivery system
            // For now, return mock values
            return [
                'on_time_rate' => 87.3,
                'average_delay_minutes' => 15,
                'total_deliveries' => 150
            ];

        } catch (Exception $e) {
            return [
                'on_time_rate' => 0,
                'average_delay_minutes' => 0,
                'total_deliveries' => 0
            ];
        }
    }

    /**
     * Check complaint rate
     */
    private function checkComplaintRate(int $vendorId): float
    {
        try {
            // This would integrate with customer feedback system
            // For now, return a mock value
            return 3.2;

        } catch (Exception $e) {
            return 0.0;
        }
    }

    /**
     * Check inventory management performance
     */
    private function checkInventoryManagement(int $vendorId): array
    {
        try {
            // This would analyze inventory patterns
            // For now, return mock values
            return [
                'stock_out_rate' => 8.5,
                'overstock_rate' => 12.3,
                'turnover_rate' => 4.2
            ];

        } catch (Exception $e) {
            return [
                'stock_out_rate' => 0,
                'overstock_rate' => 0,
                'turnover_rate' => 0
            ];
        }
    }

    /**
     * Check response time to customer queries
     */
    private function checkResponseTime(int $vendorId): float
    {
        try {
            // This would integrate with customer service system
            // For now, return a mock value
            return 18.5;

        } catch (Exception $e) {
            return 0.0;
        }
    }
}