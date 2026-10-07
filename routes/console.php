<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Transactions\Jobs\ResetDailyCaps;
use Modules\Transactions\Jobs\ResetMonthlyCaps;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ResetDailyCaps)->dailyAt('00:00')->timezone('UTC');
Schedule::job(new ResetMonthlyCaps)->monthlyOn(1, '00:00')->timezone('UTC');
