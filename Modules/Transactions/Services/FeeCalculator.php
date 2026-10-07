<?php

namespace Modules\Transactions\Services;

use App\Models\SystemSetting;

class FeeCalculator
{
    /**
     * Calculate the system fee for a given amount based on the system_fee_rate setting.
     *
     * @return array{rate: string, amount: string}
     */
    public function calculate(string $amount): array
    {
        $settingValue = SystemSetting::where('key', 'system_fee_rate')->value('value');
        $rate = number_format((float) ($settingValue ?? '0.0500'), 4, '.', '');
        $formattedAmount = number_format((float) $amount, 2, '.', '');

        $calculated = bcmul($formattedAmount, $rate, 4);
        $feeAmount = number_format((float) $calculated, 2, '.', '');

        return [
            'rate' => $rate,
            'amount' => $feeAmount,
        ];
    }
}
