<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Unit;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $recipes = Recipe::query()
            ->with('menuItem:id,name', 'ingredients.ingredient:id,name,sku,unit')
            ->when($request->filled('menu_item_id'), fn ($q) => $q->where('menu_item_id', $request->integer('menu_item_id')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->latest('id')
            ->get();

        return ApiResponse::success($recipes);
    }

    public function store(Request $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'yield_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'ingredients.*.unit' => ['nullable', Rule::in(Unit::values())],
            'ingredients.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $recipe = DB::transaction(function () use ($menuItem, $data) {
            $menuItem->recipes()->where('is_active', true)->update(['is_active' => false]);

            $version = (int) $menuItem->recipes()->max('version') + 1;

            $recipe = $menuItem->recipes()->create([
                'version' => $version,
                'yield_quantity' => $data['yield_quantity'] ?? 1,
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ]);

            foreach ($data['ingredients'] as $ingredient) {
                $recipe->ingredients()->create($ingredient);
            }

            return $recipe;
        });

        return ApiResponse::created($recipe->load('ingredients.ingredient'), 'Recipe created successfully');
    }

    public function show(Recipe $recipe): JsonResponse
    {
        return ApiResponse::success($recipe->load('menuItem', 'ingredients.ingredient'));
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $request->validate([
            'yield_quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'ingredients' => ['nullable', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required_with:ingredients', 'integer', 'exists:ingredients,id'],
            'ingredients.*.quantity' => ['required_with:ingredients', 'numeric', 'min:0.001'],
            'ingredients.*.unit' => ['nullable', Rule::in(Unit::values())],
            'ingredients.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        DB::transaction(function () use ($recipe, $data) {
            if (array_key_exists('is_active', $data) && $data['is_active']) {
                $recipe->menuItem->recipes()->where('id', '!=', $recipe->id)->update(['is_active' => false]);
            }

            $recipe->update(collect($data)->except('ingredients')->all());

            if (isset($data['ingredients'])) {
                $recipe->ingredients()->delete();

                foreach ($data['ingredients'] as $ingredient) {
                    $recipe->ingredients()->create($ingredient);
                }
            }
        });

        return ApiResponse::success($recipe->refresh()->load('ingredients.ingredient'), 'Recipe updated successfully');
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->update(['is_active' => false]);

        return ApiResponse::noContent('Recipe deactivated successfully');
    }
}
