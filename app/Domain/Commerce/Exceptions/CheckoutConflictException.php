<?php

namespace App\Domain\Commerce\Exceptions;

use RuntimeException;

class CheckoutConflictException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }
}
