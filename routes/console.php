<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Payments\Jobs\ReconcilePendingTopUps;
use Modules\Payments\Services\TopUpReconciler;
use Modules\Transactions\Jobs\ResetStaleCaps;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:reconcile-top-ups', function (TopUpReconciler $reconciler): int {
    $summary = $reconciler->run();

    $this->table(array_keys($summary), [array_values($summary)]);

    return $summary['errors'] > 0 ? 1 : 0;
})->purpose('Settle gateway top-ups whose payment callbacks never arrived');

// Housekeeping only: transactions roll cap counters over lazily, so a missed run is harmless
Schedule::job(new ResetStaleCaps)->dailyAt('00:05')->timezone(config('transactions.business_timezone', 'Asia/Dhaka'));
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Settles top-ups whose IPN or browser return was lost; safe to overlap with callbacks, so only overlapping runs are prevented
Schedule::job(new ReconcilePendingTopUps)->everyTenMinutes()->withoutOverlapping();

// Detects balances that drifted from the ledger; read-only, alerts through the log
Schedule::command('wallets:verify-ledger')->dailyAt('02:00')->timezone(config('transactions.business_timezone', 'Asia/Dhaka'));
