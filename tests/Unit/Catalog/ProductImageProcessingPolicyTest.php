<?php

namespace Tests\Unit\Catalog;

use App\Jobs\ProcessProductImage;
use PHPUnit\Framework\TestCase;

class ProductImageProcessingPolicyTest extends TestCase
{
    public function test_image_job_uses_bounded_media_policy(): void
    {
        $job = new ProcessProductImage(42);
        self::assertSame(3, $job->tries);
        self::assertSame(120, $job->timeout);
        self::assertSame([10, 30, 60], $job->backoff);
        self::assertSame('media', $job->queue);
    }
}
