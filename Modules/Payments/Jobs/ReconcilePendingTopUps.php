<?php

namespace Modules\Payments\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Payments\Services\TopUpReconciler;

/**
 * Scheduled sweep that settles gateway top-ups whose callbacks never arrived. See TopUpReconciler.
 */
class ReconcilePendingTopUps
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(TopUpReconciler $reconciler): void
    {
        $reconciler->run();
    }
}
