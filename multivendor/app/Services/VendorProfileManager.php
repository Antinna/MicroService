<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Interfaces\ServiceInterface;
use Antinna\MultiVendor\Repositories\VendorRepository;
use Antinna\MultiVendor\Services\AuditTrailService;
use Exception;

/**
 * Vendor profile management service
 */
class VendorProfileManager implements ServiceInterface
{
    private VendorRepository $vendorRepository;
    private AuditTrailService $auditTrail;

    public function __construct()
    {
        $this->vendorRepository = new VendorRepository();
        $this->auditTrail = new AuditTrailService();
    }

    /**
     * Validate profile update data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Business name validation
        if (isset($data['business_name'])) {
            if (empty($data['business_name'])) {
                $errors['business_name'] = 'Business name cannot be empty';
            } elseif (strlen($data['business_name']) < 3) {
                $errors['business_name'] = 'Business name must be at least 3 characters long';
            } elseif (strlen($data['business_name']) > 255) {
                $errors['business_name'] = 'Business name must not exceed 255 characters';
            }
        }

        // Business type validation
        if (isset($data['business_type'])) {
            $validTypes = ['dairy', 'vegetables', 'mixed'];
            if (!in_array($data['business_type'], $validTypes)) {
                $errors['business_type'] = 'Business type must be one of: ' . implode(', ', $validTypes);
            }
        }

        // Email validation
        if (isset($data['email'])) {
            if (empty($data['email'])) {
                $errors['email'] = 'Email cannot be empty';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }
        }

        // Phone validation
        if (isset($data['phone'])) {
            if (empty($data['phone'])) {
                $errors['phone'] = 'Phone cannot be empty';
            } elseif (!preg_match('/^[+]?[0-9\s\-\(\)]{10,20}$/', $data['phone'])) {
                $errors['phone'] = 'Invalid phone number format';
            }
        }

        // Address validation
        if (isset($data['address'])) {
            if (empty($data['address'])) {
                $errors['address'] = 'Address cannot be empty';
            } elseif (strlen($data['address']) < 10) {
                $errors['address'] = 'Address must be at least 10 characters long';
            }
        }

        // Location validation
        if (isset($data['latitude']) || isset($data['longitude'])) {
            if (isset($data['latitude']) && isset($data['longitude'])) {
                if (!is_numeric($data['latitude']) || $data['latitude'] < -90 || $data['latitude'] > 90) {
                    $errors['latitude'] = 'Latitude must be a valid number between -90 and 90';
                }
                if (!is_numeric($data['longitude']) || $data['longitude'] < -180 || $data['longitude'] > 180) {
                    $errors['longitude'] = 'Longitude must be a valid number between -180 and 180';
                }
            } else {
                $errors['location'] = 'Both latitude and longitude are required for location updates';
            }
        }

        // Cold chain capability validation
        if (isset($data['cold_chain_capable'])) {
            $data['cold_chain_capable'] = filter_var($data['cold_chain_capable'], FILTER_VALIDATE_BOOLEAN);
        }

        // Status validation (only certain statuses can be set)
        if (isset($data['status'])) {
            $validStatuses = ['pending', 'active', 'suspended'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors['status'] = 'Invalid status. Must be one of: ' . implode(', ', $validStatuses);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Process profile update
     */
    public function process(array $data): array
    {
        // This method is required by ServiceInterface but not used directly
        // Use updateProfile method instead
        return $this->updateProfile($data['vendor_id'], $data);
    }

    /**
     * Update vendor profile
     */
    public function updateProfile(int $vendorId, array $data, ?int $updatedBy = null): array
    {
        try {
            // Check if vendor exists
            $existingVendor = $this->vendorRepository->find($vendorId);
            if (!$existingVendor) {
                return [
                    'success' => false,
                    'error' => 'Vendor not found'
                ];
            }

            // Validate update data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for email uniqueness if email is being updated
            if (isset($data['email']) && $data['email'] !== $existingVendor['email']) {
                if ($this->isEmailExists($data['email'], $vendorId)) {
                    return [
                        'success' => false,
                        'errors' => ['email' => 'Email already exists for another vendor']
                    ];
                }
            }

            // Prepare update data (only include fields that are being updated)
            $updateData = [];
            $allowedFields = [
                'business_name', 'business_type', 'owner_name', 'phone', 
                'email', 'address', 'latitude', 'longitude', 
                'cold_chain_capable', 'status'
            ];

            foreach ($allowedFields as $field) {
                if (isset($validation['data'][$field])) {
                    $updateData[$field] = $validation['data'][$field];
                }
            }

            // Track changes for audit trail
            $changes = $this->getChanges($existingVendor, $updateData);

            // Update vendor profile
            $success = $this->vendorRepository->update($vendorId, $updateData);

            if ($success) {
                // Log audit trail
                $this->auditTrail->logProfileUpdate($vendorId, $changes, $updatedBy);

                return [
                    'success' => true,
                    'message' => 'Profile updated successfully',
                    'changes' => $changes
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update profile'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Profile update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get vendor profile
     */
    public function getProfile(int $vendorId): array
    {
        try {
            $vendor = $this->vendorRepository->find($vendorId);
            
            if (!$vendor) {
                return [
                    'found' => false,
                    'error' => 'Vendor not found'
                ];
            }

            // Remove sensitive information
            unset($vendor['created_at'], $vendor['updated_at']);

            return [
                'found' => true,
                'profile' => $vendor
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => 'Error retrieving profile: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get profile update history
     */
    public function getUpdateHistory(int $vendorId, int $limit = 50): array
    {
        try {
            return $this->auditTrail->getProfileHistory($vendorId, $limit);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving update history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Activate vendor profile
     */
    public function activateProfile(int $vendorId, ?int $activatedBy = null): array
    {
        return $this->updateProfile($vendorId, ['status' => 'active'], $activatedBy);
    }

    /**
     * Suspend vendor profile
     */
    public function suspendProfile(int $vendorId, string $reason, ?int $suspendedBy = null): array
    {
        $result = $this->updateProfile($vendorId, ['status' => 'suspended'], $suspendedBy);
        
        if ($result['success']) {
            // Log suspension reason
            $this->auditTrail->logProfileSuspension($vendorId, $reason, $suspendedBy);
        }
        
        return $result;
    }

    /**
     * Validate profile completeness
     */
    public function validateCompleteness(int $vendorId): array
    {
        try {
            $vendor = $this->vendorRepository->find($vendorId);
            
            if (!$vendor) {
                return [
                    'complete' => false,
                    'error' => 'Vendor not found'
                ];
            }

            $requiredFields = [
                'business_name', 'business_type', 'fssai_license',
                'owner_name', 'phone', 'email', 'address'
            ];

            $missingFields = [];
            $completionPercentage = 0;
            $totalFields = count($requiredFields) + 2; // +2 for location

            foreach ($requiredFields as $field) {
                if (empty($vendor[$field])) {
                    $missingFields[] = $field;
                } else {
                    $completionPercentage++;
                }
            }

            // Check location
            if (!empty($vendor['latitude']) && !empty($vendor['longitude'])) {
                $completionPercentage += 2;
            } else {
                $missingFields[] = 'location';
            }

            $completionPercentage = round(($completionPercentage / $totalFields) * 100);

            return [
                'complete' => empty($missingFields),
                'completion_percentage' => $completionPercentage,
                'missing_fields' => $missingFields,
                'status' => $vendor['status']
            ];

        } catch (Exception $e) {
            return [
                'complete' => false,
                'error' => 'Error validating completeness: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if email exists for another vendor
     */
    private function isEmailExists(string $email, int $excludeVendorId): bool
    {
        $existing = $this->vendorRepository->findAll(['email' => $email]);
        
        foreach ($existing as $vendor) {
            if ($vendor['id'] != $excludeVendorId) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get changes between old and new data
     */
    private function getChanges(array $oldData, array $newData): array
    {
        $changes = [];
        
        foreach ($newData as $field => $newValue) {
            $oldValue = $oldData[$field] ?? null;
            
            if ($oldValue != $newValue) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }
        
        return $changes;
    }
}