<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * last_reset_date is the business-timezone date the daily/monthly counters belong to.
     * NULL means the counters have never been anchored and are treated as stale.
     */
    public function up(): void
    {
        Schema::table('caps', function (Blueprint $table) {
            $table->date('last_reset_date')->nullable()->after('monthly_used');
        });

        $this->rebuildCurrentUsageFromLedger();
    }

    /**
     * Existing counters were never reset reliably, so recompute them from the transaction ledger
     * for the current business day and month, and anchor every row to today.
     */
    private function rebuildCurrentUsageFromLedger(): void
    {
        $now = CarbonImmutable::now(config('transactions.business_timezone', 'Asia/Dhaka'));
        $dayStartUtc = $now->startOfDay()->utc();
        $monthStartUtc = $now->startOfMonth()->utc();

        // Mirrors the operations that increment caps: the sender's outgoing CASH_OUT and TRANSFER amounts
        $usageSince = fn (CarbonImmutable $since) => DB::table('transactions')
            ->whereIn('type', ['CASH_OUT', 'TRANSFER'])
            ->where('status', 'COMPLETED')
            ->where('created_at', '>=', $since)
            ->whereNotNull('sender_id')
            ->groupBy('sender_id')
            ->pluck(DB::raw('SUM(amount)'), 'sender_id');

        $dailyUsage = $usageSince($dayStartUtc);
        $monthlyUsage = $usageSince($monthStartUtc);

        DB::table('caps')->orderBy('id')->each(function (object $cap) use ($dailyUsage, $monthlyUsage, $now): void {
            DB::table('caps')->where('id', $cap->id)->update([
                'daily_used' => $dailyUsage[$cap->user_id] ?? 0,
                'monthly_used' => $monthlyUsage[$cap->user_id] ?? 0,
                'last_reset_date' => $now->toDateString(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caps', function (Blueprint $table) {
            $table->dropColumn('last_reset_date');
        });
    }
};
