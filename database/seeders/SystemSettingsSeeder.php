<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SystemSetting::create(['key' => 'system_fee_rate', 'value' => 0.05]);
        SystemSetting::create(['key' => 'agent_commission_rate', 'value' => 0.01]);
    }
}
