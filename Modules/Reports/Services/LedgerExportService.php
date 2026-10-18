<?php

namespace Modules\Reports\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Writes the admin ledger as CSV, row by row, so memory stays flat however many rows are exported.
 */
class LedgerExportService
{
    /**
     * @var list<string>
     */
    public const array COLUMNS = [
        'id', 'created_at', 'type', 'status', 'amount', 'fee', 'commission', 'currency',
        'sender_name', 'sender_phone', 'recipient_name', 'recipient_phone', 'agent_name', 'agent_phone',
        'external_reference', 'reversed_transaction_id', 'description',
    ];

    /**
     * Stream the rows of the query to the output handle.
     *
     * @param  Builder<Transaction>  $query
     * @param  resource  $handle
     */
    public function write(Builder $query, $handle): void
    {
        $timezone = (string) config('transactions.business_timezone', 'Asia/Dhaka');

        // UTF-8 byte order mark so spreadsheet apps read Bangla names correctly
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, escape: '');

        $query->with(['sender:id,name,phone_number', 'recipient:id,name,phone_number', 'agent:id,name,phone_number'])
            ->chunkById(1000, function ($transactions) use ($handle, $timezone): void {
                foreach ($transactions as $transaction) {
                    fputcsv($handle, array_map($this->safe(...), [
                        $transaction->id,
                        $transaction->created_at?->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        $transaction->type,
                        $transaction->status,
                        $transaction->amount,
                        $transaction->system_fee_amount,
                        $transaction->agent_commission_amount,
                        $transaction->currency,
                        ...$this->party($transaction->sender),
                        ...$this->party($transaction->recipient),
                        ...$this->party($transaction->agent),
                        $transaction->external_reference,
                        $transaction->reversed_transaction_id,
                        $transaction->description,
                    ]), escape: '');
                }
            });
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function party(?User $user): array
    {
        return [$user?->name, $user?->phone_number];
    }

    /**
     * Neutralise spreadsheet formulas: a cell starting with = + - @ or a control character is prefixed
     * with a quote so it is shown as text instead of executed (CSV injection).
     */
    private function safe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 && ! is_numeric($text) ? "'".$text : $text;
    }
}
