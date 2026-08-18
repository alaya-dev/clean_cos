<?php

namespace App\Console\Commands;

use App\Domain\Operations\Services\OperationalDataPruner;
use Illuminate\Console\Command;
use Throwable;

class PruneOperationalData extends Command
{
    protected $signature = 'maintenance:prune-operational-data {--dry-run : Report eligibility without deleting records}';

    protected $description = 'Prune only retention-approved operational records in bounded chunks.';

    public function handle(OperationalDataPruner $pruner): int
    {
        if (! $this->option('dry-run') && config('app.env') === 'production' && ! config('operations.pruning_enabled')) {
            $this->components->error('La purge de production exige OPERATIONS_PRUNING_ENABLED=true. Utilisez --dry-run pour la prévisualisation.');

            return self::FAILURE;
        }
        try {
            $result = $pruner->handle((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('La maintenance opérationnelle a échoué. Consultez le monitoring sécurisé.');

            return self::FAILURE;
        }
        foreach ($result['eligible'] as $type => $count) {
            $this->line(sprintf('%s: %d éligible(s), %d supprimé(s)', $type, $count, $result['deleted'][$type] ?? 0));
        }

        return self::SUCCESS;
    }
}
