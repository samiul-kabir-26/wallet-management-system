<?php

namespace Modules\Transactions\Jobs;

use App\Models\Cap;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Modules\Transactions\Services\CapService;

/**
 * Housekeeping: roll over cap counters whose business day or month has ended.
 *
 * Correctness does not depend on this job. Transactions reset stale counters lazily
 * under a row lock, and reads report stale counters as zero. The job only keeps the
 * stored values tidy, and because it resets only stale rows, a late run can never
 * wipe usage that belongs to the current period.
 */
class ResetStaleCaps
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(CapService $capService): void
    {
        $today = $capService->businessNow()->toDateString();

        Cap::query()
            ->where(fn ($query) => $query->whereNull('last_reset_date')->orWhere('last_reset_date', '<', $today))
            ->select('id')
            ->chunkById(500, function ($caps) use ($capService): void {
                foreach ($caps as $cap) {
                    DB::transaction(function () use ($cap, $capService): void {
                        $lockedCap = Cap::whereKey($cap->id)->lockForUpdate()->first();

                        if ($lockedCap) {
                            $capService->resetStalePeriods($lockedCap);
                        }
                    });
                }
            });
    }
}
