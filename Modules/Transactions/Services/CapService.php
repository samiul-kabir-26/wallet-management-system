<?php

namespace Modules\Transactions\Services;

use App\Models\Cap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Modules\Transactions\Exceptions\DailyCapsExceededException;
use Modules\Transactions\Exceptions\MonthlyCapsExceededException;

class CapService
{
    /**
     * Get the current moment in the business timezone that defines cap periods.
     */
    public function businessNow(): CarbonImmutable
    {
        return CarbonImmutable::now(config('transactions.business_timezone', 'Asia/Dhaka'));
    }

    /**
     * Zero any counters whose period has rolled over since they were last anchored.
     *
     * Must be called on a row locked with lockForUpdate(), inside the same DB transaction
     * that checks and increments the caps, so concurrent requests cannot reset each other's usage.
     */
    public function resetStalePeriods(Cap $lockedCap): void
    {
        $today = $this->businessNow();

        if (! $this->isDailyPeriodStale($lockedCap, $today)) {
            return;
        }

        $lockedCap->daily_used = '0.00';

        if ($this->isMonthlyPeriodStale($lockedCap, $today)) {
            $lockedCap->monthly_used = '0.00';
        }

        $lockedCap->last_reset_date = Carbon::parse($today->toDateString());
        $lockedCap->save();
    }

    /**
     * Describe the cap usage as it applies right now, without writing to the database.
     *
     * Counters from an earlier day or month are reported as zero even if no transaction
     * has reset them yet.
     *
     * @return array{daily_limit: numeric-string, daily_used: numeric-string, daily_remaining: numeric-string, monthly_limit: numeric-string, monthly_used: numeric-string, monthly_remaining: numeric-string}
     */
    public function usageSnapshot(Cap $cap): array
    {
        $today = $this->businessNow();

        $dailyLimit = number_format((float) $cap->daily_cap, 2, '.', '');
        $monthlyLimit = number_format((float) $cap->monthly_cap, 2, '.', '');

        $dailyUsed = $this->isDailyPeriodStale($cap, $today) ? '0.00' : number_format((float) $cap->daily_used, 2, '.', '');
        $monthlyUsed = $this->isMonthlyPeriodStale($cap, $today) ? '0.00' : number_format((float) $cap->monthly_used, 2, '.', '');

        return [
            'daily_limit' => $dailyLimit,
            'daily_used' => $dailyUsed,
            'daily_remaining' => $this->remaining($dailyLimit, $dailyUsed),
            'monthly_limit' => $monthlyLimit,
            'monthly_used' => $monthlyUsed,
            'monthly_remaining' => $this->remaining($monthlyLimit, $monthlyUsed),
        ];
    }

    /**
     * Determine whether the daily counter belongs to an earlier business day.
     */
    private function isDailyPeriodStale(Cap $cap, CarbonImmutable $today): bool
    {
        return $cap->last_reset_date === null
            || $cap->last_reset_date->toDateString() !== $today->toDateString();
    }

    /**
     * Determine whether the monthly counter belongs to an earlier business month.
     */
    private function isMonthlyPeriodStale(Cap $cap, CarbonImmutable $today): bool
    {
        return $cap->last_reset_date === null
            || $cap->last_reset_date->format('Y-m') !== $today->format('Y-m');
    }

    /**
     * Compute the remaining allowance, never below zero.
     *
     * @param  numeric-string  $limit
     * @param  numeric-string  $used
     * @return numeric-string
     */
    private function remaining(string $limit, string $used): string
    {
        $remaining = bcsub($limit, $used, 2);

        return bccomp($remaining, '0.00', 2) === -1 ? '0.00' : $remaining;
    }

    /**
     * Assert that the requested transaction amount does not breach the user's daily or monthly caps.
     *
     * @throws DailyCapsExceededException
     * @throws MonthlyCapsExceededException
     */
    public function assertWithinCaps(Cap $cap, string $amount): void
    {
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $dailyUsed = number_format((float) $cap->daily_used, 2, '.', '');
        $dailyCap = number_format((float) $cap->daily_cap, 2, '.', '');
        $projectedDaily = bcadd($dailyUsed, $formattedAmount, 2);

        if (bccomp($projectedDaily, $dailyCap, 2) === 1) {
            throw new DailyCapsExceededException(
                "Daily transaction cap of {$dailyCap} exceeded. Current used: {$dailyUsed}, requested: {$formattedAmount}."
            );
        }

        $monthlyUsed = number_format((float) $cap->monthly_used, 2, '.', '');
        $monthlyCap = number_format((float) $cap->monthly_cap, 2, '.', '');
        $projectedMonthly = bcadd($monthlyUsed, $formattedAmount, 2);

        if (bccomp($projectedMonthly, $monthlyCap, 2) === 1) {
            throw new MonthlyCapsExceededException(
                "Monthly transaction cap of {$monthlyCap} exceeded. Current used: {$monthlyUsed}, requested: {$formattedAmount}."
            );
        }
    }

    /**
     * Increment the user's daily and monthly used amounts.
     *
     * @param  numeric-string  $amount
     */
    public function increment(Cap $lockedCap, string $amount): void
    {
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $lockedCap->daily_used = bcadd(
            number_format((float) $lockedCap->daily_used, 2, '.', ''),
            $formattedAmount,
            2
        );

        $lockedCap->monthly_used = bcadd(
            number_format((float) $lockedCap->monthly_used, 2, '.', ''),
            $formattedAmount,
            2
        );

        $lockedCap->save();
    }
}
