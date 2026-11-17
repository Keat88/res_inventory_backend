<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles:id,name', 'branches:id,name,code')
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term);
            }))
            ->when($request->filled('branch_id'), fn ($q) => $q->whereHas('branches', fn ($q) => $q->whereKey($request->integer('branch_id'))))
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return ApiResponse::paginated($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(RoleEnum::values())],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'primary_branch_id' => ['nullable', 'integer', 'in_array:branch_ids.*'],
            'status' => ['nullable', Rule::in(UserStatus::values())],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => $data['status'] ?? UserStatus::ACTIVE->value,
            ]);

            $user->syncRoles([$data['role']]);
            $this->syncBranches($user, $data['branch_ids'], $data['primary_branch_id'] ?? $data['branch_ids'][0]);

            return $user;
        });

        $this->audit->log('employee.created', 'user', $user->id, null, ['role' => $data['role']]);

        return ApiResponse::created($user->load('roles:id,name', 'branches:id,name,code'), 'Employee created successfully');
    }

    public function show(User $employee): JsonResponse
    {
        return ApiResponse::success($employee->load('roles:id,name', 'branches:id,name,code', 'shifts'));
    }

    public function update(Request $request, User $employee): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($employee->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in(RoleEnum::values())],
            'branch_ids' => ['sometimes', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'primary_branch_id' => ['nullable', 'integer'],
            'status' => ['sometimes', Rule::in(UserStatus::values())],
        ]);

        DB::transaction(function () use ($employee, $data) {
            $employee->fill(collect($data)->only(['name', 'email', 'phone', 'password', 'status'])->all())->save();

            if (isset($data['role'])) {
                $employee->syncRoles([$data['role']]);
            }

            if (isset($data['branch_ids'])) {
                $this->syncBranches($employee, $data['branch_ids'], $data['primary_branch_id'] ?? $data['branch_ids'][0]);
            }
        });

        $this->audit->log('employee.updated', 'user', $employee->id, null, collect($data)->only(['role', 'branch_ids', 'status'])->all());

        return ApiResponse::success($employee->refresh()->load('roles:id,name', 'branches:id,name,code'), 'Employee updated successfully');
    }

    public function destroy(Request $request, User $employee): JsonResponse
    {
        if ($request->user()->id === $employee->id) {
            throw new BusinessException('You cannot delete your own account.', 'CANNOT_DELETE_SELF', null, 409);
        }

        $employee->branches()->detach();
        $employee->tokens()->delete();
        $employee->delete();

        $this->audit->log('employee.deleted', 'user', $employee->id);

        return ApiResponse::noContent();
    }

    public function assignBranches(Request $request, User $employee): JsonResponse
    {
        $data = $request->validate([
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'primary_branch_id' => ['nullable', 'integer'],
        ]);

        $this->syncBranches($employee, $data['branch_ids'], $data['primary_branch_id'] ?? $data['branch_ids'][0]);

        return ApiResponse::success($employee->refresh()->load('branches:id,name,code'), 'Branches assigned successfully');
    }

    protected function syncBranches(User $user, array $branchIds, ?int $primaryBranchId): void
    {
        $payload = collect($branchIds)->mapWithKeys(fn ($branchId) => [
            (int) $branchId => [
                'is_primary' => (int) $branchId === (int) $primaryBranchId,
                'started_at' => now(),
            ],
        ])->all();

        $user->branches()->sync($payload);
    }
}
