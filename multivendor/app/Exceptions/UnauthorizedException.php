<?php

namespace Antinna\Multivendor\Exceptions;

use Exception;

class UnauthorizedException extends Exception
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message);
    }
}