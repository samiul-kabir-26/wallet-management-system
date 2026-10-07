<?php

namespace Modules\Transactions\Jobs;

use App\Models\Cap;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResetMonthlyCaps implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cap::query()->update(['monthly_used' => 0]);
    }
}
