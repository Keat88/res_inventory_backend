<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;
use App\Models\Category;
use App\Services\OrderPricingService;
use App\Services\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController
{
    public function __construct(
        protected OrderPricingService $pricing,
        protected SettingService $settings
    ) {}

    public function menu(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $branchId = (int) $validated['branch_id'];

        $branch = Branch::query()->whereKey($branchId)->firstOrFail();

        $categories = Category::query()
            ->where('status', 'ACTIVE')
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->with([
                'menuItems' => fn ($q) => $q->where('status', 'ACTIVE')->where('is_available', true),
                'menuItems.variants' => fn ($q) => $q->where('status', 'ACTIVE'),
                'menuItems.modifierGroups.options',
                'menuItems.branchPrices' => fn ($q) => $q->where('branch_id', $branchId),
            ])
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success([
            'branch' => $branch->only(['id', 'code', 'name', 'currency', 'tax_rate', 'service_charge_rate']),
            'categories' => $categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'items' => $category->menuItems->map(function ($item) use ($branchId) {
                    if (! $this->pricing->isAvailableAtBranch($branchId, $item)) {
                        return null;
                    }

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'description' => $item->description,
                        'image_path' => $item->image_path,
                        'item_type' => $item->item_type->value,
                        'prep_time_minutes' => $item->prep_time_minutes,
                        'price' => $this->pricing->resolveUnitPrice($branchId, $item),
                        'variants' => $item->variants->map(fn ($variant) => [
                            'id' => $variant->id,
                            'code' => $variant->code,
                            'name' => $variant->name,
                            'price' => $this->pricing->resolveUnitPrice($branchId, $item, $variant->id),
                            'is_default' => $variant->is_default,
                        ])->values(),
                        'modifier_groups' => $item->modifierGroups->map(fn ($group) => [
                            'id' => $group->id,
                            'name' => $group->name,
                            'selection_type' => $group->selection_type->value,
                            'is_required' => $group->is_required,
                            'min_selection' => $group->min_selection,
                            'max_selection' => $group->max_selection,
                            'options' => $group->options->map(fn ($option) => [
                                'id' => $option->id,
                                'name' => $option->name,
                                'price' => (float) $option->price,
                                'is_default' => $option->is_default,
                            ])->values(),
                        ])->values(),
                    ];
                })->filter()->values(),
            ])->values(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        return ApiResponse::success($this->settings->all((int) $validated['branch_id'], true));
    }
}
