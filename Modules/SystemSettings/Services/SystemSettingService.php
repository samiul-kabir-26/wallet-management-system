<?php

namespace Modules\SystemSettings\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SystemSettingService
{
    public const CACHE_KEY = 'system_settings';

    public const CACHE_TTL = 3600; // 1 hour

    /**
     * Get all system settings as key-value array (cached).
     *
     * @return array<string, float>
     */
    public function getSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $settings = SystemSetting::pluck('value', 'key')->toArray();

            $formatted = [];
            foreach ($settings as $key => $val) {
                $formatted[$key] = (float) $val;
            }

            return $formatted;
        });
    }

    /**
     * Get a specific system setting by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->getSettings();

        return $settings[$key] ?? $default;
    }

    /**
     * Update multiple system settings, recording the updating user and invalidating cache.
     *
     * @param  array<string, float|int|string>  $settings
     * @return array<string, float>
     */
    public function updateSettings(array $settings, User $user): array
    {
        foreach ($settings as $key => $value) {
            $setting = SystemSetting::firstOrNew(['key' => $key]);
            $setting->value = (float) $value;
            $setting->updated_by = $user->id;
            $setting->save();
        }

        Cache::forget(self::CACHE_KEY);

        return $this->getSettings();
    }
}
