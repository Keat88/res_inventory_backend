<?php

namespace App\Services;

use App\Enums\SettingType;
use App\Models\SystemSetting;
use App\Models\User;

class SettingService
{
    public function set(
        int $branchId,
        string $key,
        mixed $value,
        SettingType $type = SettingType::STRING,
        bool $isPublic = false,
        ?User $user = null
    ): SystemSetting {
        $stored = match ($type) {
            SettingType::JSON => json_encode($value),
            SettingType::BOOLEAN => $value ? '1' : '0',
            default => (string) $value,
        };

        return SystemSetting::query()->updateOrCreate(
            ['branch_id' => $branchId, 'key' => $key],
            [
                'value' => $stored,
                'type' => $type->value,
                'is_public' => $isPublic,
                'updated_by' => $user?->id,
            ]
        );
    }

    public function get(int $branchId, string $key, mixed $default = null): mixed
    {
        $setting = SystemSetting::query()
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->first();

        return $setting?->typedValue() ?? $default;
    }

    public function all(int $branchId, bool $publicOnly = false): array
    {
        $query = SystemSetting::query()->where('branch_id', $branchId);

        if ($publicOnly) {
            $query->where('is_public', true);
        }

        return $query->get()->mapWithKeys(
            fn (SystemSetting $setting) => [$setting->key => $setting->typedValue()]
        )->all();
    }

    public function delete(int $branchId, string $key): void
    {
        SystemSetting::query()->where('branch_id', $branchId)->where('key', $key)->delete();
    }
}
