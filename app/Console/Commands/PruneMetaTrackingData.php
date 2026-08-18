<?php

namespace App\Console\Commands;

use App\Domain\Operations\Services\OperationalDataPruner;
use Illuminate\Console\Command;

class PruneMetaTrackingData extends Command
{
    protected $signature = 'meta:prune-retention';

    protected $description = 'Prune Meta tracking data according to approved retention windows.';

    public function handle(OperationalDataPruner $pruner): int
    {
        if (config('app.env') === 'production' && ! config('operations.pruning_enabled')) {
            $this->components->error('La purge de production exige OPERATIONS_PRUNING_ENABLED=true. Utilisez maintenance:prune-operational-data --dry-run pour la prévisualisation.');

            return self::FAILURE;
        }

        $result = $pruner->handle();
        $this->components->info('Legacy Meta retention alias completed: '.($result['deleted']['meta_succeeded'] + $result['deleted']['meta_terminal']).' events pruned.');

        return self::SUCCESS;
    }
}
