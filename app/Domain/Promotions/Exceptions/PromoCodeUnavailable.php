<?php

namespace App\Domain\Promotions\Exceptions;

use RuntimeException;

class PromoCodeUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Code promo invalide ou indisponible.');
    }
}
