<?php

namespace App\Exceptions;

use Exception;

class NorthCloudUnavailableException extends Exception
{
    public function __construct(string $message = 'North Cloud services are currently unavailable')
    {
        parent::__construct($message);
    }
}
