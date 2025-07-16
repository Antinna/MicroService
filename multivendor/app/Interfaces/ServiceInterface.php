<?php

namespace Antinna\MultiVendor\Interfaces;

/**
 * Base service interface for business logic operations
 */
interface ServiceInterface
{
    /**
     * Validate input data
     */
    public function validate(array $data): array;

    /**
     * Process business logic
     */
    public function process(array $data): array;
}