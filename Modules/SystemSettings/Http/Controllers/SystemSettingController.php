<?php

namespace Modules\SystemSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Approvals\Resources\ApprovalRequestResource;
use Modules\Approvals\Services\ApprovalService;
use Modules\SystemSettings\Http\Requests\UpdateSessionTimeoutsRequest;
use Modules\SystemSettings\Http\Requests\UpdateSystemSettingsRequest;
use Modules\SystemSettings\Resources\SettingsResource;
use Modules\SystemSettings\Services\SessionTimeoutService;
use Modules\SystemSettings\Services\SystemSettingService;
use Modules\Transactions\Services\SignupBonusService;

class SystemSettingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected SystemSettingService $settingService,
        protected SessionTimeoutService $sessionTimeouts,
        protected SignupBonusService $signupBonus,
    ) {}

    /**
     * Get all system settings.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SystemSetting::class);

        $settings = $this->settingService->getSettings();

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => new SettingsResource($this->withEffectiveSignupBonus($settings)),
            ],
        ]);
    }

    /**
     * Update system settings.
     */
    public function update(UpdateSystemSettingsRequest $request, ApprovalService $approvals): JsonResponse
    {
        if ($approvals->isRequired()) {
            $current = $this->withEffectiveSignupBonus($this->settingService->getSettings());
            $approval = $approvals->requestSettingsUpdate($request->user(), $request->validated(), $current);

            if ($approval === null) {
                return response()->json([
                    'success' => true,
                    'message' => 'Nothing to change: the submitted values are already in force.',
                    'data' => ['settings' => new SettingsResource($current)],
                ]);
            }

            return ApprovalRequestResource::pendingResponse($approval);
        }

        $updatedSettings = $this->settingService->updateSettings(
            settings: $request->validated(),
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => [
                'settings' => new SettingsResource($this->withEffectiveSignupBonus($updatedSettings)),
            ],
        ]);
    }

    /**
     * Get the idle auto-logout timeout (minutes) for each panel.
     */
    public function sessionTimeouts(): JsonResponse
    {
        $this->authorize('viewAny', SystemSetting::class);

        return response()->json([
            'success' => true,
            'data' => [
                'session_timeouts' => $this->sessionTimeouts->all(),
            ],
        ]);
    }

    /**
     * Change the idle auto-logout timeout for one or more panels.
     */
    public function updateSessionTimeouts(UpdateSessionTimeoutsRequest $request): JsonResponse
    {
        $timeouts = $this->sessionTimeouts->update(
            minutesByPanel: $request->validated(),
            actor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Session timeouts updated successfully',
            'data' => [
                'session_timeouts' => $timeouts,
            ],
        ]);
    }

    /**
     * Report the signup bonus values actually in force, including the configured defaults when staff
     * have not stored an override yet.
     *
     * @param  array<string, float>  $settings
     * @return array<string, float|bool>
     */
    private function withEffectiveSignupBonus(array $settings): array
    {
        return [
            ...$settings,
            SignupBonusService::SETTING_ENABLED => $this->signupBonus->isEnabled(),
            SignupBonusService::SETTING_AMOUNT => (float) $this->signupBonus->amount(),
        ];
    }
}
