<?php

namespace Modules\Transactions\Services;

use App\Models\AgentInfo;

class CommissionCalculator
{
    /**
     * Calculate agent commission for a given amount.
     *
     * @return array{rate: string, amount: string}
     */
    public function calculate(string $amount, AgentInfo $agentInfo, ?string $maxCommission = null): array
    {
        $rate = number_format((float) ($agentInfo->commission_rate ?? '0.0100'), 4, '.', '');
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $calculated = bcmul($formattedAmount, $rate, 4);
        $commissionAmount = number_format((float) $calculated, 2, '.', '');

        if ($maxCommission !== null && bccomp($commissionAmount, number_format((float) $maxCommission, 2, '.', ''), 2) === 1) {
            $commissionAmount = number_format((float) $maxCommission, 2, '.', '');
        }

        return [
            'rate' => $rate,
            'amount' => $commissionAmount,
        ];
    }
}
