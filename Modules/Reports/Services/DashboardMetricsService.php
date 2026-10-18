<?php

namespace Modules\Reports\Services;

use App\Models\ApprovalRequest;
use App\Models\FloatRequest;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the admin dashboard, grouped by business day (config transactions.business_timezone).
 *
 * Only COMPLETED transactions count. Reversals are netted out: fees refunded, commission and signup
 * bonuses clawed back reduce the totals, so the numbers match what the ledger actually kept.
 */
class DashboardMetricsService
{
    /**
     * Types whose amount is reported as volume.
     *
     * @var list<string>
     */
    public const array VOLUME_TYPES = ['TOP_UP', 'TRANSFER', 'CASH_IN', 'CASH_OUT', 'AGENT_WITHDRAWAL', 'FLOAT_ISSUE', 'SIGNUP_BONUS', 'REVERSAL'];

    /**
     * @return array<string, mixed>
     */
    public function overview(int $days): array
    {
        $timezone = (string) config('transactions.business_timezone', 'Asia/Dhaka');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $firstDay = $today->subDays($days - 1);
        $from = $firstDay->utc();
        // Fixed offset for grouping; correct for zones without daylight saving such as Asia/Dhaka
        $offset = $today->format('P');

        $rows = DB::table('transactions')
            ->selectRaw("DATE(CONVERT_TZ(created_at, '+00:00', ?)) as day, type, COUNT(*) as count, SUM(amount) as amount, SUM(system_fee_amount) as fees, SUM(agent_commission_amount) as commission", [$offset])
            ->where('status', 'COMPLETED')
            ->where('created_at', '>=', $from)
            ->groupBy('day', 'type')
            ->get();

        $signups = DB::table('users')
            ->selectRaw("DATE(CONVERT_TZ(created_at, '+00:00', ?)) as day, COUNT(*) as count", [$offset])
            ->where('created_at', '>=', $from)
            ->where('is_deleted', false)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->whereColumn('user_roles.user_id', 'users.id')
                ->whereIn('roles.name', ['USER', 'AGENT']))
            ->groupBy('day')
            ->pluck('count', 'day');

        $daily = [];
        for ($day = $firstDay; $day->lte($today); $day = $day->addDay()) {
            $key = $day->toDateString();
            $daily[$key] = ['date' => $key, 'volume' => 0.0, 'count' => 0, 'by_type' => [], 'signups' => (int) ($signups[$key] ?? 0)];
        }

        $totals = ['by_type' => array_fill_keys(self::VOLUME_TYPES, ['count' => 0, 'amount' => 0.0])];
        foreach ($rows as $row) {
            $key = (string) $row->day;
            if (! isset($daily[$key])) {
                continue;
            }
            $amount = (float) $row->amount;
            $daily[$key]['by_type'][$row->type] = ['count' => (int) $row->count, 'amount' => $amount];
            // Memo rows and reversals are not new money moving
            if (! in_array($row->type, ['COMMISSION_PAYOUT', 'REVERSAL'], true)) {
                $daily[$key]['volume'] += $amount;
                $daily[$key]['count'] += (int) $row->count;
            }
            if (isset($totals['by_type'][$row->type])) {
                $totals['by_type'][$row->type]['count'] += (int) $row->count;
                $totals['by_type'][$row->type]['amount'] += $amount;
            }
        }

        return [
            'range' => ['days' => $days, 'from' => $firstDay->toDateString(), 'to' => $today->toDateString(), 'timezone' => $timezone],
            'totals' => [
                ...$totals,
                'volume' => round(array_sum(array_column($daily, 'volume')), 2),
                'transactions' => array_sum(array_column($daily, 'count')),
                'fees_earned' => $this->netFees($from),
                'commission_paid' => $this->netCommission($from),
                'signup_bonus_paid' => $this->netSignupBonus($from),
                'signups' => array_sum(array_column($daily, 'signups')),
            ],
            'daily' => array_values($daily),
            'queues' => $this->queues(),
            'e_money' => $this->eMoney(),
        ];
    }

    /**
     * Fees charged on cash-outs and transfers, less fees refunded by reversals.
     */
    private function netFees(CarbonImmutable $from): float
    {
        return round($this->sum('system_fee_amount', ['CASH_OUT', 'TRANSFER'], $from) - $this->sum('system_fee_amount', ['REVERSAL'], $from), 2);
    }

    /**
     * Commission credited on cash-outs, less commission clawed back by reversals.
     */
    private function netCommission(CarbonImmutable $from): float
    {
        return round($this->sum('agent_commission_amount', ['CASH_OUT'], $from) - $this->sum('agent_commission_amount', ['REVERSAL'], $from), 2);
    }

    /**
     * Signup bonuses paid (backfilled opening balances excluded), less bonuses clawed back.
     */
    private function netSignupBonus(CarbonImmutable $from): float
    {
        $paid = (float) Transaction::where('type', 'SIGNUP_BONUS')
            ->where('status', 'COMPLETED')
            ->where('created_at', '>=', $from)
            ->where(fn ($query) => $query->whereNull('meta->backfilled')->orWhere('meta->backfilled', false))
            ->sum('amount');

        $clawedBack = (float) Transaction::where('type', 'REVERSAL')
            ->where('status', 'COMPLETED')
            ->where('created_at', '>=', $from)
            ->where('meta->reverses->type', 'SIGNUP_BONUS')
            ->sum('amount');

        return round($paid - $clawedBack, 2);
    }

    /**
     * Work waiting for staff right now.
     *
     * @return array<string, array{count: int, amount?: float}>
     */
    private function queues(): array
    {
        $withdrawals = Transaction::where('type', 'AGENT_WITHDRAWAL')->where('status', 'PENDING');
        $topUps = Transaction::where('type', 'TOP_UP')->where('status', 'PENDING')->whereNotNull('external_reference');

        return [
            'pending_withdrawals' => ['count' => (clone $withdrawals)->count(), 'amount' => round((float) $withdrawals->sum('amount'), 2)],
            'pending_float_requests' => ['count' => FloatRequest::where('status', FloatRequest::PENDING)->count()],
            'unfinished_top_ups' => ['count' => $topUps->count()],
            'pending_approvals' => ['count' => ApprovalRequest::where('status', ApprovalRequest::PENDING)->count()],
        ];
    }

    /**
     * E-money held in wallets right now, by wallet kind.
     *
     * @return array{total: float, user_wallets: float, agent_wallets: float}
     */
    private function eMoney(): array
    {
        $byKind = Wallet::query()->selectRaw('kind, SUM(balance) as total')->groupBy('kind')->pluck('total', 'kind');
        $user = round((float) ($byKind[Wallet::KIND_USER] ?? 0), 2);
        $agent = round((float) ($byKind[Wallet::KIND_AGENT] ?? 0), 2);

        return ['total' => round($user + $agent, 2), 'user_wallets' => $user, 'agent_wallets' => $agent];
    }

    /**
     * @param  list<string>  $types
     */
    private function sum(string $column, array $types, CarbonImmutable $from): float
    {
        return (float) Transaction::whereIn('type', $types)
            ->where('status', 'COMPLETED')
            ->where('created_at', '>=', $from)
            ->sum($column);
    }
}
