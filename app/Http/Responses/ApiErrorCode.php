<?php

namespace App\Http\Responses;

class ApiErrorCode
{
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const CHECKOUT_IDEMPOTENCY_CONFLICT = 'CHECKOUT_IDEMPOTENCY_CONFLICT';

    public const CHECKOUT_FIELDS_CHANGED = 'CHECKOUT_FIELDS_CHANGED';

    public const PRODUCT_UNAVAILABLE = 'PRODUCT_UNAVAILABLE';

    public const VARIANT_UNAVAILABLE = 'VARIANT_UNAVAILABLE';

    public const INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';
}
