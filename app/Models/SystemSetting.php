<?php

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_public' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function typedValue(): mixed
    {
        return match ($this->type) {
            SettingType::NUMBER => (float) $this->value,
            SettingType::BOOLEAN => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            SettingType::JSON => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
