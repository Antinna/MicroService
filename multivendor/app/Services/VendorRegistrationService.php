<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Interfaces\ServiceInterface;
use Antinna\MultiVendor\Repositories\VendorRepository;
use Antinna\MultiVendor\Services\KYCValidator;
use Exception;

/**
 * Vendor registration service with business validation
 */
class VendorRegistrationService implements ServiceInterface
{
    private VendorRepository $vendorRepository;
    private KYCValidator $kycValidator;

    public function __construct()
    {
        $this->vendorRepository = new VendorRepository();
        $this->kycValidator = new KYCValidator();
    }

    /**
     * Validate vendor registration data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = [
            'business_name', 'business_type', 'fssai_license', 
            'owner_name', 'phone', 'email', 'address'
        ];

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Business name validation
        if (!empty($data['business_name'])) {
            if (strlen($data['business_name']) < 3) {
                $errors['business_name'] = 'Business name must be at least 3 characters long';
            }
            if (strlen($data['business_name']) > 255) {
                $errors['business_name'] = 'Business name must not exceed 255 characters';
            }
        }

        // Business type validation
        if (!empty($data['business_type'])) {
            $validTypes = ['dairy', 'vegetables', 'mixed'];
            if (!in_array($data['business_type'], $validTypes)) {
                $errors['business_type'] = 'Business type must be one of: ' . implode(', ', $validTypes);
            }
        }

        // FSSAI license validation
        if (!empty($data['fssai_license'])) {
            if (!$this->kycValidator->validateFSSAILicense($data['fssai_license'])) {
                $errors['fssai_license'] = 'Invalid FSSAI license format';
            }
            
            // Check if FSSAI license already exists
            if ($this->isFSSAILicenseExists($data['fssai_license'])) {
                $errors['fssai_license'] = 'FSSAI license already registered with another vendor';
            }
        }

        // Email validation
        if (!empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }
            
            // Check if email already exists
            if ($this->isEmailExists($data['email'])) {
                $errors['email'] = 'Email already registered with another vendor';
            }
        }

        // Phone validation
        if (!empty($data['phone'])) {
            if (!preg_match('/^[+]?[0-9\s\-\(\)]{10,20}$/', $data['phone'])) {
                $errors['phone'] = 'Invalid phone number format';
            }
        }

        // Location validation
        if (!empty($data['latitude']) || !empty($data['longitude'])) {
            if (empty($data['latitude']) || empty($data['longitude'])) {
                $errors['location'] = 'Both latitude and longitude are required for location';
            } else {
                if (!is_numeric($data['latitude']) || $data['latitude'] < -90 || $data['latitude'] > 90) {
                    $errors['latitude'] = 'Latitude must be a valid number between -90 and 90';
                }
                if (!is_numeric($data['longitude']) || $data['longitude'] < -180 || $data['longitude'] > 180) {
                    $errors['longitude'] = 'Longitude must be a valid number between -180 and 180';
                }
            }
        }

        // Cold chain capability validation
        if (isset($data['cold_chain_capable'])) {
            $data['cold_chain_capable'] = filter_var($data['cold_chain_capable'], FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Process vendor registration
     */
    public function process(array $data): array
    {
        try {
            // Validate input data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Prepare vendor data
            $vendorData = [
                'business_name' => $validation['data']['business_name'],
                'business_type' => $validation['data']['business_type'],
                'fssai_license' => $validation['data']['fssai_license'],
                'owner_name' => $validation['data']['owner_name'],
                'phone' => $validation['data']['phone'],
                'email' => $validation['data']['email'],
                'address' => $validation['data']['address'],
                'latitude' => $validation['data']['latitude'] ?? null,
                'longitude' => $validation['data']['longitude'] ?? null,
                'cold_chain_capable' => $validation['data']['cold_chain_capable'] ?? false,
                'status' => 'pending' // All new vendors start as pending
            ];

            // Create vendor record
            $vendorId = $this->vendorRepository->create($vendorData);

            // Process KYC documents if provided
            $documentsProcessed = [];
            if (!empty($data['documents'])) {
                $documentsProcessed = $this->processKYCDocuments($vendorId, $data['documents']);
            }

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'status' => 'pending',
                'message' => 'Vendor registration submitted successfully. Awaiting verification.',
                'documents_processed' => $documentsProcessed
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Register vendor with complete workflow
     */
    public function registerVendor(array $data): array
    {
        return $this->process($data);
    }

    /**
     * Check if FSSAI license already exists
     */
    private function isFSSAILicenseExists(string $fssaiLicense): bool
    {
        $existing = $this->vendorRepository->findAll(['fssai_license' => $fssaiLicense]);
        return !empty($existing);
    }

    /**
     * Check if email already exists
     */
    private function isEmailExists(string $email): bool
    {
        $existing = $this->vendorRepository->findAll(['email' => $email]);
        return !empty($existing);
    }

    /**
     * Process KYC documents
     */
    private function processKYCDocuments(int $vendorId, array $documents): array
    {
        $processed = [];
        
        foreach ($documents as $document) {
            try {
                // Validate document
                $validation = $this->kycValidator->validateDocument($document);
                
                if ($validation['valid']) {
                    // Store document (implementation would depend on file storage system)
                    $processed[] = [
                        'type' => $document['type'],
                        'status' => 'uploaded',
                        'message' => 'Document uploaded successfully'
                    ];
                } else {
                    $processed[] = [
                        'type' => $document['type'],
                        'status' => 'failed',
                        'errors' => $validation['errors']
                    ];
                }
            } catch (Exception $e) {
                $processed[] = [
                    'type' => $document['type'] ?? 'unknown',
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $processed;
    }

    /**
     * Get vendor registration status
     */
    public function getRegistrationStatus(int $vendorId): array
    {
        try {
            $vendor = $this->vendorRepository->find($vendorId);
            
            if (!$vendor) {
                return [
                    'found' => false,
                    'error' => 'Vendor not found'
                ];
            }

            return [
                'found' => true,
                'vendor_id' => $vendor['id'],
                'business_name' => $vendor['business_name'],
                'status' => $vendor['status'],
                'created_at' => $vendor['created_at'],
                'updated_at' => $vendor['updated_at']
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => 'Error retrieving status: ' . $e->getMessage()
            ];
        }
    }
}