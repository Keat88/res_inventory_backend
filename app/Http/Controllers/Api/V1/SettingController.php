<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SettingType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function __construct(
        protected SettingService $settings,
        protected AuditService $audit
    ) {}

    public function index(Request $request, Branch $branch): JsonResponse
    {
        $publicOnly = ! $request->user()->can('settings.view');

        return ApiResponse::success($this->settings->all($branch->id, $publicOnly));
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['required'],
            'settings.*.type' => ['nullable', Rule::in(SettingType::values())],
            'settings.*.is_public' => ['nullable', 'boolean'],
        ]);

        foreach ($data['settings'] as $setting) {
            $this->settings->set(
                $branch->id,
                $setting['key'],
                $setting['value'],
                SettingType::from($setting['type'] ?? SettingType::STRING->value),
                (bool) ($setting['is_public'] ?? false),
                $request->user()
            );
        }

        $this->audit->log('settings.updated', 'system_setting', null, null, [
            'keys' => collect($data['settings'])->pluck('key')->all(),
        ], $branch->id);

        return ApiResponse::success($this->settings->all($branch->id), 'Settings updated successfully');
    }

    public function destroy(Branch $branch, string $key): JsonResponse
    {
        $this->settings->delete($branch->id, $key);

        return ApiResponse::noContent();
    }
}
