<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DiscountType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Discount;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = Discount::query()
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('code', 'like', $term);
            }))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        return ApiResponse::paginated($query->latest()->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:discounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(DiscountType::values())],
            'value' => ['required', 'numeric', 'min:0.01'],
            'min_order_total' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['branch_id'] = $request->integer('branch_id') ?: $branch->id;
        $data['created_by'] = $request->user()->id;

        $discount = Discount::query()->create($data);

        return ApiResponse::created($discount, 'Discount created successfully');
    }

    public function show(Discount $discount): JsonResponse
    {
        return ApiResponse::success($discount->load('branch:id,name,code', 'createdBy:id,name'));
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('discounts', 'code')->ignore($discount->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(DiscountType::values())],
            'value' => ['sometimes', 'numeric', 'min:0.01'],
            'min_order_total' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $discount->update($data);

        return ApiResponse::success($discount->refresh(), 'Discount updated successfully');
    }

    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return ApiResponse::noContent();
    }
}
