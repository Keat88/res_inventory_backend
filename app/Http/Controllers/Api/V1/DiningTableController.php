<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\DiningTableService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiningTableController extends Controller
{
    public function __construct(protected DiningTableService $tables) {}

    public function index(Branch $branch, Request $request): JsonResponse
    {
        $tables = DiningTable::query()
            ->where('branch_id', $branch->id)
            ->with('currentOrder:id,order_number,status,grand_total')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('area'), fn ($q) => $q->where('area', $request->string('area')))
            ->orderBy('table_number')
            ->get();

        return ApiResponse::success($tables);
    }

    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'table_number' => ['required', 'string', 'max:30', Rule::unique('dining_tables', 'table_number')->where('branch_id', $branch->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'area' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(TableStatus::values())],
        ]);

        $table = $branch->tables()->create($data);

        return ApiResponse::created($table, 'Table created successfully');
    }

    public function show(DiningTable $table): JsonResponse
    {
        return ApiResponse::success($table->load('currentOrder.items', 'branch:id,name,code'));
    }

    public function update(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate([
            'table_number' => ['sometimes', 'string', 'max:30', Rule::unique('dining_tables', 'table_number')->where('branch_id', $table->branch_id)->ignore($table->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'area' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(TableStatus::values())],
        ]);

        $table->update($data);

        return ApiResponse::success($table->refresh(), 'Table updated successfully');
    }

    public function destroy(DiningTable $table): JsonResponse
    {
        if ($table->status === TableStatus::OCCUPIED) {
            return ApiResponse::error('Occupied tables cannot be deleted.', null, 409, 'TABLE_OCCUPIED');
        }

        $table->delete();

        return ApiResponse::noContent();
    }

    public function updateStatus(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(TableStatus::values())]]);

        $table->status = $data['status'];

        if ($table->status === TableStatus::AVAILABLE) {
            $table->current_order_id = null;
        }

        $table->save();

        return ApiResponse::success($table->refresh(), 'Table status updated successfully');
    }

    public function assign(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate(['order_id' => ['required', 'integer', 'exists:orders,id']]);
        $order = Order::query()->findOrFail($data['order_id']);

        $this->authorize('update', $order);

        return ApiResponse::success($this->tables->assign($table, $order, $request->user()), 'Table assigned successfully');
    }

    public function transfer(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate([
            'to_table_id' => ['required', 'integer', 'exists:dining_tables,id'],
        ]);

        $to = DiningTable::query()->findOrFail($data['to_table_id']);

        return ApiResponse::success($this->tables->transfer($table, $to, $request->user()), 'Table transferred successfully');
    }

    public function merge(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate([
            'secondary_table_id' => ['required', 'integer', 'exists:dining_tables,id'],
        ]);

        $secondary = DiningTable::query()->findOrFail($data['secondary_table_id']);

        return ApiResponse::success($this->tables->merge($table, $secondary, $request->user()), 'Tables merged successfully');
    }
}
