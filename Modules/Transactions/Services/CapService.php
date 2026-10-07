<?php

namespace Modules\Transactions\Services;

use App\Models\Cap;
use Modules\Transactions\Exceptions\DailyCapsExceededException;
use Modules\Transactions\Exceptions\MonthlyCapsExceededException;

class CapService
{
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
