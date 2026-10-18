<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Wallets\Services\LedgerIntegrityService;

class VerifyLedgerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:verify-ledger
                            {--wallet=* : Only check these wallet IDs (skips the agent commission check)}
                            {--json : Print the full report as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recompute wallet balances and agent commission totals from the ledger and report any drift';

    /**
     * Execute the console command.
     */
    public function handle(LedgerIntegrityService $ledger): int
    {
        $walletIds = array_map('intval', (array) $this->option('wallet'));
        $report = $ledger->verify($walletIds === [] ? null : $walletIds);

        $unexplained = array_values(array_filter(
            $report['wallet_mismatches'],
            fn (array $mismatch): bool => $mismatch['classification'] === LedgerIntegrityService::UNEXPLAINED,
        ));
        $legacy = count($report['wallet_mismatches']) - count($unexplained);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->renderReport($report, $legacy);
        }

        if ($legacy > 0) {
            Log::warning('Ledger check: wallets carry the legacy opening balance with no ledger row', [
                'wallets' => $legacy,
            ]);
        }

        if (! $report['healthy']) {
            Log::critical('Ledger integrity check failed', [
                'wallet_mismatches' => $unexplained,
                'commission_mismatches' => $report['commission_mismatches'],
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report, int $legacy): void
    {
        $this->info("Wallets checked: {$report['wallets_checked']}. Agents checked: {$report['agents_checked']}.");

        if ($report['wallet_mismatches'] !== []) {
            $this->table(
                ['Wallet', 'User', 'Kind', 'Stored', 'Ledger', 'Difference', 'Classification'],
                array_map(fn (array $row): array => array_values($row), $report['wallet_mismatches']),
            );
        }

        if ($legacy > 0) {
            $this->warn("{$legacy} wallet(s) carry the legacy opening balance with no ledger row (run wallets:backfill-opening-balances).");
        }

        if ($report['commission_mismatches'] !== []) {
            $this->table(
                ['Agent user', 'Stored total', 'Ledger total', 'Difference'],
                array_map(fn (array $row): array => array_values($row), $report['commission_mismatches']),
            );
        }

        $report['healthy']
            ? $this->info('Ledger is consistent.')
            : $this->error('Ledger mismatch detected.');
    }
}
