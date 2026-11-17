<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()->withCount(['users', 'orders', 'tables']);

        if (! $request->user()->isAdmin()) {
            $query->whereIn('id', $request->user()->branches()->pluck('branches.id'));
        }

        return ApiResponse::success($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:60'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_charge_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            Branch::query()->where('is_default', true)->update(['is_default' => false]);
        }

        $branch = Branch::query()->create($data);

        $this->audit->log('branch.created', 'branch', $branch->id, null, ['code' => $branch->code], $branch->id);

        return ApiResponse::created($branch, 'Branch created successfully');
    }

    public function show(Branch $branch): JsonResponse
    {
        return ApiResponse::success($branch->loadCount(['users', 'orders', 'tables', 'inventories']));
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('branches', 'code')->ignore($branch->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:60'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_charge_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            Branch::query()->where('id', '!=', $branch->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $old = $branch->only(array_keys($data));
        $branch->update($data);

        $this->audit->log('branch.updated', 'branch', $branch->id, $old, $data, $branch->id);

        return ApiResponse::success($branch->refresh(), 'Branch updated successfully');
    }

    public function destroy(Branch $branch): JsonResponse
    {
        if ($branch->orders()->exists()) {
            return ApiResponse::error('Branches with orders cannot be deleted.', null, 409, 'BRANCH_HAS_ORDERS');
        }

        $branch->users()->detach();
        $branch->delete();

        $this->audit->log('branch.deleted', 'branch', $branch->id, ['code' => $branch->code], null, $branch->id);

        return ApiResponse::noContent();
    }
}
