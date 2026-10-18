<?php

namespace Modules\Wallets\Services;

use App\Models\Wallet;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every wallet balance from the transactions ledger and reports where the stored balance
 * disagrees. Read-only: it never changes a balance.
 *
 * How each transaction moves money (only rows whose status applied the movement count):
 *
 * | Type              | Status counted        | Effect                                                        |
 * |-------------------|-----------------------|---------------------------------------------------------------|
 * | TOP_UP            | COMPLETED             | recipient wallet + amount (PENDING / FAILED gateway rows: 0)  |
 * | SIGNUP_BONUS      | COMPLETED             | recipient wallet + amount                                     |
 * | FLOAT_ISSUE       | COMPLETED             | recipient (agent) wallet + amount                             |
 * | TRANSFER          | COMPLETED             | sender − (amount + fee), recipient + amount                   |
 * | CASH_IN           | COMPLETED             | recipient + amount, agent wallet − amount                     |
 * | CASH_OUT          | COMPLETED             | sender − (amount + fee), agent wallet + (amount + commission) |
 * | COMMISSION_PAYOUT | —                     | none: a memo row, the commission is credited by CASH_OUT      |
 * | AGENT_WITHDRAWAL  | PENDING or COMPLETED  | sender (agent) wallet − amount (held at request time)         |
 * | REVERSAL          | COMPLETED             | sender − (amount + commission), recipient + (amount + fee)    |
 *
 * A REJECTED withdrawal nets to zero because the refund restores the held amount.
 */
class LedgerIntegrityService
{
    public const string UNEXPLAINED = 'UNEXPLAINED';

    public const string LEGACY_OPENING_BALANCE = 'LEGACY_OPENING_BALANCE';

    /**
     * Compare stored wallet balances and agent commission totals with the ledger.
     *
     * All reads run in one database transaction, so on InnoDB (REPEATABLE READ) they share one
     * consistent snapshot and a transaction committing mid-check cannot cause a false mismatch.
     *
     * @param  list<int>|null  $walletIds  Limit the wallet check to these wallets.
     * @return array{
     *     wallets_checked: int,
     *     wallet_mismatches: list<array{wallet_id: int, user_id: int, kind: string, stored_balance: string, ledger_balance: string, difference: string, classification: string}>,
     *     agents_checked: int,
     *     commission_mismatches: list<array{user_id: int, stored_total: string, ledger_total: string, difference: string}>,
     *     healthy: bool
     * }
     */
    public function verify(?array $walletIds = null): array
    {
        return DB::transaction(function () use ($walletIds): array {
            $walletReport = $this->verifyWallets($walletIds);
            $commissionReport = $walletIds === null ? $this->verifyCommissionTotals() : ['checked' => 0, 'mismatches' => []];

            $unexplained = array_filter(
                $walletReport['mismatches'],
                fn (array $mismatch): bool => $mismatch['classification'] === self::UNEXPLAINED,
            );

            return [
                'wallets_checked' => $walletReport['checked'],
                'wallet_mismatches' => $walletReport['mismatches'],
                'agents_checked' => $commissionReport['checked'],
                'commission_mismatches' => $commissionReport['mismatches'],
                'healthy' => $unexplained === [] && $commissionReport['mismatches'] === [],
            ];
        });
    }

    /**
     * The balance each wallet should have according to the ledger, keyed by wallet id.
     *
     * @param  list<int>|null  $walletIds
     * @return array<int, string>
     */
    public function ledgerBalances(?array $walletIds = null): array
    {
        $totals = [];

        $add = function (Builder $query) use (&$totals): void {
            foreach ($query->get() as $row) {
                $current = $totals[(int) $row->wallet_id] ?? '0.00';
                $totals[(int) $row->wallet_id] = bcadd($current, (string) $row->total, 2);
            }
        };

        // Credits to the recipient wallet; a reversal also refunds the original fee
        $add($this->ledgerQuery('recipient_wallet_id', $walletIds)
            ->selectRaw("recipient_wallet_id as wallet_id, SUM(CASE WHEN type = 'REVERSAL' THEN amount + system_fee_amount ELSE amount END) as total")
            ->where('status', 'COMPLETED')
            ->whereIn('type', ['TOP_UP', 'SIGNUP_BONUS', 'FLOAT_ISSUE', 'TRANSFER', 'CASH_IN', 'REVERSAL']));

        // Debits from the sender wallet, fee included; withdrawals are held from the moment they are requested
        $add($this->ledgerQuery('sender_wallet_id', $walletIds)
            ->selectRaw("sender_wallet_id as wallet_id, -SUM(CASE WHEN type = 'REVERSAL' THEN amount + agent_commission_amount ELSE amount + system_fee_amount END) as total")
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $completed) => $completed
                    ->where('status', 'COMPLETED')
                    ->whereIn('type', ['TRANSFER', 'CASH_OUT', 'REVERSAL']))
                ->orWhere(fn (Builder $withdrawal) => $withdrawal
                    ->where('type', 'AGENT_WITHDRAWAL')
                    ->whereIn('status', ['PENDING', 'COMPLETED']))));

        // The agent side of cash-in (float out) and cash-out (principal plus commission in)
        $add($this->ledgerQuery('agent_wallet_id', $walletIds)
            ->selectRaw("agent_wallet_id as wallet_id, SUM(CASE WHEN type = 'CASH_IN' THEN -amount ELSE amount + agent_commission_amount END) as total")
            ->where('status', 'COMPLETED')
            ->whereIn('type', ['CASH_IN', 'CASH_OUT']));

        return $totals;
    }

    /**
     * @param  list<int>|null  $walletIds
     * @return array{checked: int, mismatches: list<array{wallet_id: int, user_id: int, kind: string, stored_balance: string, ledger_balance: string, difference: string, classification: string}>}
     */
    private function verifyWallets(?array $walletIds): array
    {
        $ledger = $this->ledgerBalances($walletIds);
        $legacyOpeningBalance = number_format((float) config('transactions.ledger_check.legacy_opening_balance', 50.00), 2, '.', '');

        $walletsWithBonus = DB::table('transactions')
            ->where('type', 'SIGNUP_BONUS')
            ->when($walletIds !== null, fn (Builder $query) => $query->whereIn('recipient_wallet_id', $walletIds))
            ->pluck('recipient_wallet_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $checked = 0;
        $mismatches = [];

        Wallet::query()
            ->select(['id', 'user_id', 'kind', 'balance'])
            ->when($walletIds !== null, fn ($query) => $query->whereKey($walletIds))
            ->orderBy('id')
            ->chunk(500, function ($wallets) use ($ledger, $legacyOpeningBalance, $walletsWithBonus, &$checked, &$mismatches): void {
                foreach ($wallets as $wallet) {
                    $checked++;

                    $stored = number_format((float) $wallet->balance, 2, '.', '');
                    $expected = $ledger[$wallet->id] ?? '0.00';
                    $difference = bcsub($stored, $expected, 2);

                    if (bccomp($difference, '0.00', 2) === 0) {
                        continue;
                    }

                    // Wallets opened before the signup bonus became a ledger row got 50.00 from a column default
                    $isLegacyOpeningBalance = bccomp($difference, $legacyOpeningBalance, 2) === 0
                        && ! $walletsWithBonus->has($wallet->id);

                    $mismatches[] = [
                        'wallet_id' => (int) $wallet->id,
                        'user_id' => (int) $wallet->user_id,
                        'kind' => (string) $wallet->kind,
                        'stored_balance' => $stored,
                        'ledger_balance' => $expected,
                        'difference' => $difference,
                        'classification' => $isLegacyOpeningBalance ? self::LEGACY_OPENING_BALANCE : self::UNEXPLAINED,
                    ];
                }
            });

        return ['checked' => $checked, 'mismatches' => $mismatches];
    }

    /**
     * Agent commission totals against the commission recorded on their completed cash-outs.
     *
     * @return array{checked: int, mismatches: list<array{user_id: int, stored_total: string, ledger_total: string, difference: string}>}
     */
    private function verifyCommissionTotals(): array
    {
        // Commission earned on cash-outs, less commission clawed back by reversing them
        $earned = DB::table('transactions')
            ->selectRaw("agent_id, SUM(CASE WHEN type = 'REVERSAL' THEN -agent_commission_amount ELSE agent_commission_amount END) as total")
            ->whereIn('type', ['CASH_OUT', 'REVERSAL'])
            ->where('status', 'COMPLETED')
            ->whereNotNull('agent_id')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $checked = 0;
        $mismatches = [];

        foreach (DB::table('agent_info')->select(['user_id', 'total_commission'])->orderBy('user_id')->cursor() as $agent) {
            $checked++;

            $stored = number_format((float) $agent->total_commission, 2, '.', '');
            $expected = number_format((float) ($earned[$agent->user_id] ?? 0), 2, '.', '');
            $difference = bcsub($stored, $expected, 2);

            if (bccomp($difference, '0.00', 2) !== 0) {
                $mismatches[] = [
                    'user_id' => (int) $agent->user_id,
                    'stored_total' => $stored,
                    'ledger_total' => $expected,
                    'difference' => $difference,
                ];
            }
        }

        return ['checked' => $checked, 'mismatches' => $mismatches];
    }

    /**
     * @param  list<int>|null  $walletIds
     */
    private function ledgerQuery(string $walletColumn, ?array $walletIds): Builder
    {
        return DB::table('transactions')
            ->whereNotNull($walletColumn)
            ->when($walletIds !== null, fn (Builder $query) => $query->whereIn($walletColumn, $walletIds))
            ->groupBy($walletColumn);
    }
}
