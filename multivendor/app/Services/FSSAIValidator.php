<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;
use DateTime;

/**
 * FSSAI validator for license validation and compliance checking
 */
class FSSAIValidator
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Validate FSSAI license
     */
    public function validateLicense(string $licenseNumber, ?array $documentData = null): array
    {
        try {
            // Basic format validation
            if (!$this->isValidLicenseFormat($licenseNumber)) {
                return [
                    'valid' => false,
                    'error' => 'Invalid FSSAI license format'
                ];
            }

            // Check for license in database
            $existingLicense = $this->getLicenseRecord($licenseNumber);
            
            if ($existingLicense) {
                // License exists, check if it's valid
                if ($existingLicense['status'] !== 'valid') {
                    return [
                        'valid' => false,
                        'error' => 'FSSAI license is ' . $existingLicense['status'],
                        'license_data' => $existingLicense
                    ];
                }

                // Check if license is expired
                $expiryDate = new DateTime($existingLicense['expiry_date']);
                $today = new DateTime();
                
                if ($expiryDate < $today) {
                    return [
                        'valid' => false,
                        'error' => 'FSSAI license expired on ' . $existingLicense['expiry_date'],
                        'license_data' => $existingLicense
                    ];
                }

                return [
                    'valid' => true,
                    'message' => 'FSSAI license is valid',
                    'license_data' => $existingLicense
                ];
            }

            // License not in database, verify with external API (mock)
            $verificationResult = $this->verifyLicenseWithExternalAPI($licenseNumber);
            
            if ($verificationResult['verified']) {
                // Save license to database
                $licenseId = $this->saveLicenseRecord(
                    $licenseNumber,
                    $verificationResult['business_name'],
                    $verificationResult['license_type'],
                    $verificationResult['valid_until'],
                    $documentData
                );

                return [
                    'valid' => true,
                    'message' => 'FSSAI license verified successfully',
                    'license_id' => $licenseId,
                    'license_data' => $verificationResult
                ];
            } else {
                return [
                    'valid' => false,
                    'error' => 'FSSAI license verification failed',
                    'details' => $verificationResult['message'] ?? 'Unknown error'
                ];
            }

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'License validation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Register compliance record for vendor
     */
    public function registerComplianceRecord(int $vendorId, array $complianceData): array
    {
        try {
            // Validate compliance data
            $validation = $this->validateComplianceData($complianceData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check if compliance record already exists
            $existingRecord = $this->getComplianceRecord($vendorId, $complianceData['compliance_type']);
            
            if ($existingRecord) {
                // Update existing record
                $updateData = [
                    'certificate_number' => $complianceData['certificate_number'],
                    'issuing_authority' => $complianceData['issuing_authority'],
                    'issue_date' => $complianceData['issue_date'],
                    'expiry_date' => $complianceData['expiry_date'],
                    'status' => $this->determineComplianceStatus($complianceData['expiry_date']),
                    'document_path' => $complianceData['document_path'] ?? $existingRecord['document_path'],
                    'verification_notes' => $complianceData['verification_notes'] ?? $existingRecord['verification_notes'],
                    'last_verified_at' => date('Y-m-d H:i:s')
                ];

                $success = $this->updateComplianceRecord($existingRecord['id'], $updateData);

                if ($success) {
                    return [
                        'success' => true,
                        'message' => 'Compliance record updated successfully',
                        'record_id' => $existingRecord['id'],
                        'status' => $updateData['status']
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'Failed to update compliance record'
                    ];
                }
            } else {
                // Create new record
                $recordData = [
                    'vendor_id' => $vendorId,
                    'compliance_type' => $complianceData['compliance_type'],
                    'certificate_number' => $complianceData['certificate_number'],
                    'issuing_authority' => $complianceData['issuing_authority'],
                    'issue_date' => $complianceData['issue_date'],
                    'expiry_date' => $complianceData['expiry_date'],
                    'status' => $this->determineComplianceStatus($complianceData['expiry_date']),
                    'document_path' => $complianceData['document_path'] ?? null,
                    'verification_notes' => $complianceData['verification_notes'] ?? null,
                    'last_verified_at' => date('Y-m-d H:i:s')
                ];

                $recordId = $this->createComplianceRecord($recordData);

                if ($recordId) {
                    return [
                        'success' => true,
                        'message' => 'Compliance record created successfully',
                        'record_id' => $recordId,
                        'status' => $recordData['status']
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'Failed to create compliance record'
                    ];
                }
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Compliance registration failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check vendor compliance status
     */
    public function checkVendorCompliance(int $vendorId): array
    {
        try {
            // Get all compliance records for vendor
            $sql = "SELECT * FROM compliance_records WHERE vendor_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $records = $stmt->fetchAll();

            // Check if FSSAI license exists and is valid
            $fssaiRecord = null;
            $hasValidFSSAI = false;
            $complianceStatus = 'non_compliant';
            $expiringRecords = [];
            $expiredRecords = [];
            $validRecords = [];

            foreach ($records as $record) {
                if ($record['compliance_type'] === 'fssai') {
                    $fssaiRecord = $record;
                    if ($record['status'] === 'valid') {
                        $hasValidFSSAI = true;
                    }
                }

                // Check expiry status
                $expiryDate = new DateTime($record['expiry_date']);
                $today = new DateTime();
                $daysDifference = $expiryDate->diff($today)->days;
                
                if ($expiryDate < $today) {
                    $expiredRecords[] = $record;
                } elseif ($daysDifference <= 30) {
                    $expiringRecords[] = $record;
                } elseif ($record['status'] === 'valid') {
                    $validRecords[] = $record;
                }
            }

            // Determine overall compliance status
            if ($hasValidFSSAI && empty($expiredRecords)) {
                $complianceStatus = 'compliant';
            } elseif ($hasValidFSSAI && !empty($expiringRecords)) {
                $complianceStatus = 'warning';
            }

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'compliance_status' => $complianceStatus,
                'has_valid_fssai' => $hasValidFSSAI,
                'fssai_record' => $fssaiRecord,
                'total_records' => count($records),
                'valid_records' => $validRecords,
                'expiring_records' => $expiringRecords,
                'expired_records' => $expiredRecords
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Compliance check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get expiring compliance records
     */
    public function getExpiringComplianceRecords(int $daysThreshold = 30): array
    {
        try {
            $expiryDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));
            
            $sql = "SELECT cr.*, v.business_name as vendor_name
                    FROM compliance_records cr
                    JOIN vendors v ON cr.vendor_id = v.id
                    WHERE cr.expiry_date <= ? AND cr.expiry_date >= CURDATE()
                    ORDER BY cr.expiry_date ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$expiryDate]);
            $records = $stmt->fetchAll();

            return [
                'success' => true,
                'days_threshold' => $daysThreshold,
                'expiring_count' => count($records),
                'records' => $records
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get expiring records: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate compliance report
     */
    public function generateComplianceReport(?int $vendorId = null): array
    {
        try {
            $params = [];
            $vendorCondition = '';
            
            if ($vendorId) {
                $vendorCondition = 'WHERE cr.vendor_id = ?';
                $params[] = $vendorId;
            }

            $sql = "SELECT 
                        v.id as vendor_id,
                        v.business_name,
                        v.business_type,
                        COUNT(cr.id) as total_certifications,
                        SUM(CASE WHEN cr.status = 'valid' THEN 1 ELSE 0 END) as valid_certifications,
                        SUM(CASE WHEN cr.status = 'expired' THEN 1 ELSE 0 END) as expired_certifications,
                        SUM(CASE WHEN cr.status = 'suspended' THEN 1 ELSE 0 END) as suspended_certifications,
                        SUM(CASE WHEN cr.compliance_type = 'fssai' AND cr.status = 'valid' THEN 1 ELSE 0 END) as has_valid_fssai,
                        MIN(CASE WHEN cr.status = 'valid' THEN cr.expiry_date END) as next_expiry_date
                    FROM vendors v
                    LEFT JOIN compliance_records cr ON v.id = cr.vendor_id
                    {$vendorCondition}
                    GROUP BY v.id
                    ORDER BY has_valid_fssai DESC, next_expiry_date ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $report = $stmt->fetchAll();

            // Calculate compliance statistics
            $totalVendors = count($report);
            $compliantVendors = 0;
            $nonCompliantVendors = 0;
            $warningVendors = 0;

            foreach ($report as $vendor) {
                if ($vendor['has_valid_fssai'] > 0 && $vendor['expired_certifications'] == 0) {
                    $compliantVendors++;
                } elseif ($vendor['has_valid_fssai'] > 0) {
                    $warningVendors++;
                } else {
                    $nonCompliantVendors++;
                }
            }

            return [
                'success' => true,
                'generated_at' => date('Y-m-d H:i:s'),
                'statistics' => [
                    'total_vendors' => $totalVendors,
                    'compliant_vendors' => $compliantVendors,
                    'warning_vendors' => $warningVendors,
                    'non_compliant_vendors' => $nonCompliantVendors,
                    'compliance_rate' => $totalVendors > 0 ? ($compliantVendors / $totalVendors) * 100 : 0
                ],
                'vendor_report' => $report
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate compliance report: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate compliance data
     */
    private function validateComplianceData(array $data): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['compliance_type', 'certificate_number', 'issuing_authority', 'issue_date', 'expiry_date'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Compliance type validation
        if (!empty($data['compliance_type'])) {
            $validTypes = ['fssai', 'organic', 'halal', 'kosher', 'other'];
            if (!in_array($data['compliance_type'], $validTypes)) {
                $errors['compliance_type'] = 'Invalid compliance type';
            }
        }

        // Date validations
        if (!empty($data['issue_date'])) {
            if (!$this->isValidDate($data['issue_date'])) {
                $errors['issue_date'] = 'Invalid issue date format (YYYY-MM-DD)';
            } else {
                $issueDate = new DateTime($data['issue_date']);
                $today = new DateTime();
                
                if ($issueDate > $today) {
                    $errors['issue_date'] = 'Issue date cannot be in the future';
                }
            }
        }

        if (!empty($data['expiry_date'])) {
            if (!$this->isValidDate($data['expiry_date'])) {
                $errors['expiry_date'] = 'Invalid expiry date format (YYYY-MM-DD)';
            } else {
                if (!empty($data['issue_date']) && $this->isValidDate($data['issue_date'])) {
                    $issueDate = new DateTime($data['issue_date']);
                    $expiryDate = new DateTime($data['expiry_date']);
                    
                    if ($expiryDate <= $issueDate) {
                        $errors['expiry_date'] = 'Expiry date must be after issue date';
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Determine compliance status based on expiry date
     */
    private function determineComplianceStatus(string $expiryDate): string
    {
        $expiryDateTime = new DateTime($expiryDate);
        $today = new DateTime();
        
        if ($expiryDateTime < $today) {
            return 'expired';
        }
        
        return 'valid';
    }

    /**
     * Check if license format is valid
     */
    private function isValidLicenseFormat(string $license): bool
    {
        // Remove spaces and convert to uppercase
        $license = strtoupper(str_replace(' ', '', $license));
        
        // FSSAI license formats:
        // 14-digit number for manufacturers/importers
        // 10-digit number for traders/distributors
        // Must start with specific digits based on state codes
        
        if (preg_match('/^[0-9]{10}$/', $license)) {
            // 10-digit format for traders
            return $this->validateFSSAI10Digit($license);
        }
        
        if (preg_match('/^[0-9]{14}$/', $license)) {
            // 14-digit format for manufacturers
            return $this->validateFSSAI14Digit($license);
        }
        
        return false;
    }

    /**
     * Validate 10-digit FSSAI license
     */
    private function validateFSSAI10Digit(string $license): bool
    {
        // Basic validation - first 2 digits should be valid state code
        $stateCode = substr($license, 0, 2);
        $validStateCodes = [
            '10', '11', '12', '13', '14', '15', '16', '17', '18', '19',
            '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
            '30', '31', '32', '33', '34', '35', '36', '37'
        ];
        
        return in_array($stateCode, $validStateCodes);
    }

    /**
     * Validate 14-digit FSSAI license
     */
    private function validateFSSAI14Digit(string $license): bool
    {
        // Basic validation - first 2 digits should be valid state code
        $stateCode = substr($license, 0, 2);
        $validStateCodes = [
            '10', '11', '12', '13', '14', '15', '16', '17', '18', '19',
            '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
            '30', '31', '32', '33', '34', '35', '36', '37'
        ];
        
        return in_array($stateCode, $validStateCodes);
    }

    /**
     * Verify license with external API (mock)
     */
    private function verifyLicenseWithExternalAPI(string $license): array
    {
        // This would integrate with FSSAI's verification API
        // For now, return a mock response
        
        return [
            'verified' => true,
            'status' => 'active',
            'business_name' => 'Sample Business Name',
            'license_type' => 'State License',
            'valid_until' => date('Y-m-d', strtotime('+1 year')),
            'message' => 'License verification successful'
        ];
    }

    /**
     * Get license record from database
     */
    private function getLicenseRecord(string $licenseNumber): ?array
    {
        try {
            $sql = "SELECT * FROM compliance_records WHERE certificate_number = ? AND compliance_type = 'fssai'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$licenseNumber]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Save license record to database
     */
    private function saveLicenseRecord(
        string $licenseNumber,
        string $businessName,
        string $licenseType,
        string $expiryDate,
        ?array $documentData = null
    ): ?int {
        try {
            // This would typically be linked to a vendor, but for now we'll just store the license
            $recordData = [
                'vendor_id' => 0, // Placeholder, would be updated later
                'compliance_type' => 'fssai',
                'certificate_number' => $licenseNumber,
                'issuing_authority' => 'FSSAI',
                'issue_date' => date('Y-m-d'),
                'expiry_date' => $expiryDate,
                'status' => 'valid',
                'document_path' => $documentData['path'] ?? null,
                'verification_notes' => 'Verified via external API',
                'last_verified_at' => date('Y-m-d H:i:s')
            ];

            $sql = "INSERT INTO compliance_records (" . implode(', ', array_keys($recordData)) . ") 
                    VALUES (" . str_repeat('?,', count($recordData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($recordData));

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get compliance record
     */
    private function getComplianceRecord(int $vendorId, string $complianceType): ?array
    {
        try {
            $sql = "SELECT * FROM compliance_records 
                    WHERE vendor_id = ? AND compliance_type = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $complianceType]);
            
            return $stmt->fetch() ?: null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create compliance record
     */
    private function createComplianceRecord(array $recordData): ?int
    {
        try {
            $sql = "INSERT INTO compliance_records (" . implode(', ', array_keys($recordData)) . ") 
                    VALUES (" . str_repeat('?,', count($recordData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($recordData));

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update compliance record
     */
    private function updateComplianceRecord(int $recordId, array $updateData): bool
    {
        try {
            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE compliance_records SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $params = array_values($updateData);
            $params[] = $recordId;
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate date format
     */
    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}