<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add nullable wallet FK columns after matching *_balance_after columns
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('sender_wallet_id')
                ->nullable()
                ->after('sender_wallet_balance_after')
                ->constrained('wallets')
                ->restrictOnDelete();

            $table->foreignId('recipient_wallet_id')
                ->nullable()
                ->after('recipient_wallet_balance_after')
                ->constrained('wallets')
                ->restrictOnDelete();

            $table->foreignId('agent_wallet_id')
                ->nullable()
                ->after('agent_wallet_balance_after')
                ->constrained('wallets')
                ->restrictOnDelete();
        });

        // 2. Perform backfill mapping transactions to participating wallets
        $this->performBackfill();

        // 3. Legacy row policy:
        // If a transaction participant lacks a wallet of the required kind (for example, a user who held
        // both USER and AGENT roles prior to the multi-wallet migration whose sole wallet was backfilled as AGENT),
        // we deliberately leave those slots NULL. Fabricating 0.00 wallets would falsify the audit trail,
        // because those historical transactions moved balances in their original pre-migration wallet.
        // Unlinked legacy slots remain NULL and are audited below.
        $unlinkedSummary = $this->getUnlinkedSlotsSummary();
        if ($unlinkedSummary['total'] > 0) {
            Log::warning('add_wallet_ids_to_transactions_table migration: transactions remain with unlinked wallet slots', $unlinkedSummary);
        }
    }

    /**
     * Execute slot-by-slot updates joining transactions with wallets on (user_id, kind).
     */
    public function performBackfill(): void
    {
        // TOP_UP: recipient_wallet_id -> user's USER wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.recipient_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'USER');
            })
            ->where('transactions.type', 'TOP_UP')
            ->whereNull('transactions.recipient_wallet_id')
            ->update(['transactions.recipient_wallet_id' => DB::raw('wallets.id')]);

        // TRANSFER: sender_wallet_id -> sender's USER wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.sender_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'USER');
            })
            ->where('transactions.type', 'TRANSFER')
            ->whereNull('transactions.sender_wallet_id')
            ->update(['transactions.sender_wallet_id' => DB::raw('wallets.id')]);

        // TRANSFER: recipient_wallet_id -> recipient's USER wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.recipient_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'USER');
            })
            ->where('transactions.type', 'TRANSFER')
            ->whereNull('transactions.recipient_wallet_id')
            ->update(['transactions.recipient_wallet_id' => DB::raw('wallets.id')]);

        // CASH_IN: recipient_wallet_id -> user's USER wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.recipient_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'USER');
            })
            ->where('transactions.type', 'CASH_IN')
            ->whereNull('transactions.recipient_wallet_id')
            ->update(['transactions.recipient_wallet_id' => DB::raw('wallets.id')]);

        // CASH_IN: agent_wallet_id -> agent's AGENT wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.agent_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'AGENT');
            })
            ->where('transactions.type', 'CASH_IN')
            ->whereNull('transactions.agent_wallet_id')
            ->update(['transactions.agent_wallet_id' => DB::raw('wallets.id')]);

        // CASH_OUT: sender_wallet_id -> user's USER wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.sender_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'USER');
            })
            ->where('transactions.type', 'CASH_OUT')
            ->whereNull('transactions.sender_wallet_id')
            ->update(['transactions.sender_wallet_id' => DB::raw('wallets.id')]);

        // CASH_OUT: agent_wallet_id -> agent's AGENT wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.agent_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'AGENT');
            })
            ->where('transactions.type', 'CASH_OUT')
            ->whereNull('transactions.agent_wallet_id')
            ->update(['transactions.agent_wallet_id' => DB::raw('wallets.id')]);

        // AGENT_WITHDRAWAL: sender_wallet_id -> agent's AGENT wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.sender_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'AGENT');
            })
            ->where('transactions.type', 'AGENT_WITHDRAWAL')
            ->whereNull('transactions.sender_wallet_id')
            ->update(['transactions.sender_wallet_id' => DB::raw('wallets.id')]);

        // COMMISSION_PAYOUT: recipient_wallet_id -> agent's AGENT wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.recipient_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'AGENT');
            })
            ->where('transactions.type', 'COMMISSION_PAYOUT')
            ->whereNull('transactions.recipient_wallet_id')
            ->update(['transactions.recipient_wallet_id' => DB::raw('wallets.id')]);

        // COMMISSION_PAYOUT: agent_wallet_id -> agent's AGENT wallet
        DB::table('transactions')
            ->join('wallets', function ($join) {
                $join->on('transactions.agent_id', '=', 'wallets.user_id')
                    ->where('wallets.kind', '=', 'AGENT');
            })
            ->where('transactions.type', 'COMMISSION_PAYOUT')
            ->whereNull('transactions.agent_wallet_id')
            ->update(['transactions.agent_wallet_id' => DB::raw('wallets.id')]);
    }

    /**
     * Audit transaction rows where a required wallet slot remains null.
     *
     * @return array{total: int, by_type: array<string, int>}
     */
    public function getUnlinkedSlotsSummary(): array
    {
        $byType = [
            'TOP_UP' => DB::table('transactions')
                ->where('type', 'TOP_UP')
                ->whereNull('recipient_wallet_id')
                ->count(),
            'TRANSFER' => DB::table('transactions')
                ->where('type', 'TRANSFER')
                ->where(fn ($w) => $w->whereNull('sender_wallet_id')->orWhereNull('recipient_wallet_id'))
                ->count(),
            'CASH_IN' => DB::table('transactions')
                ->where('type', 'CASH_IN')
                ->where(fn ($w) => $w->whereNull('recipient_wallet_id')->orWhereNull('agent_wallet_id'))
                ->count(),
            'CASH_OUT' => DB::table('transactions')
                ->where('type', 'CASH_OUT')
                ->where(fn ($w) => $w->whereNull('sender_wallet_id')->orWhereNull('agent_wallet_id'))
                ->count(),
            'AGENT_WITHDRAWAL' => DB::table('transactions')
                ->where('type', 'AGENT_WITHDRAWAL')
                ->whereNull('sender_wallet_id')
                ->count(),
            'COMMISSION_PAYOUT' => DB::table('transactions')
                ->where('type', 'COMMISSION_PAYOUT')
                ->where(fn ($w) => $w->whereNull('recipient_wallet_id')->orWhereNull('agent_wallet_id'))
                ->count(),
        ];

        return [
            'total' => array_sum($byType),
            'by_type' => $byType,
        ];
    }

    /**
     * Count transaction rows where a required wallet slot remains null.
     */
    public function countUnlinkedRequiredSlots(): int
    {
        return DB::table('transactions')
            ->where(function ($q) {
                $q->where(fn ($sub) => $sub->where('type', 'TOP_UP')->whereNull('recipient_wallet_id'))
                    ->orWhere(fn ($sub) => $sub->where('type', 'TRANSFER')->where(fn ($w) => $w->whereNull('sender_wallet_id')->orWhereNull('recipient_wallet_id')))
                    ->orWhere(fn ($sub) => $sub->where('type', 'CASH_IN')->where(fn ($w) => $w->whereNull('recipient_wallet_id')->orWhereNull('agent_wallet_id')))
                    ->orWhere(fn ($sub) => $sub->where('type', 'CASH_OUT')->where(fn ($w) => $w->whereNull('sender_wallet_id')->orWhereNull('agent_wallet_id')))
                    ->orWhere(fn ($sub) => $sub->where('type', 'AGENT_WITHDRAWAL')->whereNull('sender_wallet_id'))
                    ->orWhere(fn ($sub) => $sub->where('type', 'COMMISSION_PAYOUT')->where(fn ($w) => $w->whereNull('recipient_wallet_id')->orWhereNull('agent_wallet_id')));
            })
            ->count();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['sender_wallet_id']);
            $table->dropForeign(['recipient_wallet_id']);
            $table->dropForeign(['agent_wallet_id']);

            $table->dropColumn([
                'sender_wallet_id',
                'recipient_wallet_id',
                'agent_wallet_id',
            ]);
        });
    }
};
