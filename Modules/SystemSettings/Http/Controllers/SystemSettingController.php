<?php

namespace Modules\SystemSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\SystemSettings\Http\Requests\UpdateSystemSettingsRequest;
use Modules\SystemSettings\Resources\SettingsResource;
use Modules\SystemSettings\Services\SystemSettingService;

class SystemSettingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected SystemSettingService $settingService,
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
                'settings' => new SettingsResource($settings),
            ],
        ]);
    }

    /**
     * Update system settings.
     */
    public function update(UpdateSystemSettingsRequest $request): JsonResponse
    {
        $updatedSettings = $this->settingService->updateSettings(
            settings: $request->validated(),
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => [
                'settings' => new SettingsResource($updatedSettings),
            ],
        ]);
    }
}
