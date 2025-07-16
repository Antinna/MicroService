<?php

namespace Antinna\Multivendor\Exceptions;

use Exception;

class NotFoundException extends Exception
{
    public function __construct(string $resource = 'Resource', string $identifier = '')
    {
        $message = $identifier 
            ? "{$resource} with identifier '{$identifier}' not found"
            : "{$resource} not found";
            
        parent::__construct($message);
    }
}