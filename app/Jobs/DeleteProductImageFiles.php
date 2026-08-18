<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DeleteProductImageFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<int|string, string>|null $renditions */
    public function __construct(
        private readonly ?string $publicPath,
        private readonly ?string $originalPath,
        private readonly ?array $renditions,
    ) {
        $this->onQueue('media');
    }

    public function handle(): void
    {
        Storage::disk('public')->delete(array_unique(array_filter([
            $this->publicPath,
            ...array_values($this->renditions ?? []),
        ])));
        Storage::disk('local')->delete(array_filter([$this->originalPath]));
    }
}
