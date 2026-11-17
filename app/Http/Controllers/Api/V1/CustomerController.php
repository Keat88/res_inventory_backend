<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->when($request->filled('branch_id'), fn ($q) => $q->whereHas('orders', fn ($q) => $q->where('branch_id', $request->integer('branch_id'))));

        return ApiResponse::paginated($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:customers,phone'],
            'email' => ['nullable', 'email'],
            'birthday' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::query()->create($data);

        return ApiResponse::created($customer, 'Customer created successfully');
    }

    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success($customer->load(['orders' => fn ($q) => $q->latest()->limit(20)]));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'phone')->ignore($customer->id)],
            'email' => ['nullable', 'email'],
            'birthday' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer->update($data);

        return ApiResponse::success($customer->refresh(), 'Customer updated successfully');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return ApiResponse::noContent();
    }
}
