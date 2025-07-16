<?php

namespace Antinna\Multivendor\Exceptions;

use Exception;

class ConflictException extends Exception
{
    public function __construct(string $resource = 'Resource', string $message = '')
    {
        $defaultMessage = "{$resource} already exists or conflicts with existing data";
        parent::__construct($message ?: $defaultMessage);
    }
}