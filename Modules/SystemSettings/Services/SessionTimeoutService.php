<?php

namespace Modules\SystemSettings\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class SessionTimeoutService
{
    /**
     * Panels whose idle timeout staff can configure.
     *
     * @var list<'admin'|'agent'|'user'>
     */
    public const array PANELS = ['admin', 'agent', 'user'];

    public function __construct(
        protected SystemSettingService $settingService,
    ) {}

    /**
     * The system_settings key that stores a panel's override.
     */
    public static function settingKey(string $panel): string
    {
        return "session_idle_timeout_{$panel}_minutes";
    }

    /**
     * Idle timeout in minutes for a panel: the stored override, else the configured default.
     */
    public function minutesFor(string $panel): int
    {
        $override = $this->settingService->get(self::settingKey($panel));

        if ($override !== null) {
            return (int) $override;
        }

        return (int) config("auth_settings.tokens.idle_timeout_minutes.{$panel}", 5);
    }

    /**
     * Idle timeout for an access token, chosen by the panel ability it carries. 0 means no timeout.
     */
    public function minutesForToken(PersonalAccessToken $token): int
    {
        $panel = $this->panelForAbilities($token->abilities ?? []);

        return $panel !== null
            ? $this->minutesFor($panel)
            : (int) config('auth_settings.tokens.default_idle_timeout_minutes', 15);
    }

    /**
     * The panel a set of token abilities belongs to, if any.
     *
     * @param  array<int, string>  $abilities
     */
    public function panelForAbilities(array $abilities): ?string
    {
        foreach (self::PANELS as $panel) {
            if (in_array($panel, $abilities, true)) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * Current idle timeout for every panel.
     *
     * @return array{admin: int, agent: int, user: int}
     */
    public function all(): array
    {
        return [
            'admin' => $this->minutesFor('admin'),
            'agent' => $this->minutesFor('agent'),
            'user' => $this->minutesFor('user'),
        ];
    }

    /**
     * Override the idle timeout for one or more panels and audit the change.
     *
     * @param  array<string, int>  $minutesByPanel  Keyed by panel name.
     * @return array{admin: int, agent: int, user: int}
     */
    public function update(array $minutesByPanel, User $actor): array
    {
        $before = $this->all();

        DB::transaction(function () use ($minutesByPanel, $actor, $before): void {
            $settings = [];
            foreach ($minutesByPanel as $panel => $minutes) {
                $settings[self::settingKey($panel)] = (int) $minutes;
            }

            $this->settingService->updateSettings($settings, $actor, auditAction: null);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'SESSION_TIMEOUTS_UPDATED',
                'auditable_type' => null,
                'auditable_id' => null,
                'metadata' => [
                    'before' => $before,
                    'after' => array_merge($before, array_map('intval', $minutesByPanel)),
                ],
            ]);
        });

        return $this->all();
    }
}
