<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Branch $branch, Request $request): JsonResponse
    {
        $query = BranchInventory::query()
            ->where('branch_id', $branch->id)
            ->with('ingredient:id,name,sku,unit,cost_per_unit,status')
            ->when($request->filled('search'), fn ($q) => $q->whereHas('ingredient', fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')))
            ->when($request->boolean('low'), fn ($q) => $q->whereColumn('quantity', '<=', 'min_stock'));

        return ApiResponse::paginated($query->orderBy('ingredient_id')->paginate($request->integer('per_page', 50)));
    }

    public function updateSettings(Request $request, Branch $branch, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        $inventory = BranchInventory::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'ingredient_id' => $ingredient->id],
            $data
        );

        return ApiResponse::success($inventory->load('ingredient'), 'Inventory settings updated');
    }

    public function adjust(Request $request, Branch $branch, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'quantity_change' => ['required', 'numeric', 'not_in:0'],
            'type' => ['nullable', Rule::in(StockMovementType::values())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->inventory->adjust(
            $branch->id,
            $ingredient->id,
            (float) $data['quantity_change'],
            StockMovementType::from($data['type'] ?? StockMovementType::ADJUSTMENT->value),
            $request->user()->id,
            $data['note'] ?? null
        );

        return ApiResponse::success($movement->load('ingredient'), 'Stock adjusted successfully');
    }

    public function movements(Branch $branch, Request $request): JsonResponse
    {
        $query = StockMovement::query()
            ->where('branch_id', $branch->id)
            ->with('ingredient:id,name,sku,unit', 'createdBy:id,name')
            ->when($request->filled('ingredient_id'), fn ($q) => $q->where('ingredient_id', $request->integer('ingredient_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->date('to')));

        return ApiResponse::paginated($query->latest('occurred_at')->paginate($request->integer('per_page', 50)));
    }

    public function lowStock(Branch $branch): JsonResponse
    {
        $items = BranchInventory::query()
            ->where('branch_id', $branch->id)
            ->whereColumn('quantity', '<=', 'min_stock')
            ->with('ingredient:id,name,sku,unit')
            ->get();

        return ApiResponse::success($items);
    }
}
