<?php

namespace App\Domain\Content\Services;

use Illuminate\Support\Facades\Cache;

class HomepageCache
{
    public const KEY = 'pc:cache:storefront:home';

    public function forget(): void
    {
        Cache::forget(self::KEY);
        Cache::forget('pc:cache:storefront:layout');
    }
}
