<?php

namespace Antinna\MultiVendor\Services;

/**
 * KYC document validation service
 */
class KYCValidator
{
    /**
     * Validate FSSAI license number
     */
    public function validateFSSAILicense(string $license): bool
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
     * Validate KYC document
     */
    public function validateDocument(array $document): array
    {
        $errors = [];

        // Required fields
        if (empty($document['type'])) {
            $errors['type'] = 'Document type is required';
        }

        if (empty($document['number']) && empty($document['file'])) {
            $errors['content'] = 'Either document number or file is required';
        }

        // Validate document type
        if (!empty($document['type'])) {
            $validTypes = ['kyc', 'fssai', 'business_license', 'tax_certificate'];
            if (!in_array($document['type'], $validTypes)) {
                $errors['type'] = 'Invalid document type';
            }
        }

        // Type-specific validations
        if (!empty($document['type']) && !empty($document['number'])) {
            switch ($document['type']) {
                case 'fssai':
                    if (!$this->validateFSSAILicense($document['number'])) {
                        $errors['number'] = 'Invalid FSSAI license number format';
                    }
                    break;
                    
                case 'business_license':
                    if (strlen($document['number']) < 5) {
                        $errors['number'] = 'Business license number too short';
                    }
                    break;
                    
                case 'tax_certificate':
                    // Basic PAN or GST validation
                    if (!$this->validateTaxNumber($document['number'])) {
                        $errors['number'] = 'Invalid tax certificate number format';
                    }
                    break;
            }
        }

        // File validation if provided
        if (!empty($document['file'])) {
            $fileValidation = $this->validateDocumentFile($document['file']);
            if (!$fileValidation['valid']) {
                $errors['file'] = $fileValidation['error'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate tax number (PAN/GST)
     */
    private function validateTaxNumber(string $number): bool
    {
        $number = strtoupper(str_replace(' ', '', $number));
        
        // PAN format: ABCDE1234F
        if (preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $number)) {
            return true;
        }
        
        // GST format: 15 characters
        if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $number)) {
            return true;
        }
        
        return false;
    }

    /**
     * Validate document file
     */
    private function validateDocumentFile(array $file): array
    {
        // Check if file was uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return [
                'valid' => false,
                'error' => 'No file uploaded or invalid file'
            ];
        }

        // Check file size (max 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            return [
                'valid' => false,
                'error' => 'File size exceeds 5MB limit'
            ];
        }

        // Check file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            return [
                'valid' => false,
                'error' => 'Invalid file type. Only JPEG, PNG, and PDF files are allowed'
            ];
        }

        return [
            'valid' => true,
            'mime_type' => $mimeType,
            'size' => $file['size']
        ];
    }

    /**
     * Verify FSSAI license with external API (placeholder)
     */
    public function verifyFSSAILicenseOnline(string $license): array
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
     * Get document requirements for business type
     */
    public function getDocumentRequirements(string $businessType): array
    {
        $baseRequirements = [
            'fssai' => [
                'required' => true,
                'description' => 'FSSAI License Certificate',
                'formats' => ['PDF', 'JPEG', 'PNG']
            ],
            'business_license' => [
                'required' => true,
                'description' => 'Business Registration Certificate',
                'formats' => ['PDF', 'JPEG', 'PNG']
            ]
        ];

        switch ($businessType) {
            case 'dairy':
                $baseRequirements['dairy_license'] = [
                    'required' => true,
                    'description' => 'Dairy License from Local Authority',
                    'formats' => ['PDF', 'JPEG', 'PNG']
                ];
                break;
                
            case 'vegetables':
                $baseRequirements['agriculture_license'] = [
                    'required' => false,
                    'description' => 'Agriculture Produce License (if applicable)',
                    'formats' => ['PDF', 'JPEG', 'PNG']
                ];
                break;
                
            case 'mixed':
                $baseRequirements['dairy_license'] = [
                    'required' => true,
                    'description' => 'Dairy License from Local Authority',
                    'formats' => ['PDF', 'JPEG', 'PNG']
                ];
                $baseRequirements['agriculture_license'] = [
                    'required' => false,
                    'description' => 'Agriculture Produce License (if applicable)',
                    'formats' => ['PDF', 'JPEG', 'PNG']
                ];
                break;
        }

        return $baseRequirements;
    }
}