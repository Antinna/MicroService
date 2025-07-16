<?php

namespace Antinna\MultiVendor\Controllers;

use Antinna\MultiVendor\Services\VendorRegistrationService;
use Antinna\MultiVendor\Services\VendorProfileManager;
use Antinna\MultiVendor\Services\VendorRoleManager;
use Antinna\MultiVendor\Services\KYCValidator;
use Antinna\MultiVendor\Services\FSSAIValidator;
use Exception;

/**
 * Vendor management API controller
 */
class VendorController
{
    private VendorRegistrationService $registrationService;
    private VendorProfileManager $profileManager;
    private VendorRoleManager $roleManager;
    private KYCValidator $kycValidator;
    private FSSAIValidator $fssaiValidator;

    public function __construct()
    {
        $this->registrationService = new VendorRegistrationService();
        $this->profileManager = new VendorProfileManager();
        $this->roleManager = new VendorRoleManager();
        $this->kycValidator = new KYCValidator();
        $this->fssaiValidator = new FSSAIValidator();
    }

    /**
     * Register new vendor
     * POST /api/vendors/register
     */
    public function register(): void
    {
        try {
            $input = $this->getJsonInput();
            
            // Validate required fields
            $requiredFields = ['business_name', 'business_type', 'contact_person', 'email', 'phone'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            // Register vendor
            $result = $this->registrationService->registerVendor($input);
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor profile
     * GET /api/vendors/{id}
     */
    public function getProfile(int $vendorId): void
    {
        try {
            $result = $this->profileManager->getVendorProfile($vendorId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendor profile: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update vendor profile
     * PUT /api/vendors/{id}
     */
    public function updateProfile(int $vendorId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $result = $this->profileManager->updateVendorProfile($vendorId, $input);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to update vendor profile: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get all vendors with pagination
     * GET /api/vendors
     */
    public function getVendors(): void
    {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? null;
            $businessType = $_GET['business_type'] ?? null;
            $search = $_GET['search'] ?? null;

            $result = $this->profileManager->getVendors([
                'page' => $page,
                'limit' => $limit,
                'status' => $status,
                'business_type' => $businessType,
                'search' => $search
            ]);
            
            $this->sendResponse(200, $result);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendors: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Validate KYC documents
     * POST /api/vendors/{id}/kyc/validate
     */
    public function validateKYC(int $vendorId): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['documents'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Documents are required for KYC validation'
                ]);
                return;
            }

            $result = $this->kycValidator->validateDocuments($vendorId, $input['documents']);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'KYC validation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get KYC status
     * GET /api/vendors/{id}/kyc/status
     */
    public function getKYCStatus(int $vendorId): void
    {
        try {
            $result = $this->kycValidator->getKYCStatus($vendorId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get KYC status: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Validate FSSAI license
     * POST /api/vendors/{id}/fssai/validate
     */
    public function validateFSSAI(int $vendorId): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['license_number'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'FSSAI license number is required'
                ]);
                return;
            }

            // First validate the license
            $validationResult = $this->fssaiValidator->validateLicense(
                $input['license_number'],
                $input['document_data'] ?? null
            );

            if (!$validationResult['valid']) {
                $this->sendResponse(400, $validationResult);
                return;
            }

            // Register compliance record
            $complianceData = [
                'compliance_type' => 'fssai',
                'certificate_number' => $input['license_number'],
                'issuing_authority' => 'FSSAI',
                'issue_date' => $input['issue_date'] ?? date('Y-m-d'),
                'expiry_date' => $validationResult['license_data']['valid_until'] ?? date('Y-m-d', strtotime('+1 year')),
                'document_path' => $input['document_path'] ?? null,
                'verification_notes' => 'Validated via API'
            ];

            $complianceResult = $this->fssaiValidator->registerComplianceRecord($vendorId, $complianceData);

            $response = [
                'success' => true,
                'message' => 'FSSAI license validated successfully',
                'validation_result' => $validationResult,
                'compliance_result' => $complianceResult
            ];

            $this->sendResponse(200, $response);

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'FSSAI validation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor compliance status
     * GET /api/vendors/{id}/compliance
     */
    public function getComplianceStatus(int $vendorId): void
    {
        try {
            $result = $this->fssaiValidator->checkVendorCompliance($vendorId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get compliance status: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Assign role to vendor user
     * POST /api/vendors/{id}/roles
     */
    public function assignRole(int $vendorId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $requiredFields = ['user_id', 'role'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            $result = $this->roleManager->assignRole(
                $vendorId,
                $input['user_id'],
                $input['role'],
                $input['permissions'] ?? []
            );
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Role assignment failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor roles
     * GET /api/vendors/{id}/roles
     */
    public function getRoles(int $vendorId): void
    {
        try {
            $result = $this->roleManager->getVendorRoles($vendorId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendor roles: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update user role
     * PUT /api/vendors/{id}/roles/{userId}
     */
    public function updateRole(int $vendorId, int $userId): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['role'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Role is required'
                ]);
                return;
            }

            $result = $this->roleManager->updateRole(
                $vendorId,
                $userId,
                $input['role'],
                $input['permissions'] ?? []
            );
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Role update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Remove user role
     * DELETE /api/vendors/{id}/roles/{userId}
     */
    public function removeRole(int $vendorId, int $userId): void
    {
        try {
            $result = $this->roleManager->removeRole($vendorId, $userId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Role removal failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Check user permissions
     * GET /api/vendors/{id}/users/{userId}/permissions
     */
    public function checkPermissions(int $vendorId, int $userId): void
    {
        try {
            $permission = $_GET['permission'] ?? null;
            
            if ($permission) {
                $result = $this->roleManager->hasPermission($vendorId, $userId, $permission);
            } else {
                $result = $this->roleManager->getUserPermissions($vendorId, $userId);
            }
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Permission check failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Update vendor status
     * PATCH /api/vendors/{id}/status
     */
    public function updateStatus(int $vendorId): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (empty($input['status'])) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Status is required'
                ]);
                return;
            }

            $validStatuses = ['active', 'inactive', 'suspended', 'pending_approval'];
            if (!in_array($input['status'], $validStatuses)) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Invalid status. Valid statuses: ' . implode(', ', $validStatuses)
                ]);
                return;
            }

            $result = $this->profileManager->updateVendorStatus(
                $vendorId, 
                $input['status'],
                $input['reason'] ?? null
            );
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Status update failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor statistics
     * GET /api/vendors/{id}/stats
     */
    public function getStatistics(int $vendorId): void
    {
        try {
            $result = $this->profileManager->getVendorStatistics($vendorId);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendor statistics: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Upload vendor document
     * POST /api/vendors/{id}/documents
     */
    public function uploadDocument(int $vendorId): void
    {
        try {
            $input = $this->getJsonInput();
            
            $requiredFields = ['document_type', 'document_path'];
            $validation = $this->validateRequiredFields($input, $requiredFields);
            
            if (!$validation['valid']) {
                $this->sendResponse(400, [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validation['errors']
                ]);
                return;
            }

            $result = $this->profileManager->uploadDocument($vendorId, $input);
            
            if ($result['success']) {
                $this->sendResponse(201, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Document upload failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get vendor documents
     * GET /api/vendors/{id}/documents
     */
    public function getDocuments(int $vendorId): void
    {
        try {
            $documentType = $_GET['document_type'] ?? null;
            
            $result = $this->profileManager->getVendorDocuments($vendorId, $documentType);
            
            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(404, $result);
            }

        } catch (Exception $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Failed to get vendor documents: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input');
        }
        
        return $data ?? [];
    }

    /**
     * Validate required fields
     */
    private function validateRequiredFields(array $data, array $requiredFields): array
    {
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Send JSON response
     */
    private function sendResponse(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}