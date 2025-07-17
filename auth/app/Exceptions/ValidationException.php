<?php

namespace Antinna\Auth\Exceptions;

use Exception;

/**
 * Validation Exception for input validation errors
 */
class ValidationException extends Exception
{
    private array $errors;
    private string $field;

    public function __construct(string $message, array $errors = [], string $field = '', int $code = 1101, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
        $this->field = $field;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get field that failed validation
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get error response array
     */
    public function getErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => $this->getMessage(),
                'field' => $this->field,
                'errors' => $this->errors,
                'timestamp' => date('c')
            ]
        ];
    }

    /**
     * Create validation exception for required field
     */
    public static function requiredField(string $field): self
    {
        return new self(
            "The {$field} field is required",
            [$field => ['required' => 'This field is required']],
            $field,
            1101
        );
    }

    /**
     * Create validation exception for invalid format
     */
    public static function invalidFormat(string $field, string $expectedFormat): self
    {
        return new self(
            "The {$field} field has an invalid format. Expected: {$expectedFormat}",
            [$field => ['format' => "Invalid format. Expected: {$expectedFormat}"]],
            $field,
            1102
        );
    }

    /**
     * Create validation exception for invalid email
     */
    public static function invalidEmail(string $field = 'email'): self
    {
        return new self(
            "The {$field} field must be a valid email address",
            [$field => ['email' => 'Must be a valid email address']],
            $field,
            1103
        );
    }

    /**
     * Create validation exception for weak password
     */
    public static function weakPassword(): self
    {
        return new self(
            'Password does not meet security requirements',
            ['password' => [
                'strength' => 'Password must be at least 8 characters long and contain uppercase, lowercase, numbers, and special characters'
            ]],
            'password',
            1104
        );
    }

    /**
     * Create validation exception for multiple fields
     */
    public static function multipleFields(array $errors): self
    {
        $fields = array_keys($errors);
        $message = 'Validation failed for fields: ' . implode(', ', $fields);
        
        return new self($message, $errors, '', 1105);
    }
}