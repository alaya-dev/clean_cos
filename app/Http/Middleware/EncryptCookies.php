<?php

namespace App\Http\Middleware;

class EncryptCookies extends \Illuminate\Cookie\Middleware\EncryptCookies
{
    /** @var array<int, string> */
    protected $except = [
        // Browser-owned Meta attribution cookies must remain readable unchanged.
        '_fbp',
        '_fbc',
    ];
}
