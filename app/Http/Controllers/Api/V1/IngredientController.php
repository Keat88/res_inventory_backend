<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Unit;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Ingredient::query()
            ->with(['inventories' => fn ($q) => $q->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            }))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return ApiResponse::paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:ingredients,name'],
            'sku' => ['nullable', 'string', 'max:50', 'unique:ingredients,sku'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', Rule::in(Unit::values())],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $ingredient = Ingredient::query()->create($data);

        return ApiResponse::created($ingredient, 'Ingredient created successfully');
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        return ApiResponse::success($ingredient->load('inventories.branch', 'recipeIngredients.recipe.menuItem'));
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('ingredients', 'name')->ignore($ingredient->id)],
            'sku' => ['nullable', 'string', 'max:50', Rule::unique('ingredients', 'sku')->ignore($ingredient->id)],
            'description' => ['nullable', 'string'],
            'unit' => ['sometimes', Rule::in(Unit::values())],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $ingredient->update($data);

        return ApiResponse::success($ingredient->refresh(), 'Ingredient updated successfully');
    }

    public function destroy(Ingredient $ingredient): JsonResponse
    {
        if ($ingredient->recipeIngredients()->exists()) {
            return ApiResponse::error('Ingredients used in recipes cannot be deleted.', null, 409, 'INGREDIENT_IN_USE');
        }

        $ingredient->delete();

        return ApiResponse::noContent();
    }
}
