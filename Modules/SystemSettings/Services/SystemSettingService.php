<?php

namespace Modules\SystemSettings\Services;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * Each change is audited with its previous and new value unless the caller writes its own
     * audit entry (pass $auditAction = null).
     *
     * @param  array<string, float|int|string>  $settings
     * @return array<string, float>
     */
    public function updateSettings(array $settings, User $user, ?string $auditAction = 'SETTINGS_UPDATED'): array
    {
        DB::transaction(function () use ($settings, $user, $auditAction): void {
            $changes = [];

            foreach ($settings as $key => $value) {
                $setting = SystemSetting::where('key', $key)->lockForUpdate()->first() ?? new SystemSetting(['key' => $key]);
                $changes[$key] = [
                    'before' => $setting->exists ? (float) $setting->value : null,
                    'after' => (float) $value,
                ];

                $setting->value = (float) $value;
                $setting->updated_by = $user->id;
                $setting->save();
            }

            if ($auditAction !== null) {
                AuditLog::create([
                    'actor_id' => $user->id,
                    'action' => $auditAction,
                    'auditable_type' => null,
                    'auditable_id' => null,
                    'metadata' => ['changes' => $changes],
                ]);
            }
        });

        // Forget after commit so a concurrent read cannot re-cache the old values mid-transaction
        Cache::forget(self::CACHE_KEY);

        return $this->getSettings();
    }
}
