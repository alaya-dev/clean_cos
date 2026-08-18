<?php

namespace App\Jobs;

use App\Domain\Operations\Services\OperationalHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OperationalQueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(config('operations.queue_heartbeat_queue'));
    }

    public function handle(OperationalHealth $health): void
    {
        $health->touchQueueWorker();
    }
}
