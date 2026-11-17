<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()
            ->withCount('purchaseOrders')
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return ApiResponse::paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $supplier = Supplier::query()->create($data);

        return ApiResponse::created($supplier, 'Supplier created successfully');
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success($supplier->load(['purchaseOrders' => fn ($q) => $q->latest()->limit(20)]));
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $supplier->update($data);

        return ApiResponse::success($supplier->refresh(), 'Supplier updated successfully');
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchaseOrders()->exists()) {
            return ApiResponse::error('Suppliers with purchase orders cannot be deleted.', null, 409, 'SUPPLIER_IN_USE');
        }

        $supplier->delete();

        return ApiResponse::noContent();
    }
}
