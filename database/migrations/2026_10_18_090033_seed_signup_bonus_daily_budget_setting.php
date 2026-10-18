<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The row always exists so SignupBonusService can lock it while it checks the daily budget,
     * which serializes concurrent registrations. 0 means no limit.
     */
    public function up(): void
    {
        $now = now();

        DB::table('system_settings')->insertOrIgnore([
            'key' => 'signup_bonus_daily_budget',
            'value' => json_encode(0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Cache::forget('system_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->where('key', 'signup_bonus_daily_budget')->delete();

        Cache::forget('system_settings');
    }
};
