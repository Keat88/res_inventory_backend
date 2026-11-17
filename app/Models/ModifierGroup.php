<?php

namespace App\Models;

use App\Enums\ModifierSelectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'selection_type' => ModifierSelectionType::class,
            'is_required' => 'boolean',
            'min_selection' => 'integer',
            'max_selection' => 'integer',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort_order');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_modifier_group')
            ->withPivot('sort_order');
    }
}
