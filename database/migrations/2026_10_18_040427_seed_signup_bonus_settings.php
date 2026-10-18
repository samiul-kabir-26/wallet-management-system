<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Setting keys added by this migration with their initial values.
     *
     * @var array<string, int|float>
     */
    private array $settings = [
        'signup_bonus_enabled' => 1,
        'signup_bonus_amount' => 50.00,
    ];

    /**
     * Run the migrations.
     *
     * Existing installs already ran SystemSettingsSeeder, so the new keys are inserted here. Rows that
     * already exist are left untouched.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->settings as $key => $value) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::forget('system_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys($this->settings))->delete();

        Cache::forget('system_settings');
    }
};
