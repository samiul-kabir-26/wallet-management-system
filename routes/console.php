<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Transactions\Jobs\ResetStaleCaps;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Housekeeping only: transactions roll cap counters over lazily, so a missed run is harmless
Schedule::job(new ResetStaleCaps)->dailyAt('00:05')->timezone(config('transactions.business_timezone', 'Asia/Dhaka'));
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Detects balances that drifted from the ledger; read-only, alerts through the log
Schedule::command('wallets:verify-ledger')->dailyAt('02:00')->timezone(config('transactions.business_timezone', 'Asia/Dhaka'));
