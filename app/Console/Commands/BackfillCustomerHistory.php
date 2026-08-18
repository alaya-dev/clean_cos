<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Services\CustomerHistoryBackfillService;
use Illuminate\Console\Command;
use Throwable;

class BackfillCustomerHistory extends Command
{
    protected $signature = 'customers:backfill-from-orders {--dry-run : Report what would be linked without changing data} {--chunk=500 : Number of orders processed per transaction}';

    protected $description = 'Build customer profiles and historical order links from existing orders.';

    public function handle(CustomerHistoryBackfillService $backfill): int
    {
        $chunkSize = max(1, min(5000, (int) $this->option('chunk')));
        try {
            $stats = $backfill->handle((bool) $this->option('dry-run'), $chunkSize);
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Le backfill des profils clients a échoué. Consultez le monitoring sécurisé.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d commande(s) analysée(s), %d liée(s), %d profil(s) créé(s), %d profil(s) mis à jour, %d téléphone(s) ignoré(s).',
            $stats['orders_scanned'],
            $stats['orders_linked'],
            $stats['customers_created'],
            $stats['customers_updated'],
            $stats['orders_skipped_invalid_phone'],
        ));

        return self::SUCCESS;
    }
}
