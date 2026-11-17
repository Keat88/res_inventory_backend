<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuItemVariantController extends Controller
{
    public function store(Request $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('menu_item_variants', 'code')->where('menu_item_id', $menuItem->id)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $variant = $menuItem->variants()->create($data);

        return ApiResponse::created($variant, 'Variant created successfully');
    }

    public function update(Request $request, MenuItem $menuItem, MenuItemVariant $variant): JsonResponse
    {
        abort_unless((int) $variant->menu_item_id === (int) $menuItem->id, 404);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('menu_item_variants', 'code')->where('menu_item_id', $menuItem->id)->ignore($variant->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $variant->update($data);

        return ApiResponse::success($variant->refresh(), 'Variant updated successfully');
    }

    public function destroy(MenuItem $menuItem, MenuItemVariant $variant): JsonResponse
    {
        abort_unless((int) $variant->menu_item_id === (int) $menuItem->id, 404);

        $variant->delete();

        return ApiResponse::noContent();
    }
}
