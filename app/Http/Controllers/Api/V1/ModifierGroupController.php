<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModifierSelectionType;
use App\Http\Controllers\Controller;
use App\Models\ModifierGroup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ModifierGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = ModifierGroup::query()
            ->with('options')
            ->withCount('menuItems')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'selection_type' => ['nullable', Rule::in(ModifierSelectionType::values())],
            'min_selection' => ['nullable', 'integer', 'min:0'],
            'max_selection' => ['nullable', 'integer', 'min:1'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ]);

        $group = DB::transaction(function () use ($data) {
            $group = ModifierGroup::query()->create(collect($data)->except('options')->all());

            foreach ($data['options'] ?? [] as $option) {
                $group->options()->create($option);
            }

            return $group;
        });

        return ApiResponse::created($group->load('options'), 'Modifier group created successfully');
    }

    public function show(ModifierGroup $modifierGroup): JsonResponse
    {
        return ApiResponse::success($modifierGroup->load('options', 'menuItems:id,name'));
    }

    public function update(Request $request, ModifierGroup $modifierGroup): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'selection_type' => ['sometimes', Rule::in(ModifierSelectionType::values())],
            'min_selection' => ['nullable', 'integer', 'min:0'],
            'max_selection' => ['nullable', 'integer', 'min:1'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'integer', 'exists:modifier_options,id'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($modifierGroup, $data) {
            $modifierGroup->update(collect($data)->except('options')->all());

            foreach ($data['options'] ?? [] as $option) {
                $id = $option['id'] ?? null;
                unset($option['id']);
                $id
                    ? $modifierGroup->options()->whereKey($id)->update($option)
                    : $modifierGroup->options()->create($option);
            }
        });

        return ApiResponse::success($modifierGroup->refresh()->load('options'), 'Modifier group updated successfully');
    }

    public function destroy(ModifierGroup $modifierGroup): JsonResponse
    {
        $modifierGroup->delete();

        return ApiResponse::noContent();
    }
}
