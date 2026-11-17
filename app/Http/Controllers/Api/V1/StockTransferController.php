<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(protected StockTransferService $transfers) {}

    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = StockTransfer::query()
            ->where(fn ($q) => $q->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))
            ->with('fromBranch:id,name,code', 'toBranch:id,name,code', 'requestedBy:id,name')
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('direction'), fn ($q) => $request->string('direction')->value() === 'out'
                ? $q->where('from_branch_id', $branch->id)
                : $q->where('to_branch_id', $branch->id));

        return ApiResponse::paginated($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'from_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:from_branch_id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.quantity_requested' => ['required', 'numeric', 'min:0.001'],
        ]);

        $data['from_branch_id'] = $data['from_branch_id'] ?? $branch->id;

        abort_unless($request->user()->canAccessBranch((int) $data['from_branch_id']), 403, 'You cannot transfer stock out of this branch.');

        $transfer = $this->transfers->create($request->user(), $data);

        return ApiResponse::created($transfer, 'Stock transfer created successfully');
    }

    public function show(StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorize('view', $stockTransfer);

        return ApiResponse::success($stockTransfer->load('fromBranch', 'toBranch', 'items.ingredient', 'requestedBy:id,name'));
    }

    public function approve(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorize('update', $stockTransfer);

        return ApiResponse::success($this->transfers->approve($stockTransfer, $request->user()), 'Transfer approved');
    }

    public function dispatch(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorize('dispatch', $stockTransfer);

        return ApiResponse::success($this->transfers->dispatch($stockTransfer, $request->user()), 'Transfer dispatched');
    }

    public function receive(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorize('receive', $stockTransfer);

        return ApiResponse::success($this->transfers->receive($stockTransfer, $request->user()), 'Transfer received');
    }

    public function cancel(StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorize('update', $stockTransfer);

        return ApiResponse::success($this->transfers->cancel($stockTransfer), 'Transfer cancelled');
    }

    public function destroy(StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorize('update', $stockTransfer);

        $this->transfers->cancel($stockTransfer);

        return ApiResponse::noContent('Transfer cancelled successfully');
    }
}
