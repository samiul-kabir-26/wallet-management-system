<?php

namespace Modules\Transactions\Services;

use Modules\SystemSettings\Services\SystemSettingService;

class FeeCalculator
{
    public function __construct(
        protected SystemSettingService $settingService,
    ) {}

    /**
     * Calculate the system fee for a given amount based on the system_fee_rate setting.
     *
     * @return array{rate: string, amount: string}
     */
    public function calculate(string $amount): array
    {
        $settingValue = $this->settingService->get('system_fee_rate');
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
