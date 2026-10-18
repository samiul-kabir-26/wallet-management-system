<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Wallets\Services\OpeningBalanceBackfillService;

class BackfillOpeningBalancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:backfill-opening-balances
                            {--apply : Write the rows (without it, only report what would be written)}
                            {--force : Skip the confirmation prompt in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record the missing ledger row for wallets that got the legacy 50.00 opening balance from a column default';

    /**
     * Execute the console command.
     */
    public function handle(OpeningBalanceBackfillService $backfill): int
    {
        $candidates = $backfill->candidates();

        if ($candidates === []) {
            $this->info('No wallets need an opening-balance row.');

            return self::SUCCESS;
        }

        $this->table(
            ['Wallet', 'User', 'Kind', 'Balance', 'Ledger', 'Missing'],
            array_map(fn (array $row): array => [
                $row['wallet_id'], $row['user_id'], $row['kind'], $row['stored_balance'], $row['ledger_balance'], $row['difference'],
            ], $candidates),
        );

        if (! $this->option('apply')) {
            $this->warn('Dry run: '.count($candidates).' wallet(s) would get a SIGNUP_BONUS row (balances are not changed). Re-run with --apply to write them.');

            return self::SUCCESS;
        }

        // The SIGNUP_BONUS transaction type is added by a migration; writing before it runs fails mid-way
        $pending = $this->pendingMigrations();
        if ($pending !== []) {
            $this->error('Run the pending migrations first (php artisan migrate):');
            foreach ($pending as $migration) {
                $this->line("  - {$migration}");
            }

            return self::FAILURE;
        }

        if ($this->laravel->isProduction() && ! $this->option('force')
            && ! $this->confirm('Write '.count($candidates).' opening-balance row(s) to the production ledger?')) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        foreach ($candidates as $candidate) {
            $backfill->backfill($candidate['wallet_id']) ? $written++ : $skipped++;
        }

        Log::info('Opening balances backfilled', ['written' => $written, 'skipped' => $skipped]);
        $this->info("Wrote {$written} opening-balance row(s). Skipped {$skipped} that no longer qualified.");

        return self::SUCCESS;
    }

    /**
     * Migrations in database/migrations that have not run yet.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $migrator = $this->laravel->make('migrator');

        if (! $migrator->repositoryExists()) {
            return array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        }

        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));

        return array_values(array_diff($files, $migrator->getRepository()->getRan()));
    }
}
