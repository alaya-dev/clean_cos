<?php

namespace App\Console\Commands;

use App\Domain\Audit\Actions\PruneAuditLogsAction;
use Illuminate\Console\Command;
use Throwable;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune-retention {--dry-run : Report eligible audit logs without deleting them}';

    protected $description = 'Prune audit logs older than the approved retention period in bounded chunks.';

    public function handle(PruneAuditLogsAction $pruner): int
    {
        if (! $this->option('dry-run') && config('app.env') === 'production' && ! config('operations.pruning_enabled')) {
            $this->components->error('La purge de production exige OPERATIONS_PRUNING_ENABLED=true. Utilisez --dry-run pour la prévisualisation.');

            return self::FAILURE;
        }

        try {
            $result = $pruner->handle((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('La purge du journal d’audit a échoué. Consultez le monitoring sécurisé.');

            return self::FAILURE;
        }

        $this->line(sprintf('audit_logs: %d éligible(s), %d supprimé(s)', $result['eligible'], $result['deleted']));

        return self::SUCCESS;
    }
}
