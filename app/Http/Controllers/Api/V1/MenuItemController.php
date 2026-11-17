<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchMenuItemPrice;
use App\Models\MenuItem;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MenuItemController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;

        $query = MenuItem::query()
            ->with([
                'category:id,name',
                'variants',
                'modifierGroups.options',
                'branchPrices' => fn ($q) => $q->when($branchId, fn ($q) => $q->where('branch_id', $branchId)),
            ])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('available'), fn ($q) => $q->where('is_available', $request->boolean('available')));

        return ApiResponse::paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:menu_items,slug'],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'item_type' => ['nullable', Rule::in(ItemType::values())],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'variants' => ['nullable', 'array'],
            'variants.*.code' => ['required_with:variants', 'string', 'max:30'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.is_default' => ['nullable', 'boolean'],
            'variants.*.sort_order' => ['nullable', 'integer'],
            'modifier_group_ids' => ['nullable', 'array'],
            'modifier_group_ids.*' => ['integer', 'exists:modifier_groups,id'],
        ]);

        $item = DB::transaction(function () use ($data) {
            $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['name']);

            $item = MenuItem::query()->create(collect($data)->except(['variants', 'modifier_group_ids'])->all());

            foreach ($data['variants'] ?? [] as $variant) {
                $item->variants()->create($variant);
            }

            if (isset($data['modifier_group_ids'])) {
                $item->modifierGroups()->sync($data['modifier_group_ids']);
            }

            return $item;
        });

        $this->audit->log('menu_item.created', 'menu_item', $item->id, null, ['name' => $item->name]);

        return ApiResponse::created($item->load('category', 'variants', 'modifierGroups.options'), 'Menu item created successfully');
    }

    public function show(MenuItem $menuItem): JsonResponse
    {
        return ApiResponse::success($menuItem->load('category', 'variants', 'modifierGroups.options', 'branchPrices.branch', 'recipe.ingredients.ingredient'));
    }

    public function update(Request $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('menu_items', 'slug')->ignore($menuItem->id)],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'item_type' => ['sometimes', Rule::in(ItemType::values())],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'variants.*.code' => ['required_with:variants', 'string', 'max:30'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.is_default' => ['nullable', 'boolean'],
            'variants.*.sort_order' => ['nullable', 'integer'],
            'modifier_group_ids' => ['nullable', 'array'],
            'modifier_group_ids.*' => ['integer', 'exists:modifier_groups,id'],
        ]);

        DB::transaction(function () use ($menuItem, $data) {
            if (isset($data['name']) && empty($data['slug'])) {
                $data['slug'] = $this->uniqueSlug($data['name'], $menuItem->id);
            }

            $menuItem->update(collect($data)->except(['variants', 'modifier_group_ids'])->all());

            foreach ($data['variants'] ?? [] as $variant) {
                $id = $variant['id'] ?? null;
                unset($variant['id']);
                $id
                    ? $menuItem->variants()->whereKey($id)->update($variant)
                    : $menuItem->variants()->create($variant);
            }

            if (isset($data['modifier_group_ids'])) {
                $menuItem->modifierGroups()->sync($data['modifier_group_ids']);
            }
        });

        $this->audit->log('menu_item.updated', 'menu_item', $menuItem->id, null, $data);

        return ApiResponse::success($menuItem->refresh()->load('category', 'variants', 'modifierGroups.options'), 'Menu item updated successfully');
    }

    public function destroy(MenuItem $menuItem): JsonResponse
    {
        $menuItem->delete();

        $this->audit->log('menu_item.deleted', 'menu_item', $menuItem->id, ['name' => $menuItem->name]);

        return ApiResponse::noContent();
    }

    public function setBranchPrice(Request $request, Branch $branch, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'menu_item_variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $price = BranchMenuItemPrice::query()->updateOrCreate(
            [
                'branch_id' => $branch->id,
                'menu_item_id' => $menuItem->id,
                'menu_item_variant_id' => $data['menu_item_variant_id'] ?? null,
            ],
            [
                'price' => $data['price'] ?? null,
                'is_available' => $data['is_available'] ?? true,
            ]
        );

        return ApiResponse::success($price, 'Branch price saved successfully');
    }

    protected function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;

        while (MenuItem::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
