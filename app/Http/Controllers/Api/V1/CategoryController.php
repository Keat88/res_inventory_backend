<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->withCount('menuItems')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['name']);

        $category = Category::query()->create($data);

        $this->audit->log('category.created', 'category', $category->id, null, ['name' => $category->name]);

        return ApiResponse::created($category, 'Category created successfully');
    }

    public function show(Category $category): JsonResponse
    {
        return ApiResponse::success($category->load('menuItems.variants', 'menuItems.modifierGroups'));
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
            'description' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        if (isset($data['name']) && empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['name'], $category->id);
        }

        $category->update($data);

        $this->audit->log('category.updated', 'category', $category->id, null, $data);

        return ApiResponse::success($category->refresh(), 'Category updated successfully');
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->menuItems()->exists()) {
            return ApiResponse::error('Categories with menu items cannot be deleted.', null, 409, 'CATEGORY_HAS_ITEMS');
        }

        $category->delete();

        $this->audit->log('category.deleted', 'category', $category->id, ['name' => $category->name]);

        return ApiResponse::noContent();
    }

    protected function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;

        while (Category::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
